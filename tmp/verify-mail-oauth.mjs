/**
 * 微软 OAuth2 邮件授权界面验证。
 *
 * 检查：配置卡片渲染、重定向 URI 正确、保存凭据、授权跳转 URL 的
 * client_id / scope / PKCE / state 是否合规。
 */

import { writeFile, mkdir } from 'node:fs/promises';

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const TOTP_SECRET = process.env.TOTP_SECRET || '';
const CLIENT_ID = process.env.TEST_CLIENT_ID || '11111111-2222-3333-4444-555555555555';

if (!PASSWORD) throw new Error('需要 ADMIN_PASSWORD');

const connect = (u) => new Promise((res, rej) => {
    const ws = new WebSocket(u);
    ws.onopen = () => res(ws);
    ws.onerror = () => rej(new Error('WS 失败'));
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
    if (r.exceptionDetails) throw new Error('脚本异常：' + (r.exceptionDetails.exception?.description || '').slice(0, 200));
    return r.result.value;
};

const waitFor = async (ws, expr, timeout = 15000) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await ev(ws, expr)) return true;
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error('等待超时：' + expr);
};

const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    return ok ? 0 : 1;
};

const totp = async (secret) => {
    const { createHmac } = await import('node:crypto');
    const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += a.indexOf(c).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    const buf = Buffer.alloc(8);
    buf.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const h = createHmac('sha1', Buffer.from(bytes)).update(buf).digest();
    const o = h[h.length - 1] & 0xf;
    return String(((h[o] & 0x7f) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1000000).padStart(6, '0');
};

const main = async () => {
    await mkdir(OUT, { recursive: true });
    let failures = 0;

    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.clearBrowserCookies');

    // 登录
    await send(ws, 'Page.navigate', { url: `${BASE}/login` });
    await new Promise((r) => setTimeout(r, 1200));
    await ev(ws, `document.querySelector('[name=identifier]').value='admin@lyraauth.local';document.querySelector('[name=password]').value=${JSON.stringify(PASSWORD)};document.querySelector('form[action="/login"]').requestSubmit()`);
    await waitFor(ws, `location.pathname==='/dashboard'||location.pathname==='/mfa'`);
    if (await ev(ws, `location.pathname==='/mfa'`)) {
        if (!TOTP_SECRET) throw new Error('需要 TOTP_SECRET');
        const remain = 30000 - (Date.now() % 30000);
        if (remain < 3000) await new Promise((r) => setTimeout(r, remain + 500));
        await ev(ws, `document.querySelector('[name=code]').value=${JSON.stringify(await totp(TOTP_SECRET))};document.querySelector('form[action="/mfa/totp"]').requestSubmit()`);
        await waitFor(ws, `location.pathname==='/dashboard'`);
    }
    failures += log(true, '管理员登录');

    // 设置页
    await send(ws, 'Page.navigate', { url: `${BASE}/admin/settings#mail-oauth` });
    await new Promise((r) => setTimeout(r, 1500));
    await waitFor(ws, `document.querySelector('#mail-oauth') !== null`);

    const text = await ev(ws, `document.querySelector('#mail-oauth').innerText`);
    failures += log(text.includes('微软账号授权'), 'OAuth 卡片渲染');
    failures += log(text.includes('重定向 URI'), '显示重定向 URI 说明');
    failures += log(text.includes('SMTP.Send'), '提到 SMTP.Send 权限');

    const redirectUri = await ev(ws, `(() => {
        const codes = Array.from(document.querySelectorAll('#mail-oauth code'));
        const el = codes.find(c => c.textContent.includes('/admin/settings/mail/oauth/callback'));
        return el ? el.textContent.trim() : '';
    })()`);
    const expected = 'http://localhost:9501/admin/settings/mail/oauth/callback';
    failures += log(redirectUri === expected, '重定向 URI 正确', redirectUri);

    // 保存凭据
    await ev(ws, `(() => {
        document.querySelector('[name=client_id]').value = ${JSON.stringify(CLIENT_ID)};
        document.querySelector('[name=client_secret]').value = 'test-secret-value-123';
        document.querySelector('[name=tenant]').value = 'consumers';
        document.querySelector('form[action="/admin/settings/mail/oauth/application"]').requestSubmit();
        return true;
    })()`);
    await waitFor(ws, `document.body.innerText.includes('应用凭据已保存')`);
    failures += log(true, '保存应用凭据');

    // 校验持久化：用同源 fetch 读取，避免整页导航丢失会话或停在旧 DOM
    await new Promise((r) => setTimeout(r, 600));
    const persisted = await ev(ws, `(async () => {
        const html = await (await fetch('/admin/settings', { headers: { Accept: 'text/html' } })).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const pick = (sel, attr) => {
            const el = doc.querySelector(sel);
            if (!el) return null;
            return attr ? el.getAttribute(attr) : el.value;
        };
        return {
            clientId: pick('[name=client_id]'),
            secretPlaceholder: pick('[name=client_secret]', 'placeholder'),
            tenant: pick('[name=tenant]'),
            hasCard: doc.querySelector('#mail-oauth') !== null,
        };
    })()`);

    if (!persisted || persisted.clientId === null) {
        throw new Error('读取设置页失败：' + JSON.stringify(persisted));
    }
    failures += log(persisted.clientId === CLIENT_ID, 'Client ID 已持久化');
    failures += log(
        typeof persisted.secretPlaceholder === 'string' && persisted.secretPlaceholder.includes('已设置'),
        '密钥已设置且不回显',
        persisted.secretPlaceholder
    );
    failures += log(persisted.tenant === 'consumers', '租户为 consumers');

    // 检查授权链接（不真正跳转，用 fetch 读 Location）
    const authUrl = await ev(ws, `(async () => {
        const res = await fetch('/admin/settings/mail/oauth/authorize', { redirect: 'manual' });
        return { status: res.status, type: res.type };
    })()`);
    failures += log(authUrl.status === 0 || authUrl.status === 302, '授权端点返回重定向', authUrl.status);

    // 用 Node 侧带 cookie 再取一次，检查 URL 参数
    const cookies = await send(ws, 'Network.getCookies', { urls: [BASE] });
    const cookieHeader = (cookies.cookies || []).map((c) => `${c.name}=${c.value}`).join('; ');
    const authRes = await fetch(`${BASE}/admin/settings/mail/oauth/authorize`, {
        headers: { Cookie: cookieHeader, Accept: 'text/html' },
        redirect: 'manual',
    });
    const location = authRes.headers.get('location') || '';
    failures += log(authRes.status === 302, '带会话请求返回 302', authRes.status);

    if (location) {
        const url = new URL(location);
        const params = url.searchParams;
        failures += log(url.host === 'login.microsoftonline.com', '跳转到微软登录域', url.host);
        failures += log(url.pathname.includes('/consumers/oauth2/v2.0/authorize'), '使用 consumers 端点', url.pathname);
        failures += log(params.get('client_id') === CLIENT_ID, 'client_id 正确');
        failures += log(params.get('redirect_uri') === expected, 'redirect_uri 正确');
        failures += log(params.get('response_type') === 'code', 'response_type=code');
        failures += log((params.get('scope') || '').includes('https://outlook.office.com/SMTP.Send'), '包含 SMTP.Send scope');
        failures += log((params.get('scope') || '').includes('offline_access'), '包含 offline_access');
        failures += log(params.get('code_challenge_method') === 'S256', 'PKCE 使用 S256');
        failures += log((params.get('code_challenge') || '').length > 20, '包含 code_challenge');
        failures += log((params.get('state') || '').length >= 16, '包含 state');
    }

    // 截图
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 1200, deviceScaleFactor: 1, mobile: false });
    await ev(ws, `document.querySelector('#mail-oauth').scrollIntoView({block:'start'}); window.scrollBy(0,-70)`);
    await new Promise((r) => setTimeout(r, 400));
    const shot = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/19-mail-oauth.png`, Buffer.from(shot.data, 'base64'));

    // 清理测试凭据
    await fetch(`${BASE}/admin/settings/mail/oauth/revoke`, {
        method: 'POST',
        headers: {
            Cookie: cookieHeader,
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'text/html',
        },
        body: `_token=${await ev(ws, `document.querySelector('input[name=_token]')?.value || ''`)}`,
        redirect: 'manual',
    });

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\n微软 OAuth 授权界面验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
