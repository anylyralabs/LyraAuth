/**
 * OIDC 验证：id_token 签发、签名校验、claims 完整性、at_hash。
 *
 * 用 JWKS 里的公钥真实验签（Node 内置 crypto），不使用第三方库。
 */

import { createHash, createPublicKey, randomBytes, verify } from 'node:crypto';

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CLIENT_ID = process.env.CLIENT_ID || '';
const REDIRECT = 'http://localhost:9502/callback';

if (!CLIENT_ID) throw new Error('请通过 CLIENT_ID 指定客户端');

const b64url = (buf) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

const decodeSegment = (value) => {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    return Buffer.from(normalized + '='.repeat((4 - (normalized.length % 4)) % 4), 'base64');
};

/** 把 JWK 转成 PEM 公钥 */
const jwkToPem = (jwk) => {
    const publicKey = createPublicKey({ key: jwk, format: 'jwk' });
    return publicKey.export({ type: 'spki', format: 'pem' });
};

const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    return ok ? 0 : 1;
};

const main = async () => {
    let failures = 0;

    // 引导登录：先取得 code（这里直接用授权码流程的简化版，复用 admin 账号的会话不可行，
    // 因此通过 Node 侧模拟完整表单流程）
    const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
    const EMAIL = process.env.OAUTH_EMAIL || 'oauthor@example.com';
    const PASSWORD = process.env.OAUTH_PASSWORD || '';
    if (!PASSWORD) throw new Error('请通过 OAUTH_PASSWORD 提供账号密码');

    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await new Promise((resolve, reject) => {
        const socket = new WebSocket(page.webSocketDebuggerUrl);
        socket.onopen = () => resolve(socket);
        socket.onerror = () => reject(new Error('WebSocket 连接失败'));
    });

    let seq = 0;
    const send = (method, params = {}) =>
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

    const evaluate = async (expression) => {
        const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (r.exceptionDetails) throw new Error('脚本异常：' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
        return r.result.value;
    };

    const waitFor = async (expr, timeout = 15000) => {
        const end = Date.now() + timeout;
        while (Date.now() < end) {
            if (await evaluate(expr)) return true;
            await new Promise((r) => setTimeout(r, 150));
        }
        throw new Error('等待超时：' + expr);
    };

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.clearBrowserCookies');
    await send('Page.navigate', { url: `${BASE}/login` });
    await new Promise((r) => setTimeout(r, 1200));
    await evaluate(
        `(() => {
            document.querySelector('[name=identifier]').value = ${JSON.stringify(EMAIL)};
            document.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
            document.querySelector('form[action="/login"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(`location.pathname === '/dashboard' || location.pathname === '/mfa'`);

    const verifier = b64url(randomBytes(32));
    const challenge = b64url(createHash('sha256').update(verifier).digest());
    const nonce = b64url(randomBytes(16));
    const state = b64url(randomBytes(8));

    const authorizeUrl =
        `${BASE}/oauth/authorize?response_type=code&client_id=${encodeURIComponent(CLIENT_ID)}` +
        `&redirect_uri=${encodeURIComponent(REDIRECT)}&scope=${encodeURIComponent('openid profile email')}` +
        `&state=${state}&nonce=${nonce}&code_challenge=${challenge}&code_challenge_method=S256`;

    await send('Fetch.enable', { patterns: [{ urlPattern: REDIRECT + '*' }] });
    const redirects = [];
    const fetchHandler = (event) => {
        const data = JSON.parse(event.data);
        if (data.method === 'Fetch.requestPaused') {
            redirects.push(data.params.request.url);
            ws.send(JSON.stringify({ id: ++seq, method: 'Fetch.failRequest', params: { requestId: data.params.requestId, errorReason: 'Aborted' } }));
        }
    };
    ws.addEventListener('message', fetchHandler);

    await send('Page.navigate', { url: authorizeUrl });
    await new Promise((r) => setTimeout(r, 3000));

    ws.removeEventListener('message', fetchHandler);
    await send('Fetch.disable').catch(() => {});

    const target = redirects[redirects.length - 1] || '';
    const code = new URL(target || 'http://x/').searchParams.get('code');
    failures += log(!!code, '取得授权码');
    if (!code) throw new Error('未取得授权码，目标：' + target);

    // 交换令牌
    const tokenRes = await fetch(`${BASE}/oauth/token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            grant_type: 'authorization_code',
            code,
            redirect_uri: REDIRECT,
            client_id: CLIENT_ID,
            code_verifier: verifier,
        }),
    });
    const tokens = await tokenRes.json();
    failures += log(tokenRes.status === 200, '令牌交换成功', tokenRes.status);
    failures += log(!!tokens.id_token, '响应包含 id_token');

    if (!tokens.id_token) {
        console.log(JSON.stringify(tokens));
        throw new Error('缺少 id_token');
    }

    const [headerB64, payloadB64, signatureB64] = tokens.id_token.split('.');
    const header = JSON.parse(decodeSegment(headerB64).toString('utf8'));
    const claims = JSON.parse(decodeSegment(payloadB64).toString('utf8'));

    failures += log(header.alg === 'RS256', 'id_token 使用 RS256', header.alg);
    failures += log(typeof header.kid === 'string' && header.kid !== '', 'id_token 头含 kid', header.kid);

    // 用 JWKS 验签
    const jwks = await (await fetch(`${BASE}/.well-known/jwks.json`)).json();
    const jwk = jwks.keys.find((k) => k.kid === header.kid);
    failures += log(!!jwk, 'JWKS 中存在对应 kid 的公钥');

    if (jwk) {
        const pem = jwkToPem(jwk);
        const isValid = verify(
            'RSA-SHA256',
            Buffer.from(`${headerB64}.${payloadB64}`),
            pem,
            decodeSegment(signatureB64)
        );
        failures += log(isValid, 'id_token 签名校验通过');
    }

    const discovery = await (await fetch(`${BASE}/.well-known/openid-configuration`)).json();

    failures += log(claims.iss === discovery.issuer, 'iss 与发现文档一致', claims.iss);
    failures += log(claims.aud === CLIENT_ID, 'aud 为 client_id');
    failures += log(claims.azp === CLIENT_ID, 'azp 为 client_id');
    failures += log(!!claims.sub && claims.sub.includes('-'), 'sub 为 UUID', claims.sub);
    failures += log(claims.nonce === nonce, 'nonce 原样回传');
    failures += log(typeof claims.auth_time === 'number', 'auth_time 存在', claims.auth_time);
    failures += log(Array.isArray(claims.amr) && claims.amr.length > 0, 'amr 存在', claims.amr);
    failures += log(typeof claims.sid === 'string' && claims.sid.length > 20, 'sid 存在');
    failures += log(claims.exp > claims.iat, 'exp 晚于 iat');

    // at_hash 校验：SHA-256 左半 + base64url
    const expectedAtHash = b64url(createHash('sha256').update(tokens.access_token).digest().subarray(0, 16));
    failures += log(claims.at_hash === expectedAtHash, 'at_hash 与访问令牌匹配');

    // userinfo 的 sub 必须与 id_token 的 sub 一致
    const userinfo = await (await fetch(`${BASE}/userinfo`, {
        headers: { Authorization: `Bearer ${tokens.access_token}` },
    })).json();
    failures += log(userinfo.sub === claims.sub, 'userinfo.sub 与 id_token.sub 一致');

    ws.close();
    await fetch(`${CDP}/json/close/${page.id}`).catch(() => {});

    console.log(failures === 0 ? '\nOIDC 验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
