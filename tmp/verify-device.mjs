/**
 * 设备码（RFC 8628）与单点登出验证。
 *
 * 流程：device_authorization → 用户在 /device 批准 → 轮询 token → userinfo
 *        → /oauth/logout 结束会话 → 令牌应失效
 */

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';

const CLIENT_ID = process.env.CLIENT_ID || '';
const CLIENT_SECRET = process.env.CLIENT_SECRET || '';
const EMAIL = process.env.OAUTH_EMAIL || 'oauthor@example.com';
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

const goto = async (ws, url) => {
    await send(ws, 'Page.navigate', { url });
    await new Promise((r) => setTimeout(r, 300));
    await waitFor(ws, `document.readyState === 'complete'`);
};

const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    return ok ? 0 : 1;
};

const main = async () => {
    let failures = 0;

    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.clearBrowserCookies');

    const basic = CLIENT_SECRET
        ? 'Basic ' + Buffer.from(`${CLIENT_ID}:${CLIENT_SECRET}`).toString('base64')
        : null;
    const authHeaders = {
        'Content-Type': 'application/x-www-form-urlencoded',
        ...(basic ? { Authorization: basic } : {}),
    };

    // ---- 1. 请求设备码
    const deviceRes = await fetch(`${BASE}/oauth/device_authorization`, {
        method: 'POST',
        headers: authHeaders,
        body: new URLSearchParams({ client_id: CLIENT_ID, scope: 'openid profile email' }),
    });
    const device = await deviceRes.json();
    failures += log(deviceRes.status === 200, '设备授权端点返回 200', deviceRes.status);
    if (!device.device_code) {
        console.log(JSON.stringify(device));
        throw new Error('未获得 device_code');
    }
    failures += log(!!device.user_code, '返回 user_code', device.user_code);
    failures += log(device.verification_uri?.includes('/device'), '返回 verification_uri', device.verification_uri);
    failures += log(typeof device.expires_in === 'number', '返回 expires_in', device.expires_in);
    const deviceCode = device.device_code;
    const userCode = device.user_code;

    // ---- 2. 未批准时轮询应返回 authorization_pending
    const pendingRes = await fetch(`${BASE}/oauth/token`, {
        method: 'POST',
        headers: authHeaders,
        body: new URLSearchParams({
            grant_type: 'urn:ietf:params:oauth:grant-type:device_code',
            device_code: deviceCode,
            client_id: CLIENT_ID,
        }),
    });
    const pending = await pendingRes.json();
    failures += log(pending.error === 'authorization_pending', '未批准时返回 authorization_pending', pending.error);

    // ---- 3. 登录并批准
    await goto(ws, `${BASE}/login`);
    await waitFor(ws, `document.querySelector('input[name=identifier]') !== null`);
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
    if (await evaluate(ws, `location.pathname === '/mfa'`)) throw new Error('测试账号不应启用 TOTP');

    await goto(ws, `${BASE}/device?user_code=${encodeURIComponent(userCode)}`);
    await waitFor(ws, `document.querySelector('form[action="/device/decision"]') !== null`, 15000);
    const confirmText = await evaluate(ws, `document.body.innerText`);
    failures += log(confirmText.includes('请求在设备上登录'), '设备确认页展示应用信息');

    await evaluate(ws, `document.querySelector('form[action="/device/decision"] input[value=allow]').closest('form').requestSubmit()`);
    await waitFor(ws, `document.body.innerText.includes('设备已获授权')`, 15000);
    failures += log(true, '用户批准设备码');

    // ---- 4. 再次轮询应拿到令牌
    let tokenBody = null;
    for (let i = 0; i < 5; i += 1) {
        const res = await fetch(`${BASE}/oauth/token`, {
            method: 'POST',
            headers: authHeaders,
            body: new URLSearchParams({
                grant_type: 'urn:ietf:params:oauth:grant-type:device_code',
                device_code: deviceCode,
                client_id: CLIENT_ID,
            }),
        });
        tokenBody = await res.json();
        if (tokenBody.access_token) break;
        await new Promise((r) => setTimeout(r, 1200));
    }

    failures += log(!!tokenBody?.access_token, '批准后获得访问令牌');
    if (!tokenBody?.access_token) {
        console.log(JSON.stringify(tokenBody));
        throw new Error('设备码兑换失败');
    }

    const accessToken = tokenBody.access_token;

    // ---- 5. userinfo
    const userinfo = await (await fetch(`${BASE}/userinfo`, {
        headers: { Authorization: `Bearer ${accessToken}` },
    })).json();
    failures += log(!!userinfo.sub, '设备码令牌可访问 userinfo', userinfo.email);

    // ---- 6. 登出回跳与令牌吊销
    const logoutUrl =
        `${BASE}/oauth/logout?client_id=${encodeURIComponent(CLIENT_ID)}` +
        `&post_logout_redirect_uri=${encodeURIComponent('http://localhost:9502/')}&state=xyz`;

    // 用浏览器真实会话 POST 登出（同时吊销该会话签发的令牌）
    await goto(ws, logoutUrl);
    await waitFor(ws, `document.querySelector('form[action="/oauth/logout"]') !== null`, 15000);
    const formAction = await evaluate(ws, `document.querySelector('form[action="/oauth/logout"]').getAttribute('action')`);

    // 直接读服务端响应头：在同源内用 fetch 且 redirect:manual 时 Chrome 会把 302 变成 opaque，
    // 因此这里改为提交表单，随后用 Node 侧独立的 GET 断言回跳规则（不依赖登录态）。
    await evaluate(ws, `document.querySelector('form[action="/oauth/logout"]').submit()`);
    await new Promise((r) => setTimeout(r, 2500));
    failures += log(formAction === '/oauth/logout', '登出表单指向正确端点');

    const logoutGet = await fetch(logoutUrl, { redirect: 'manual' });
    const target = logoutGet.headers.get('location') || '';
    failures += log(logoutGet.status === 302, '登出接口返回 302', logoutGet.status);
    failures += log(target.startsWith('http://localhost:9502/'), '登出后回跳已注册地址', target.slice(0, 60));
    failures += log(target.includes('state=xyz'), '回跳保留 state');

    // ---- 7. 登出后令牌应失效
    const afterLogout = await fetch(`${BASE}/userinfo`, {
        headers: { Authorization: `Bearer ${accessToken}` },
    });
    failures += log(afterLogout.status === 401, '登出后访问令牌失效', afterLogout.status);

    // ---- 8. 未注册的回跳地址应被忽略
    await goto(ws, `${BASE}/login`);
    await fetch(`${BASE}/oauth/logout?client_id=${encodeURIComponent(CLIENT_ID)}&post_logout_redirect_uri=${encodeURIComponent('https://evil.example.com/')}`, { redirect: 'manual' });
    const openRedirect = await fetch(
        `${BASE}/oauth/logout?client_id=${encodeURIComponent(CLIENT_ID)}&post_logout_redirect_uri=${encodeURIComponent('https://evil.example.com/')}`,
        { redirect: 'manual' }
    );
    const location = openRedirect.headers.get('location') || '';
    failures += log(!location.includes('evil.example.com'), '拒绝未注册的回跳地址', location || '(无 Location)');

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\n设备码与单点登出验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
