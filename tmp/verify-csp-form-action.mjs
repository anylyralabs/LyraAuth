/**
 * 验证 CSP form-action 修复：完整走一遍 授权 → 登录 → 同意/静默 → 回跳 RP。
 *
 * 判据：控制台无 CSP form-action 违规，且最终落到 RP 的回调地址。
 * 关键：起点必须是 RP（跨源），直接开 IdP 登录页测不出这个问题。
 */

const RP = process.env.RP_BASE || 'http://localhost:9502';
const IDP = process.env.IDP_BASE || 'http://localhost:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const EMAIL = process.env.VERIFY_EMAIL || 'oauthor@example.com';
const PASSWORD = process.env.VERIFY_PASSWORD || '';

let passed = 0;
let failed = 0;
const check = (ok, label) => {
    console.log(`${ok ? '  ✓' : '  ✗'} ${label}`);
    ok ? passed++ : failed++;
};

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

const waitFor = async (ws, expr, timeout = 25000) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await ev(ws, expr)) return true;
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error('等待超时：' + expr);
};

const main = async () => {
    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.enable');
    await send(ws, 'Log.enable');
    await send(ws, 'Network.clearBrowserCookies');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    const violations = [];
    ws.addEventListener('message', (e) => {
        const d = JSON.parse(e.data);
        if (d.method === 'Log.entryAdded') {
            const t = d.params.entry.text || '';
            if (/Content Security Policy|form-action|violates/i.test(t)) violations.push(t);
        }
        if (d.method === 'Runtime.exceptionThrown') {
            const t = d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text || '';
            if (/CSP|form-action/i.test(t)) violations.push(t);
        }
    });

    console.log('第 1 步：从 RP 发起登录（跨源起点，这是关键）');
    await send(ws, 'Page.navigate', { url: RP + '/login' });
    await waitFor(ws, `location.port === '9501' && document.readyState === 'complete'`, 25000);
    const atLogin = await ev(ws, 'location.href');
    check(atLogin.includes('9501'), '跳转到 IdP 登录页');

    console.log('\n第 2 步：检查登录页 CSP');
    const csp = await ev(ws, `(() => {
        const m = performance.getEntriesByType('navigation');
        return null;
    })()`);
    void csp;
    console.log('  控制台 CSP 违规数（此时应为 0）:', violations.length);

    console.log('\n第 3 步：填表并真实点击');
    await waitFor(ws, `document.querySelector('input[name=identifier]') !== null`, 15000);
    await ev(ws, `(() => {
        const f = document.querySelector('form[action="/login"]');
        f.querySelector('[name=identifier]').value = ${JSON.stringify(EMAIL)};
        f.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
        return true;
    })()`);
    const box = await ev(ws, `(() => {
        const b = document.querySelector('form[action="/login"] button[type=submit]');
        b.scrollIntoView({ block: 'center' });
        const r = b.getBoundingClientRect();
        return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
    })()`);
    await send(ws, 'Input.dispatchMouseEvent', { type: 'mousePressed', x: box.x, y: box.y, button: 'left', clickCount: 1 });
    await send(ws, 'Input.dispatchMouseEvent', { type: 'mouseReleased', x: box.x, y: box.y, button: 'left', clickCount: 1 });

    await new Promise((r) => setTimeout(r, 6000));
    const afterLogin = await ev(ws, 'location.href');
    console.log('  点击后:', afterLogin.slice(0, 130));

    check(!afterLogin.includes('9501/login'), '登录后离开登录页');
    check(!violations.some((v) => /form-action/i.test(v)), '无 form-action CSP 违规');

    // 若停在同意页，点允许
    if (afterLogin.includes('/oauth/authorize')) {
        console.log('\n第 4 步：同意页提交');
        const hasAllow = await ev(ws, `document.querySelector('input[name=decision][value=allow]') !== null`);
        if (hasAllow) {
            const btn = await ev(ws, `(() => {
                const b = [...document.querySelectorAll('button[type=submit]')].find(x => /允许/.test(x.textContent));
                if (!b) return null;
                b.scrollIntoView({ block: 'center' });
                const r = b.getBoundingClientRect();
                return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
            })()`);
            if (btn) {
                await send(ws, 'Input.dispatchMouseEvent', { type: 'mousePressed', x: btn.x, y: btn.y, button: 'left', clickCount: 1 });
                await send(ws, 'Input.dispatchMouseEvent', { type: 'mouseReleased', x: btn.x, y: btn.y, button: 'left', clickCount: 1 });
                await new Promise((r) => setTimeout(r, 5000));
            }
        }
    }

    const final = await ev(ws, 'location.href');
    console.log('\n最终地址:', final.slice(0, 140));
    check(final.includes('9502'), '最终回跳到 RP（跨源跳转成功）');
    check(!violations.some((v) => /form-action/i.test(v)), '全程无 form-action 违规');

    if (violations.length) {
        console.log('\n捕获到的 CSP 违规:');
        violations.slice(0, 5).forEach((v) => console.log('  ' + v.slice(0, 250)));
    }

    await send(ws, 'Page.close').catch(() => {});
    ws.close();
    console.log(`\n通过 ${passed} 项，失败 ${failed} 项。`);
    process.exit(failed === 0 ? 0 : 1);
};

main().catch((e) => { console.error('中断：', e.message); process.exit(1); });
