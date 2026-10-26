/**
 * 新 UI 视觉检查：截图 + 布局量测 + 字体加载确认。
 */

import { writeFile, mkdir } from 'node:fs/promises';

const BASE = process.env.VERIFY_BASE || 'http://192.168.8.211:9501';
const CDP = 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots/ui';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const TOTP_SECRET = process.env.TOTP_SECRET || '';

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

const waitFor = async (ws, expr, timeout = 20000) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await ev(ws, expr)) return true;
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error('等待超时：' + expr);
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

const shot = async (ws, name, width = 1440, height = 1000, mobile = false) => {
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: mobile ? 2 : 1, mobile });
    await ev(ws, 'window.scrollTo(0,0)');
    await new Promise((r) => setTimeout(r, 450));
    const data = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/${name}.png`, Buffer.from(data.data, 'base64'));
};

const nav = async (ws, path) => {
    await send(ws, 'Page.navigate', { url: BASE + path });
    await new Promise((r) => setTimeout(r, 400));
    await waitFor(ws, `document.readyState === 'complete'`, 15000);
    await new Promise((r) => setTimeout(r, 500));
};

const main = async () => {
    await mkdir(OUT, { recursive: true });

    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.enable');
    await send(ws, 'Network.clearBrowserCookies');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

    // 首页（桌面 + 移动）
    await nav(ws, '/');
    await shot(ws, '01-home-desktop');

    const metrics = await ev(ws, `(() => {
        const h1 = document.querySelector('h1');
        const s = getComputedStyle(h1);
        return {
            title: document.title,
            h1Font: s.fontFamily.split(',')[0],
            bodyFont: getComputedStyle(document.body).fontFamily.split(',')[0],
            bg: getComputedStyle(document.body).backgroundColor,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            fontsReady: document.fonts ? document.fonts.status : 'n/a',
            bricolageLoaded: document.fonts ? document.fonts.check('600 40px "Bricolage Grotesque"') : false,
            instrumentLoaded: document.fonts ? document.fonts.check('400 14px "Instrument Sans"') : false,
            monoLoaded: document.fonts ? document.fonts.check('400 12px "IBM Plex Mono"') : false,
        };
    })()`);
    console.log('首页量测:', JSON.stringify(metrics, null, 1));

    await shot(ws, '02-home-mobile', 390, 844, true);

    // 登录页
    await nav(ws, '/login');
    await shot(ws, '03-login-desktop');
    await shot(ws, '04-login-mobile', 390, 844, true);

    // 注册页
    await nav(ws, '/register');
    await shot(ws, '05-register');

    // 登录进后台
    await nav(ws, '/login');
    await waitFor(ws, `document.querySelector('input[name=identifier]') !== null`);
    await ev(ws, `(() => {
        const f = document.querySelector('form[action="/login"]');
        f.querySelector('[name=identifier]').value = 'admin@lyraauth.local';
        f.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
        f.querySelector('button[type=submit]').click();
        return true;
    })()`);
    await waitFor(ws, `location.pathname !== '/login'`, 20000);

    if ((await ev(ws, 'location.pathname')) === '/mfa') {
        if (!TOTP_SECRET) throw new Error('需要 TOTP_SECRET');
        const remain = 30000 - (Date.now() % 30000);
        if (remain < 3000) await new Promise((r) => setTimeout(r, remain + 500));
        await ev(ws, `document.querySelector('[name=code]').value = ${JSON.stringify(await totp(TOTP_SECRET))}; document.querySelector('form[action="/mfa/totp"]').requestSubmit()`);
        await waitFor(ws, `location.pathname !== '/mfa'`, 20000);
    }
    console.log('登录后路径:', await ev(ws, 'location.pathname'));

    // 仪表盘
    await nav(ws, '/dashboard');
    await shot(ws, '06-dashboard');

    const dash = await ev(ws, `(() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        readouts: document.querySelectorAll('.readout').length,
        panels: document.querySelectorAll('.panel').length,
        navActive: document.querySelector('aside a[aria-current="page"]')?.textContent.trim() || null,
    }))()`);
    console.log('仪表盘量测:', JSON.stringify(dash));

    // 账号安全
    await nav(ws, '/account/security');
    await shot(ws, '07-security');

    // 管理概览
    await nav(ws, '/admin');
    await shot(ws, '08-admin-dashboard');

    // 应用列表
    await nav(ws, '/admin/clients');
    await shot(ws, '09-admin-clients');

    // 用户详情
    await nav(ws, '/admin/users');
    await shot(ws, '10-admin-users');

    // 审计日志
    await nav(ws, '/admin/audit-logs');
    await shot(ws, '11-admin-audit');

    // 设置
    await nav(ws, '/admin/settings');
    await shot(ws, '12-admin-settings');
    // 长页底部：微软 OAuth 与签名密钥
    await ev(ws, `window.scrollTo(0, document.documentElement.scrollHeight)`);
    await new Promise((r) => setTimeout(r, 450));
    const settingsBottom = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/12b-admin-settings-bottom.png`, Buffer.from(settingsBottom.data, 'base64'));
    await ev(ws, 'window.scrollTo(0,0)');

    // 用户详情
    await nav(ws, '/admin/users/1');
    await shot(ws, '12b-admin-user-detail');

    // 新建应用表单
    await nav(ws, '/admin/clients/create');
    await shot(ws, '12c-admin-client-form');

    // 编辑应用
    await nav(ws, '/admin/clients/1/edit');
    await shot(ws, '12c2-admin-client-edit');

    // 组织与权限范围
    await nav(ws, '/admin/organizations');
    await shot(ws, '12d-admin-organizations');
    await nav(ws, '/admin/scopes');
    await shot(ws, '12e-admin-scopes');

    // 组织详情
    await nav(ws, '/admin/organizations');
    const orgHref = await ev(ws, `document.querySelector('a[href^="/organizations/"]')?.getAttribute('href') || ''`);
    if (orgHref) {
        await nav(ws, orgHref);
        await shot(ws, '12d2-organization-show');
    }

    // 用户侧页面
    await nav(ws, '/account/profile');
    await shot(ws, '12f-account-profile');
    await nav(ws, '/account/apps');
    await shot(ws, '12g-account-apps');
    await nav(ws, '/organizations');
    await shot(ws, '12h-organizations');

    // 移动端后台
    await nav(ws, '/dashboard');
    await shot(ws, '13-dashboard-mobile', 390, 844, true);
    const mobileDash = await ev(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`);
    console.log('移动端仪表盘横向溢出:', mobileDash);

    await nav(ws, '/admin/users');
    await shot(ws, '13b-admin-users-mobile', 390, 844, true);
    console.log('移动端用户列表横向溢出:', await ev(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`));

    await nav(ws, '/admin/settings');
    await shot(ws, '13c-admin-settings-mobile', 390, 844, true);
    console.log('移动端设置页横向溢出:', await ev(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`));

    await nav(ws, '/account/profile');
    await shot(ws, '13d-profile-mobile', 390, 844, true);
    console.log('移动端资料页横向溢出:', await ev(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`));

    // 404
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await send(ws, 'Page.navigate', { url: BASE + '/this-does-not-exist' });
    await new Promise((r) => setTimeout(r, 1200));
    await shot(ws, '14-error-404');

    // 授权同意页（用演示客户端）
    await nav(ws, '/oauth/authorize?response_type=code&client_id=lyra_c6549addd7745b4ff6f66398&redirect_uri=' + encodeURIComponent('http://192.168.8.211:9502/callback') + '&scope=openid+profile+email&state=ui&nonce=ui&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256');
    await shot(ws, '15-oauth-consent');

    // 设备码登录页
    await nav(ws, '/device');
    await shot(ws, '16-device-login');

    // 移动端：应用列表 + 展开行内菜单，验证弹出层定位
    await nav(ws, '/admin/clients');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await ev(ws, `(() => {
        const rows = document.querySelectorAll('table.data-table tbody tr');
        const d = rows[rows.length - 1]?.querySelector('details[data-menu]');
        if (d) d.open = true;
        return !!d;
    })()`);
    await new Promise((r) => setTimeout(r, 400));
    const menuMetrics = await ev(ws, `(() => {
        const menu = document.querySelector('details[data-menu][open] .panel');
        if (!menu) return null;
        const r = menu.getBoundingClientRect();
        return {
            top: Math.round(r.top), bottom: Math.round(r.bottom),
            left: Math.round(r.left), right: Math.round(r.right),
            withinViewport: r.left >= 0 && r.right <= window.innerWidth && r.top >= 0 && r.bottom <= window.innerHeight,
            position: getComputedStyle(menu).position,
        };
    })()`);
    console.log('移动端菜单:', JSON.stringify(menuMetrics));
    await shot(ws, '17-clients-menu-mobile', 390, 844, true);

    await nav(ws, '/admin/audit-logs');
    await shot(ws, '18-admin-audit-mobile', 390, 844, true);
    console.log('移动端审计横向溢出:', await ev(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`));

    await send(ws, 'Page.close').catch(() => {});
    ws.close();
    console.log('\n截图完成，输出目录：' + OUT);
};

main().catch((e) => {
    console.error('失败：', e.message);
    process.exit(1);
});
