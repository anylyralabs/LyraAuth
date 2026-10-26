/**
 * OAuth2 端到端验证：授权码 + PKCE → 令牌 → userinfo → 刷新轮换 → 重放检测 → 吊销。
 *
 * 用法：
 *   CLIENT_ID=... CLIENT_SECRET=... node tmp/verify-oauth.mjs
 *   PUBLIC_CLIENT_ID=... node tmp/verify-oauth.mjs --public
 */

import { createHash, randomBytes } from 'node:crypto';

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';

const CLIENT_ID = process.env.CLIENT_ID || '';
const CLIENT_SECRET = process.env.CLIENT_SECRET || '';
const REDIRECT = process.env.REDIRECT_URI || 'http://localhost:9502/callback';
const EMAIL = process.env.OAUTH_EMAIL || 'admin@lyraauth.local';
const PASSWORD = process.env.OAUTH_PASSWORD || '';

if (!CLIENT_ID) throw new Error('请通过 CLIENT_ID 指定客户端');
if (!PASSWORD) throw new Error('请通过 OAUTH_PASSWORD 指定账号密码');

const connect = (wsUrl) =>
    new Promise((resolve, reject) => {
        const ws = new WebSocket(wsUrl);
        ws.onopen = () => resolve(ws);
        ws.onerror = () => reject(new Error('WebSocket 连接失败'));
    });

let seq = 0;
const send = (ws, method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++seq;
        const handler = (event) => {
            const data = JSON.parse(event.data);
            if (data.id !== id) return;
            ws.removeEventListener('message', handler);
            data.error ? reject(new Error(`${method}: ${data.error.message}`)) : resolve(data.result);
        };
        ws.addEventListener('message', handler);
        ws.send(JSON.stringify({ id, method, params }));
    });

const evaluate = async (ws, expression) => {
    const r = await send(ws, 'Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error('脚本异常：' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
};

const waitFor = async (ws, expr, timeout = 15000) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await evaluate(ws, expr)) return true;
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error('等待超时：' + expr);
};

const goto = async (ws, url, readyExpr) => {
    await send(ws, 'Page.navigate', { url });
    await new Promise((r) => setTimeout(r, 300));
    await waitFor(ws, `document.readyState === 'complete'`);
    if (readyExpr) await waitFor(ws, readyExpr);
};

const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    return ok ? 0 : 1;
};

const b64url = (buf) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

/** 调用令牌端点（表单提交，不走浏览器） */
const tokenRequest = async (params, { basic = null } = {}) => {
    const headers = { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' };
    if (basic) {
        headers.Authorization = 'Basic ' + Buffer.from(`${basic[0]}:${basic[1]}`).toString('base64');
    }

    const res = await fetch(`${BASE}/oauth/token`, {
        method: 'POST',
        headers,
        body: new URLSearchParams(params),
    });

    const text = await res.text();
    let json = null;
    try {
        json = JSON.parse(text);
    } catch {
        json = { raw: text.slice(0, 200) };
    }

    return { status: res.status, body: json, headers: res.headers };
};

const decodeJwt = (token) => {
    const [, payload] = token.split('.');
    const json = Buffer.from(payload.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
    return JSON.parse(json);
};

const main = async () => {
    let failures = 0;

    const res = await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' });
    const page = await res.json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.clearBrowserCookies');

    // ---- 1. 登录
    await goto(ws, `${BASE}/login`, `document.querySelector('input[name=identifier]') !== null`);
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=identifier]').value = ${JSON.stringify(EMAIL)};
            document.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
            document.querySelector('form[action="/login"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `location.pathname === '/dashboard' || location.pathname === '/mfa'`, 15000);

    // 若启用了 TOTP，此处无法自动完成（需密钥），提示并退出
    if (await evaluate(ws, `location.pathname === '/mfa'`)) {
        throw new Error('该账号启用了 TOTP，请改用未启用双因素的账号，或先关闭 TOTP');
    }
    failures += log(true, '登录成功');

    // ---- 2. 构造授权请求
    const verifier = b64url(randomBytes(32));
    const challenge = b64url(createHash('sha256').update(verifier).digest());
    const state = b64url(randomBytes(12));
    const nonce = b64url(randomBytes(12));

    const authorizeUrl =
        `${BASE}/oauth/authorize?response_type=code&client_id=${encodeURIComponent(CLIENT_ID)}` +
        `&redirect_uri=${encodeURIComponent(REDIRECT)}` +
        `&scope=${encodeURIComponent('openid profile email')}` +
        `&state=${state}&nonce=${nonce}` +
        `&code_challenge=${challenge}&code_challenge_method=S256`;

    // 拦截回跳：9502 演示客户端未启动，用 CDP 阻止真实导航并读取目标地址
    await send(ws, 'Fetch.enable', { patterns: [{ urlPattern: REDIRECT.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '*' }] });

    const redirectTargets = [];
    const fetchHandler = (event) => {
        const data = JSON.parse(event.data);
        if (data.method === 'Fetch.requestPaused') {
            redirectTargets.push(data.params.request.url);
            ws.send(JSON.stringify({ id: ++seq, method: 'Fetch.failRequest', params: { requestId: data.params.requestId, errorReason: 'Aborted' } }));
        }
    };
    ws.addEventListener('message', fetchHandler);

    await goto(ws, authorizeUrl, `document.body !== null`);
    await new Promise((r) => setTimeout(r, 1500));

    let currentUrl = redirectTargets.length > 0 ? redirectTargets[redirectTargets.length - 1] : await evaluate(ws, 'location.href');

    // 若停在同意页（第一方客户端通常不会），点击「允许」
    if (!currentUrl.startsWith(REDIRECT)) {
        const clicked = await evaluate(
            ws,
            `(() => {
                const input = document.querySelector('form[action="/oauth/authorize"] input[value=allow]');
                if (!input) return false;
                input.closest('form').requestSubmit();
                return true;
            })()`
        );
        if (!clicked) {
            throw new Error('同意页未找到「允许」按钮，当前地址：' + currentUrl);
        }
        await new Promise((r) => setTimeout(r, 1500));
        currentUrl = redirectTargets.length > 0 ? redirectTargets[redirectTargets.length - 1] : await evaluate(ws, 'location.href');
    }

    ws.removeEventListener('message', fetchHandler);
    await send(ws, 'Fetch.disable').catch(() => {});

    failures += log(true, '授权请求已完成', currentUrl.slice(0, 80));

    const redirectUrl = new URL(currentUrl);
    const code = redirectUrl.searchParams.get('code');
    const returnedState = redirectUrl.searchParams.get('state');
    failures += log(!!code, '获得授权码', code ? code.slice(0, 12) + '…' : null);
    failures += log(returnedState === state, 'state 校验通过');

    if (!code) throw new Error('未获得授权码，当前地址：' + currentUrl);

    // ---- 3. 交换令牌
    const tokenParams = {
        grant_type: 'authorization_code',
        code,
        redirect_uri: REDIRECT,
        client_id: CLIENT_ID,
        code_verifier: verifier,
    };

    const tokenRes = await tokenRequest(tokenParams, CLIENT_SECRET ? { basic: [CLIENT_ID, CLIENT_SECRET] } : {});
    failures += log(tokenRes.status === 200, '令牌交换成功', tokenRes.status);

    if (tokenRes.status !== 200) {
        console.log(JSON.stringify(tokenRes.body));
        throw new Error('令牌交换失败');
    }

    const accessToken = tokenRes.body.access_token;
    const refreshToken = tokenRes.body.refresh_token;
    failures += log(!!accessToken, '获得访问令牌');
    failures += log(!!refreshToken, '获得刷新令牌');
    failures += log(tokenRes.body.token_type === 'Bearer', 'token_type 为 Bearer');

    const claims = decodeJwt(accessToken);
    const discoveryDoc = await (await fetch(`${BASE}/.well-known/openid-configuration`)).json();
    const expectedIssuer = discoveryDoc.issuer;
    failures += log(claims.iss === expectedIssuer, 'JWT iss 与 issuer 一致', claims.iss);
    failures += log(!!claims.sid, 'JWT 含 sid（会话绑定）');
    failures += log(Array.isArray(claims.amr) && claims.amr.includes('pwd'), 'JWT 含 amr', claims.amr);
    failures += log(typeof claims.auth_time === 'number', 'JWT 含 auth_time');
    failures += log(claims.nonce === undefined || claims.nonce === nonce, 'nonce 已透传', claims.nonce);

    // ---- 4. userinfo
    const userinfoRes = await fetch(`${BASE}/userinfo`, {
        headers: { Authorization: `Bearer ${accessToken}` },
    });
    const userinfo = await userinfoRes.json();
    failures += log(userinfoRes.status === 200 && !!userinfo.sub, 'userinfo 返回 sub', userinfo.sub?.slice(0, 8));
    failures += log(userinfo.email === EMAIL, 'userinfo 返回邮箱', userinfo.email);
    failures += log(userinfo.email_verified === true, 'email_verified 为 true');

    // ---- 5. 刷新令牌轮换
    const refreshRes = await tokenRequest(
        { grant_type: 'refresh_token', refresh_token: refreshToken, client_id: CLIENT_ID },
        CLIENT_SECRET ? { basic: [CLIENT_ID, CLIENT_SECRET] } : {}
    );
    failures += log(refreshRes.status === 200, '刷新令牌成功', refreshRes.status);
    const newRefresh = refreshRes.body.refresh_token;
    failures += log(!!newRefresh && newRefresh !== refreshToken, '刷新令牌已轮换');

    // ---- 6. 旧令牌重放应失败
    const replayRes = await tokenRequest(
        { grant_type: 'refresh_token', refresh_token: refreshToken, client_id: CLIENT_ID },
        CLIENT_SECRET ? { basic: [CLIENT_ID, CLIENT_SECRET] } : {}
    );
    failures += log(replayRes.status >= 400, '旧刷新令牌被拒绝', { status: replayRes.status, error: replayRes.body.error });

    // ---- 7. 授权码重用应失败
    const reuseRes = await tokenRequest(tokenParams, CLIENT_SECRET ? { basic: [CLIENT_ID, CLIENT_SECRET] } : {});
    failures += log(reuseRes.status >= 400, '授权码不可重用', { status: reuseRes.status, error: reuseRes.body.error });

    // ---- 8. 错误 PKCE
    const code2Url = await (async () => {
        const c2 = b64url(createHash('sha256').update(verifier).digest());
        await goto(
            ws,
            `${BASE}/oauth/authorize?response_type=code&client_id=${encodeURIComponent(CLIENT_ID)}&redirect_uri=${encodeURIComponent(REDIRECT)}&scope=openid&state=s2&code_challenge=${c2}&code_challenge_method=S256`,
            `document.body !== null`
        );
        await new Promise((r) => setTimeout(r, 1000));
        return evaluate(ws, 'location.href');
    })();
    const code2 = new URL(code2Url).searchParams.get('code');
    if (code2) {
        const badPkce = await tokenRequest(
            { grant_type: 'authorization_code', code: code2, redirect_uri: REDIRECT, client_id: CLIENT_ID, code_verifier: b64url(randomBytes(32)) },
            CLIENT_SECRET ? { basic: [CLIENT_ID, CLIENT_SECRET] } : {}
        );
        failures += log(badPkce.status >= 400, '错误 PKCE 被拒绝', { status: badPkce.status, error: badPkce.body.error });
    }

    // ---- 9. 自省与吊销
    if (CLIENT_SECRET) {
        const introspect = await fetch(`${BASE}/oauth/introspect`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                Authorization: 'Basic ' + Buffer.from(`${CLIENT_ID}:${CLIENT_SECRET}`).toString('base64'),
            },
            body: new URLSearchParams({ token: refreshRes.body.access_token }),
        });
        const introspection = await introspect.json();
        failures += log(introspection.active === true, '自省返回 active', introspection.active);

        const revoke = await fetch(`${BASE}/oauth/revoke`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                Authorization: 'Basic ' + Buffer.from(`${CLIENT_ID}:${CLIENT_SECRET}`).toString('base64'),
            },
            body: new URLSearchParams({ token: refreshRes.body.access_token, token_type_hint: 'access_token' }),
        });
        failures += log(revoke.status === 200, '吊销返回 200', revoke.status);

        const afterRevoke = await fetch(`${BASE}/oauth/introspect`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                Authorization: 'Basic ' + Buffer.from(`${CLIENT_ID}:${CLIENT_SECRET}`).toString('base64'),
            },
            body: new URLSearchParams({ token: refreshRes.body.access_token }),
        });
        const revokedInfo = await afterRevoke.json();
        failures += log(revokedInfo.active === false, '吊销后自省为 inactive');
    }

    // ---- 10. 未授权访问 userinfo
    const noToken = await fetch(`${BASE}/userinfo`);
    failures += log(noToken.status === 401, '无令牌访问 userinfo 返回 401', noToken.status);

    // ---- 11. 发现文档字段
    const discovery = discoveryDoc;
    failures += log(discovery.issuer.startsWith('http'), 'discovery issuer 为绝对地址', discovery.issuer);
    failures += log(discovery.code_challenge_methods_supported?.includes('S256'), '支持 S256 PKCE');
    failures += log(discovery.device_authorization_endpoint?.endsWith('/oauth/device_authorization'), '包含设备授权端点');

    const jwks = await (await fetch(`${BASE}/.well-known/jwks.json`)).json();
    failures += log(Array.isArray(jwks.keys) && jwks.keys.length > 0, 'JWKS 至少一把公钥', jwks.keys?.length);
    failures += log(jwks.keys?.[0]?.kty === 'RSA', '公钥类型为 RSA');

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\nOAuth2 全流程验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
