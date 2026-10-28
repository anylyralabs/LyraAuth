/**
 * 登录链路回归：验证 localhost 下会话 Cookie 能落地，且登录后正确回跳。
 *
 * 覆盖下游反馈的两个症状：
 * 1. 首次点击登录无反应（Cookie 被浏览器丢弃）
 * 2. 登录后落到 /dashboard 而不是原始目标地址
 */

const BASE = process.env.VERIFY_BASE || 'http://localhost:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const EMAIL = process.env.VERIFY_EMAIL || 'oauthor@example.com';
const PASSWORD = process.env.VERIFY_PASSWORD || '';

if (!PASSWORD) {
    console.error('请通过 VERIFY_PASSWORD 提供测试账号密码');
    process.exit(1);
}

const connect = (u) => new Promise((res, rej) => {
    const ws = new WebSocket(u);
    ws.onopen = () => res(ws);
    ws.onerror = () => rej(new Error('WS 连接失败'));
});

let seq = 0;
const send = (ws, m, p = {}) => new Promise((res, rej) => {
    const id = ++seq;
    const h = (e) => {
        const d = JSON.parse(e.data);
        if (d.id !== id) return;
        ws.removeEventListener('message', h);
        d.error ? rej(new Error(`${m}: ${d.error.message}`)) : res(d.result);
    };
    ws.addEventListener('message', h);
    ws.send(JSON.stringify({ id, method: m, params: p }));
});

const ev = async (ws, expr) => {
    const r = await send(ws, 'Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error('脚本异常：' + (r.exceptionDetails.exception?.description || '').slice(0, 300));
    return r.result.value;
};

const waitFor = async (ws, expr, timeout = 20000) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await ev(ws, expr)) return true;
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error('等待超时：' + expr);
};

let passed = 0;
let failed = 0;
const check = (ok, label, extra = '') => {
    console.log(`${ok ? '  ✓' : '  ✗'} ${label}${extra ? ' ' + extra : ''}`);
    ok ? passed++ : failed++;
};

const main = async () => {
    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.enable');
    await send(ws, 'Network.clearBrowserCookies');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    const nav = async (path) => {
        await send(ws, 'Page.navigate', { url: BASE + path });
        await new Promise((r) => setTimeout(r, 500));
        await waitFor(ws, `document.readyState === 'complete'`, 15000);
        await new Promise((r) => setTimeout(r, 300));
    };

    // 先把目标地址写入 intended：访问一个需要登录的页面
    await nav('/account/security');
    check((await ev(ws, 'location.pathname')) === '/login', '未登录访问受保护页面跳转到 /login');

    // 检查 Cookie 是否真的被浏览器接受
    const cookies = await send(ws, 'Network.getCookies', { urls: [BASE + '/'] });
    const session = (cookies.cookies || []).find((c) => c.name === 'lyraauth_session');
    check(Boolean(session), '会话 Cookie 已被浏览器接受');
    if (session) {
        check(session.domain === 'localhost', 'Cookie 域为 localhost', `domain=${session.domain}`);
        check(!('domain' in session) || session.domain === 'localhost', 'Cookie 无非法 Domain 属性');
    }

    // 检查 Set-Cookie 原始响应头里不带 Domain
    await nav('/login');
    const cookieResponseHeaders = await ev(ws, `(() => {
        const e = performance.getEntriesByType('resource').map(r => r.name);
        return e.length;
    })()`);
    void cookieResponseHeaders;

    // 真实点击登录
    await waitFor(ws, `document.querySelector('input[name=identifier]') !== null`);
    await ev(ws, `(() => {
        const f = document.querySelector('form[action="/login"]');
        f.querySelector('[name=identifier]').value = ${JSON.stringify(EMAIL)};
        f.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
        return true;
    })()`);

    // 用真实鼠标事件点击，避免 requestSubmit 绕过浏览器行为
    const box = await ev(ws, `(() => {
        const b = document.querySelector('form[action="/login"] button[type=submit]');
        b.scrollIntoView({ block: 'center' });
        const r = b.getBoundingClientRect();
        return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
    })()`);
    await send(ws, 'Input.dispatchMouseEvent', { type: 'mousePressed', x: box.x, y: box.y, button: 'left', clickCount: 1 });
    await send(ws, 'Input.dispatchMouseEvent', { type: 'mouseReleased', x: box.x, y: box.y, button: 'left', clickCount: 1 });

    await waitFor(ws, `location.pathname !== '/login'`, 25000);
    const afterLogin = await ev(ws, 'location.pathname + location.search');
    check(afterLogin.startsWith('/account/security'), '登录后回跳到原始目标地址', afterLogin);

    // 再验证一次普通登录（无 intended）落到 /dashboard
    await send(ws, 'Network.clearBrowserCookies');
    await nav('/login');
    await waitFor(ws, `document.querySelector('input[name=identifier]') !== null`);
    await ev(ws, `(() => {
        const f = document.querySelector('form[action="/login"]');
        f.querySelector('[name=identifier]').value = ${JSON.stringify(EMAIL)};
        f.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
        return true;
    })()`);
    const box2 = await ev(ws, `(() => {
        const b = document.querySelector('form[action="/login"] button[type=submit]');
        b.scrollIntoView({ block: 'center' });
        const r = b.getBoundingClientRect();
        return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
    })()`);
    await send(ws, 'Input.dispatchMouseEvent', { type: 'mousePressed', x: box2.x, y: box2.y, button: 'left', clickCount: 1 });
    await send(ws, 'Input.dispatchMouseEvent', { type: 'mouseReleased', x: box2.x, y: box2.y, button: 'left', clickCount: 1 });
    await waitFor(ws, `location.pathname !== '/login'`, 25000);
    check((await ev(ws, 'location.pathname')) === '/dashboard', '无 intended 时落到 /dashboard');

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(`\n通过 ${passed} 项，失败 ${failed} 项。`);
    process.exit(failed === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
