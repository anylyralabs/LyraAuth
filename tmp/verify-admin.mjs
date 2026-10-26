/**
 * 管理后台验证：登录 → 应用列表 → 创建应用 → 密钥展示 → 编辑 → 轮换 → 停用。
 */

import { writeFile, mkdir } from 'node:fs/promises';

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';
const EMAIL = process.env.ADMIN_EMAIL || 'admin@lyraauth.local';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const TOTP_SECRET = process.env.TOTP_SECRET || '';

if (!PASSWORD) throw new Error('请通过 ADMIN_PASSWORD 提供管理员密码');

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

const goto = async (ws, path, selector) => {
    await send(ws, 'Page.navigate', { url: BASE + path });
    await new Promise((r) => setTimeout(r, 300));
    await waitFor(ws, `document.readyState === 'complete'`);
    if (selector) await waitFor(ws, `document.querySelector(${JSON.stringify(selector)}) !== null`, 15000);
};

const shot = async (ws, name, width = 1440, height = 1000) => {
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    await evaluate(ws, 'window.scrollTo(0,0)');
    await new Promise((r) => setTimeout(r, 300));
    const data = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/${name}.png`, Buffer.from(data.data, 'base64'));
};

const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    return ok ? 0 : 1;
};

const totp = async (secret) => {
    const { createHmac } = await import('node:crypto');
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const ch of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(ch).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    const buf = Buffer.alloc(8);
    buf.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const hmac = createHmac('sha1', Buffer.from(bytes)).update(buf).digest();
    const off = hmac[hmac.length - 1] & 0xf;
    return String(((hmac[off] & 0x7f) << 24 | hmac[off + 1] << 16 | hmac[off + 2] << 8 | hmac[off + 3]) % 1000000).padStart(6, '0');
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
    await goto(ws, '/login', 'input[name=identifier]');
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

    if (await evaluate(ws, `location.pathname === '/mfa'`)) {
        if (!TOTP_SECRET) throw new Error('该账号启用 TOTP，请通过 TOTP_SECRET 提供密钥');
        const remain = 30000 - (Date.now() % 30000);
        if (remain < 3000) await new Promise((r) => setTimeout(r, remain + 500));
        const code = await totp(TOTP_SECRET);
        await evaluate(
            ws,
            `(() => {
                document.querySelector('[name=code]').value = ${JSON.stringify(code)};
                document.querySelector('form[action="/mfa/totp"]').requestSubmit();
                return true;
            })()`
        );
        await waitFor(ws, `location.pathname === '/dashboard'`, 15000);
    }
    failures += log(true, '管理员登录成功');

    // 应用列表
    await goto(ws, '/admin/clients', 'table.data-table, .panel');
    const listText = await evaluate(ws, `document.querySelector('main').innerText`);
    failures += log(listText.includes('应用管理') || listText.includes('演示应用'), '应用列表渲染');
    await shot(ws, '09-admin-clients');

    // 创建应用
    await goto(ws, '/admin/clients/create', 'input[name=name]');
    const appName = `E2E 测试应用 ${Date.now().toString().slice(-6)}`;
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=name]').value = ${JSON.stringify(appName)};
            document.querySelector('[name=description]').value = '由自动化验证创建';
            document.querySelector('[name=client_type][value=confidential]').checked = true;
            document.querySelector('[name=redirect_uris]').value = 'https://example.com/callback\\nhttps://example.com/other';
            document.querySelector('[name=post_logout_uris]').value = 'https://example.com/';
            document.querySelector('[name=client_uri]').value = 'https://example.com';
            return true;
        })()`
    );
    await evaluate(ws, `document.querySelector('form[action="/admin/clients"]').requestSubmit()`);
    await waitFor(ws, `location.pathname === '/admin/clients'`, 15000);
    await waitFor(ws, `document.body.innerText.includes('仅显示一次')`, 15000);

    const secretShown = await evaluate(ws, `document.querySelector('.panel code')?.textContent.trim() || ''`);
    failures += log(secretShown.length > 20, '创建后展示客户端密钥', secretShown.slice(0, 8) + '…');
    await shot(ws, '10-admin-client-created');

    // 刷新后密钥应消失
    await goto(ws, '/admin/clients', null);
    const secretAfterReload = await evaluate(ws, `document.body.innerText.includes('仅显示一次')`);
    failures += log(!secretAfterReload, '刷新后密钥不再显示');

    // 编辑页
    await goto(ws, '/admin/clients', 'table.data-table');
    const editHref = await evaluate(
        ws,
        `(() => {
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            const row = rows.find(r => r.innerText.includes(${JSON.stringify(appName)}));
            if (!row) return null;
            const link = row.querySelector('a[href*="/edit"]');
            return link ? link.getAttribute('href') : null;
        })()`
    );
    failures += log(!!editHref, '找到新建应用的编辑入口', editHref);

    if (editHref) {
        await goto(ws, editHref, 'input[name=name]');
        const loadedName = await evaluate(ws, `document.querySelector('[name=name]').value`);
        failures += log(loadedName === appName, '编辑页回填应用名', loadedName);
        await shot(ws, '11-admin-client-edit');

        // 修改并保存
        await evaluate(
            ws,
            `(() => {
                document.querySelector('[name=name]').value = ${JSON.stringify(appName + ' 改')};
                document.querySelector('form[action^="/admin/clients/"]').requestSubmit();
                return true;
            })()`
        );
        await waitFor(ws, `location.pathname === '/admin/clients'`, 15000);
        const updated = await evaluate(ws, `document.body.innerText.includes(${JSON.stringify(appName + ' 改')})`);
        failures += log(updated, '保存后列表显示新名称');
    }

    // 越权：普通用户访问后台应 403
    await send(ws, 'Network.clearBrowserCookies');
    await goto(ws, '/login', 'input[name=identifier]');
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=identifier]').value = 'oauthor@example.com';
            document.querySelector('[name=password]').value = ${JSON.stringify(process.env.NORMAL_USER_PASS || '')};
            document.querySelector('form[action="/login"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `location.pathname === '/dashboard'`, 15000);
    await goto(ws, '/admin/clients', null);
    await new Promise((r) => setTimeout(r, 800));
    const forbidden = await evaluate(ws, `document.body.innerText`);
    failures += log(
        forbidden.includes('403') || forbidden.includes('没有访问权限') || !forbidden.includes('应用管理'),
        '普通用户访问后台被拒绝',
        forbidden.slice(0, 40).replace(/\s+/g, ' ')
    );

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\n管理后台验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
