/**
 * 阶段 6 验证：管理概览、用户管理、审计日志、权限范围、设置、组织。
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

    return evaluate(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`);
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
        if (!TOTP_SECRET) throw new Error('需要 TOTP_SECRET');
        const remain = 30000 - (Date.now() % 30000);
        if (remain < 3000) await new Promise((r) => setTimeout(r, remain + 500));
        const code = await totp(TOTP_SECRET);
        await evaluate(ws, `document.querySelector('[name=code]').value = ${JSON.stringify(code)}; document.querySelector('form[action="/mfa/totp"]').requestSubmit()`);
        await waitFor(ws, `location.pathname === '/dashboard'`, 15000);
    }
    failures += log(true, '管理员登录');

    // 管理概览
    await goto(ws, '/admin', 'main h1');
    const dashText = await evaluate(ws, `document.querySelector('main').innerText`);
    failures += log(dashText.includes('用户总数') && dashText.includes('最近登录尝试'), '管理概览渲染');
    const dashOverflow = await shot(ws, '12-admin-dashboard');
    failures += log(dashOverflow <= 0, '概览无横向溢出', { overflow: dashOverflow });

    // 用户管理
    await goto(ws, '/admin/users', 'table.data-table');
    const userCount = await evaluate(ws, `document.querySelectorAll('tbody tr').length`);
    failures += log(userCount >= 2, '用户列表有数据', { rows: userCount });

    // 搜索
    await goto(ws, '/admin/users?q=oauthor', 'table.data-table');
    const searched = await evaluate(ws, `document.querySelector('tbody').innerText`);
    failures += log(searched.includes('oauthor'), '用户搜索生效');

    // 用户详情
    const detailHref = await evaluate(
        ws,
        `(() => {
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            const row = rows.find(r => r.innerText.includes('oauthor'));
            const link = row?.querySelector('a[href^="/admin/users/"]');
            return link ? link.getAttribute('href') : null;
        })()`
    );
    failures += log(!!detailHref, '找到用户详情链接', detailHref);

    if (detailHref) {
        await goto(ws, detailHref, 'main h1');
        const detailText = await evaluate(ws, `document.querySelector('main').innerText`);
        failures += log(
            detailText.includes('平台角色') && detailText.includes('最近会话'),
            '用户详情展示完整信息'
        );
        await shot(ws, '13-admin-user-detail');

        // 强制双因素开关
        const before = await evaluate(ws, `document.querySelector('[name=mfa_enforced]').checked`);
        await evaluate(
            ws,
            `(() => {
                const cb = document.querySelector('[name=mfa_enforced]');
                cb.checked = !cb.checked;
                cb.closest('form').requestSubmit();
                return true;
            })()`
        );
        await new Promise((r) => setTimeout(r, 1200));
        const after = await evaluate(ws, `document.querySelector('[name=mfa_enforced]').checked`);
        failures += log(before !== after, '双因素强制策略可切换', { before, after });

        // 还原
        await evaluate(
            ws,
            `(() => {
                const cb = document.querySelector('[name=mfa_enforced]');
                cb.checked = ${JSON.stringify(before)};
                cb.closest('form').requestSubmit();
                return true;
            })()`
        );
        await new Promise((r) => setTimeout(r, 1000));
    }

    // 审计日志：操作日志
    await goto(ws, '/admin/audit-logs', 'table.data-table');
    const auditText = await evaluate(ws, `document.querySelector('tbody').innerText`);
    failures += log(auditText.includes('auth.login') || auditText.includes('admin.'), '操作日志有记录');

    // 审计日志：登录记录
    await goto(ws, '/admin/audit-logs?tab=logins', 'table.data-table');
    const loginText = await evaluate(ws, `document.querySelector('tbody').innerText`);
    failures += log(loginText.includes('admin@lyraauth.local') || loginText.includes('oauthor'), '登录记录有数据');
    await shot(ws, '14-admin-audit');

    // 权限范围
    await goto(ws, '/admin/scopes', 'table.data-table');
    const scopesText = await evaluate(ws, `document.querySelector('tbody').innerText`);
    failures += log(scopesText.includes('openid') && scopesText.includes('profile'), '权限范围列表渲染');

    // 新建 scope：用 fetch 提交，避免页面重定向导致断言时机错乱
    const scopeId = `test_scope_${Date.now().toString().slice(-6)}`;
    const scopeResult = await evaluate(
        ws,
        `(async () => {
            const form = document.querySelector('form[action="/admin/scopes"]');
            const token = form.querySelector('[name=_token]').value;
            const res = await fetch('/admin/scopes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' },
                body: new URLSearchParams({
                    _token: token,
                    identifier: ${JSON.stringify(scopeId)},
                    name: '测试范围',
                    description: '自动化验证创建',
                }),
                redirect: 'manual',
            });
            return res.status;
        })()`
    );
    await new Promise((r) => setTimeout(r, 600));
    await goto(ws, '/admin/scopes', 'table.data-table');
    const scopeCreated = await evaluate(ws, `document.body.innerText.includes(${JSON.stringify(scopeId)})`);
    failures += log(scopeResult === 0 || scopeResult === 302, '新建 scope 请求被接受', scopeResult);
    failures += log(scopeCreated, '新建权限范围出现在列表中', scopeId);

    // 设置页
    await goto(ws, '/admin/settings', 'input[name=site_name]');
    const settingsText = await evaluate(ws, `document.querySelector('main').innerText`);
    failures += log(settingsText.includes('通用') && settingsText.includes('邮件'), '设置页渲染');
    await shot(ws, '15-admin-settings');

    // 保存通用设置
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=site_name]').value = 'LyraAuth';
            document.querySelector('form[action="/admin/settings/general"]').requestSubmit();
            return true;
        })()`
    );
    await new Promise((r) => setTimeout(r, 1200));
    const saved = await evaluate(ws, `document.body.innerText.includes('设置已保存')`);
    failures += log(saved, '设置保存成功');

    // 组织页
    await goto(ws, '/admin/organizations', 'table.data-table');
    failures += log(true, '组织列表页渲染');

    // 用户侧：组织创建
    await goto(ws, '/organizations', 'main h1');
    const orgText = await evaluate(ws, `document.querySelector('main').innerText`);
    failures += log(orgText.includes('创建组织'), '用户组织页渲染');

    const orgSlug = `e2e-org-${Date.now().toString().slice(-6)}`;
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=name]').value = '自动化测试组织';
            document.querySelector('[name=slug]').value = ${JSON.stringify(orgSlug)};
            document.querySelector('form[action="/organizations"]').requestSubmit();
            return true;
        })()`
    );
    await new Promise((r) => setTimeout(r, 1500));
    const orgCreated = await evaluate(ws, `location.pathname`);
    failures += log(orgCreated === `/organizations/${orgSlug}`, '创建组织并跳转', orgCreated);

    const memberText = await evaluate(ws, `document.body.innerText`);
    failures += log(memberText.includes('拥有者') && memberText.includes('添加成员'), '组织详情展示成员与管理入口');
    await shot(ws, '16-organization');

    // 移动端检查
    await goto(ws, '/admin', 'main h1');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await new Promise((r) => setTimeout(r, 400));
    const mobileOverflow = await evaluate(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`);
    failures += log(mobileOverflow <= 0, '移动端管理页无横向溢出', { overflow: mobileOverflow });
    await shot(ws, '17-admin-mobile', 390, 844);

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\n阶段 6 验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
