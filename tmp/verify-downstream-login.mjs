/**
 * 下游真实链路终验：8788 → 9601 授权 → 登录 → 回跳 8788 回调 → 会话建立。
 *
 * 判定标准：最终落在 8788 的页面，且 /v1/auth/session 返回登录用户。
 */

const RP = process.env.RP_BASE || 'http://localhost:8788';
const IDP = process.env.IDP_BASE || 'http://localhost:9601';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const EMAIL = process.env.VERIFY_EMAIL || 'oauthor@example.com';
const PASSWORD = process.env.VERIFY_PASSWORD || '';

let passed = 0;
let failed = 0;
const check = (ok, label, extra = '') => {
    console.log(`${ok ? '  ✓' : '  ✗'} ${label}${extra ? '  ' + extra : ''}`);
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

    const cspViolations = [];
    ws.addEventListener('message', (e) => {
        const d = JSON.parse(e.data);
        if (d.method === 'Log.entryAdded') {
            const t = d.params.entry.text || '';
            if (/Content Security Policy|form-action/i.test(t)) cspViolations.push(t);
        }
    });

    console.log('第 1 步：从下游 RP 发起登录');
    await send(ws, 'Page.navigate', { url: RP + '/v1/auth/login' });
    await waitFor(ws, `location.port === '9601' && document.readyState === 'complete'`, 25000);
    check((await ev(ws, 'location.href')).includes('9601/login'), '跳转到 IdP 登录页');

    console.log('\n第 2 步：填表并真实点击（此前会卡在这一步）');
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

    await new Promise((r) => setTimeout(r, 7000));
    const after = await ev(ws, 'location.href');
    console.log('  点击后:', after.slice(0, 130));

    check(!after.includes('9601/login'), '已离开登录页');
    check(!cspViolations.some((v) => /form-action/i.test(v)), '无 form-action CSP 违规');
    check(after.includes('8788'), '回跳到下游 RP', after.slice(0, 60));

    console.log('\n第 3 步：确认下游会话已建立');
    const sessionStatus = await ev(ws, `fetch('${RP}/v1/auth/session', { credentials: 'include' })
        .then(async r => r.status + ' ' + (await r.text()).slice(0, 120))`);
    console.log('  /v1/auth/session →', sessionStatus);
    check(/^200/.test(sessionStatus), '下游会话有效（200）');
    check(/oauthor|OAuth/.test(sessionStatus), '会话包含登录用户');

    console.log('\n第 4 步：刷新页面，确认不会掉回登录页');
    await send(ws, 'Page.navigate', { url: RP + '/logshare/' });
    await new Promise((r) => setTimeout(r, 3000));
    const reloaded = await ev(ws, 'location.href');
    console.log('  刷新后:', reloaded.slice(0, 110));
    check(!reloaded.includes('9601'), '刷新后仍保持登录（未被踢回 IdP）');

    if (cspViolations.length) {
        console.log('\nCSP 违规:');
        cspViolations.slice(0, 3).forEach((v) => console.log('  ' + v.slice(0, 220)));
    }

    await send(ws, 'Page.close').catch(() => {});
    ws.close();
    console.log(`\n通过 ${passed} 项，失败 ${failed} 项。`);
    process.exit(failed === 0 ? 0 : 1);
};

main().catch((e) => { console.error('中断：', e.message); process.exit(1); });
