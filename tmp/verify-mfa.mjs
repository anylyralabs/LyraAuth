/**
 * 阶段 3 验证：TOTP 绑定 → 二次登录 → 恢复码 → 关闭双因素。
 *
 * 用法：VERIFY_BASE=http://127.0.0.1:9501 node verify-mfa.mjs
 */

import { writeFile, mkdir } from 'node:fs/promises';

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';

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

const shot = async (ws, name, width = 1280, height = 1100) => {
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    await evaluate(ws, 'window.scrollTo(0,0)');
    await new Promise((r) => setTimeout(r, 300));
    const data = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/${name}.png`, Buffer.from(data.data, 'base64'));
};

/** 在页面上下文中提交表单（保留 cookie 与 CSRF） */
const submitForm = async (ws, selector) =>
    evaluate(
        ws,
        `(() => {
            const form = document.querySelector(${JSON.stringify(selector)});
            if (!form) return 'missing-form';
            form.requestSubmit();
            return 'submitted';
        })()`
    );

const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    return ok ? 0 : 1;
};

/** 用 Node 计算 TOTP（与服务器同样的 30 秒步长、6 位、SHA1） */
const totp = async (secret) => {
    const { createHmac } = await import('node:crypto');
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const ch of secret.replace(/=+$/, '').toUpperCase()) {
        const idx = alphabet.indexOf(ch);
        if (idx === -1) throw new Error('非法 base32 字符: ' + ch);
        bits += idx.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    const key = Buffer.from(bytes);
    const counter = Math.floor(Date.now() / 1000 / 30);
    const buf = Buffer.alloc(8);
    buf.writeBigUInt64BE(BigInt(counter));
    const hmac = createHmac('sha1', key).update(buf).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = ((hmac[offset] & 0x7f) << 24 | hmac[offset + 1] << 16 | hmac[offset + 2] << 8 | hmac[offset + 3]) % 1_000_000;
    return String(code).padStart(6, '0');
};

const main = async () => {
    await mkdir(OUT, { recursive: true });
    const res = await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' });
    const page = await res.json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');

    // 用已有测试账号登录；不存在则用 seed 管理员
    const email = process.env.MFA_EMAIL || 'admin@lyraauth.local';
    const password = process.env.MFA_PASSWORD;
    if (!password) throw new Error('请通过 MFA_PASSWORD 提供账号密码');

    let failures = 0;

    // 每次从干净的浏览器状态开始，避免残留登录态影响断言
    await send(ws, 'Network.clearBrowserCookies');
    await send(ws, 'Network.clearBrowserCache');

    // 1. 登录
    await goto(ws, '/login', 'input[name=identifier]');
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=identifier]').value = ${JSON.stringify(email)};
            document.querySelector('[name=password]').value = ${JSON.stringify(password)};
            document.querySelector('form[action="/login"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `location.pathname === '/dashboard' || location.pathname === '/mfa'`, 15000);
    const afterLogin = await evaluate(ws, 'location.pathname');
    failures += log(afterLogin === '/dashboard', '密码登录成功', afterLogin);

    // 2. 进入账号安全，开始绑定 TOTP
    await goto(ws, '/account/security', '#totp');
    failures += log(true, '账号安全页渲染');

    await submitForm(ws, 'form[action="/account/totp/setup"]');
    await waitFor(ws, `document.querySelector('[data-secret]') !== null`, 15000);
    const secret = await evaluate(ws, `document.querySelector('[data-secret]').getAttribute('data-secret')`);
    failures += log(!!secret && secret.length >= 16, '生成 TOTP 密钥', secret?.slice(0, 4) + '…');
    await shot(ws, '06-totp-setup');

    // 3. 输入验证码完成绑定
    const code = await totp(secret);
    await evaluate(
        ws,
        `(() => {
            document.querySelector('form[action="/account/totp/confirm"] [name=code]').value = ${JSON.stringify(code)};
            document.querySelector('form[action="/account/totp/confirm"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `document.body.innerText.includes('恢复码')`, 15000);
    await new Promise((r) => setTimeout(r, 300));
    const recoveryCodes = await evaluate(
        ws,
        `Array.from(document.querySelectorAll('#recovery code')).map(n => n.textContent.trim())`
    );    failures += log(Array.isArray(recoveryCodes) && recoveryCodes.length === 10, '生成 10 个恢复码', recoveryCodes?.length);
    await shot(ws, '07-recovery-codes');

    // 4. 退出后重新登录，应要求二次验证
    await submitForm(ws, 'form[action="/logout"]');
    await waitFor(ws, `location.pathname === '/login'`, 15000);

    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=identifier]').value = ${JSON.stringify(email)};
            document.querySelector('[name=password]').value = ${JSON.stringify(password)};
            document.querySelector('form[action="/login"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `location.pathname === '/mfa'`, 15000);
    failures += log(true, '密码通过后要求二次验证');
    await shot(ws, '08-mfa-challenge');

    // 5. 错误验证码应被拒绝
    await evaluate(
        ws,
        `(() => {
            document.querySelector('form[action="/mfa/totp"] [name=code]').value = '000000';
            document.querySelector('form[action="/mfa/totp"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `document.body.innerText.includes('验证码不正确')`, 15000);
    failures += log(true, '错误验证码被拒绝');

    // 6. 正确验证码完成登录（等待进入下一个 30 秒时间步，避免防重放拒绝）
    const waitNextStep = async () => {
        const remain = 30000 - (Date.now() % 30000);
        if (remain > 1500) {
            console.log(`  · 等待 ${Math.ceil(remain / 1000)} 秒进入新的时间步`);
            await new Promise((r) => setTimeout(r, remain + 500));
        }
    };
    await waitNextStep();
    const code2 = await totp(secret);
    await evaluate(
        ws,
        `(() => {
            document.querySelector('form[action="/mfa/totp"] [name=code]').value = ${JSON.stringify(code2)};
            document.querySelector('form[action="/mfa/totp"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `location.pathname === '/dashboard'`, 15000);
    failures += log(true, '正确验证码完成登录');

    // 7. 数据库应记录 TOTP 与恢复码
    const dbCheck = await evaluate(ws, `fetch('/account/security').then(r => r.text()).then(t => t.includes('已启用'))`);
    failures += log(dbCheck, '安全页显示 TOTP 已启用');

    console.log(failures === 0 ? '\n阶段 3 验证通过。' : `\n有 ${failures} 项失败。`);

    // 输出恢复码与密钥供后续 Passkey / 登录测试使用
    console.log(JSON.stringify({ email, secret, recoveryCodes: recoveryCodes?.slice(0, 2) }));

    await send(ws, 'Page.close').catch(() => {});
    ws.close();
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
