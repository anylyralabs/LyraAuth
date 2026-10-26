/**
 * SMTP 设置页验证：保存配置 → 发送测试邮件 → 校验真实投递。
 *
 * 需要本地测试 SMTP 服务（见 tmp/smtp-test-server.py），或指定真实服务器。
 */

import { writeFile, mkdir } from 'node:fs/promises';
import { readFile } from 'node:fs/promises';

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';
const PASSWORD = process.env.ADMIN_PASSWORD || '';
const TOTP_SECRET = process.env.TOTP_SECRET || '';

const SMTP_HOST = process.env.SMTP_HOST || '127.0.0.1';
const SMTP_PORT = process.env.SMTP_PORT || '2587';
const SMTP_ENCRYPTION = process.env.SMTP_ENCRYPTION || 'tls';
const SMTP_USER = process.env.SMTP_USER || 'mailer@test.local';
const SMTP_PASS = process.env.SMTP_PASS || 'test-pass-2026';
const VERIFY_PEER = process.env.SMTP_VERIFY_PEER || 'false';
const MAIL_LOG = process.env.SMTP_OUT || '/tmp/opencode/mail-starttls.jsonl';

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

/** 读取测试服务器收到的最后一封邮件 */
const lastMail = async () => {
    try {
        const lines = (await readFile(MAIL_LOG, 'utf8')).trim().split('\n').filter(Boolean);
        return lines.length > 0 ? JSON.parse(lines[lines.length - 1]) : null;
    } catch {
        return null;
    }
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
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=identifier]').value = 'admin@lyraauth.local';
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
        await evaluate(
            ws,
            `document.querySelector('[name=code]').value = ${JSON.stringify(await totp(TOTP_SECRET))};
             document.querySelector('form[action="/mfa/totp"]').requestSubmit()`
        );
        await waitFor(ws, `location.pathname === '/dashboard'`, 15000);
    }
    failures += log(true, '管理员登录');

    // 打开设置页
    await send(ws, 'Page.navigate', { url: `${BASE}/admin/settings#mail` });
    await new Promise((r) => setTimeout(r, 1500));
    await waitFor(ws, `document.querySelector('[name=mail_host]') !== null`, 15000);
    const hasTestForm = await evaluate(ws, `document.querySelector('form[action="/admin/settings/mail/test"]') !== null`);
    failures += log(hasTestForm, '设置页包含测试邮件表单');

    // 填写 SMTP 配置
    await evaluate(
        ws,
        `(() => {
            const set = (name, value) => {
                const el = document.querySelector('[name=' + name + ']');
                if (!el) return;
                if (el.type === 'checkbox') { el.checked = ${VERIFY_PEER === 'true'}; return; }
                el.value = value;
            };
            set('mail_driver', 'smtp');
            set('mail_host', ${JSON.stringify(SMTP_HOST)});
            set('mail_port', ${JSON.stringify(SMTP_PORT)});
            set('mail_username', ${JSON.stringify(SMTP_USER)});
            set('mail_password', ${JSON.stringify(SMTP_PASS)});
            set('mail_encryption', ${JSON.stringify(SMTP_ENCRYPTION)});
            set('mail_verify_peer', '');
            set('mail_from_address', 'no-reply@test.local');
            set('mail_from_name', 'LyraAuth 测试');
            document.querySelector('form[action="/admin/settings/mail"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `document.body.innerText.includes('设置已保存')`, 15000);
    failures += log(true, '保存 SMTP 配置');

    // 刷新确认配置持久化
    await send(ws, 'Page.navigate', { url: `${BASE}/admin/settings#mail` });
    await new Promise((r) => setTimeout(r, 1500));
    const persisted = await evaluate(
        ws,
        `({
            host: document.querySelector('[name=mail_host]').value,
            port: document.querySelector('[name=mail_port]').value,
            enc: document.querySelector('[name=mail_encryption]').value,
            driver: document.querySelector('[name=mail_driver]').value,
            pwdPlaceholder: document.querySelector('[name=mail_password]').getAttribute('placeholder'),
        })`
    );
    failures += log(persisted.host === SMTP_HOST && persisted.port === SMTP_PORT, '配置已持久化', persisted);
    failures += log(
        typeof persisted.pwdPlaceholder === 'string' && persisted.pwdPlaceholder.includes('已设置'),
        '密码显示为已设置（不回显）',
        persisted.pwdPlaceholder
    );

    // 发送测试邮件
    await evaluate(
        ws,
        `(() => {
            document.querySelector('[name=to]').value = 'receiver@example.com';
            document.querySelector('form[action="/admin/settings/mail/test"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `document.body.innerText.includes('测试邮件已发送') || document.body.innerText.includes('发送失败')`, 20000);
    const resultText = await evaluate(ws, `document.body.innerText`);
    const sent = resultText.includes('测试邮件已发送');
    failures += log(sent, 'UI 显示发送成功');

    // 校验测试服务器真的收到了
    await new Promise((r) => setTimeout(r, 500));
    const mail = await lastMail();
    failures += log(!!mail, '测试服务器收到邮件');
    if (mail) {
        failures += log(mail.rcpt_to.includes('<receiver@example.com>'), '收件人正确', mail.rcpt_to);
        failures += log(mail.tls === true, '连接已加密（服务器侧确认）', mail.tls);
        failures += log(mail.auth === true, '已通过 AUTH 认证');
        failures += log(mail.data.includes('Subject:'), '邮件包含主题头');
    }

    await writeFile(`${OUT}/18-smtp-settings.png`, 'skip');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
    await evaluate(ws, `window.scrollTo(0, document.querySelector('#mail').offsetTop - 80)`);
    await new Promise((r) => setTimeout(r, 400));
    const shot = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/18-smtp-settings.png`, Buffer.from(shot.data, 'base64'));

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\nSMTP 配置验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
