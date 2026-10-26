/**
 * 真实用户路径验证：点击、提交、页面导航，最终断言 DOM 内容与截图。
 *
 * 用法：VERIFY_BASE=http://127.0.0.1:9501 node verify-auth-flow.mjs
 */

import { readFile, writeFile, mkdir } from 'node:fs/promises';

const BASE = process.env.VERIFY_BASE || 'http://127.0.0.1:9501';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';
const MAIL_LOG = process.env.MAIL_LOG || '/home/YanSui/Project/LyraAuth/runtime/logs/mail.log';

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
    if (r.exceptionDetails) {
        throw new Error('脚本异常：' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    }
    return r.result.value;
};

const waitFor = async (ws, expr, timeout = 15000, label = expr) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await evaluate(ws, expr)) return true;
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error('等待超时：' + label);
};

/** 导航并等待页面真正挂载（用 loading 状态判定，兼容服务端重定向） */
/** 导航并等待加载完成（用 CDP 的 frameStoppedLoading 事件，兼容重定向回同一 URL） */
const goto = async (ws, path, readySelector) => {
    const stopped = new Promise((resolve) => {
        const handler = (event) => {
            const data = JSON.parse(event.data);
            if (data.method === 'Page.frameStoppedLoading') {
                ws.removeEventListener('message', handler);
                resolve(true);
            }
        };
        ws.addEventListener('message', handler);
        // 兜底：3 秒内没有事件也继续，交给后续断言判断
        setTimeout(() => {
            ws.removeEventListener('message', handler);
            resolve(false);
        }, 8000);
    });

    await send(ws, 'Page.navigate', { url: BASE + path });
    await stopped;
    await waitFor(ws, `document.readyState === 'complete'`, 15000, 'readyState ' + path);
    if (readySelector) {
        await waitFor(ws, `document.querySelector(${JSON.stringify(readySelector)}) !== null`, 15000, path + ' ' + readySelector);
    }
};

const shot = async (ws, name, width = 1280, height = 900) => {
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    await evaluate(ws, 'window.scrollTo(0,0)');
    await new Promise((r) => setTimeout(r, 300));
    const data = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/${name}.png`, Buffer.from(data.data, 'base64'));
};

const log = (ok, label, extra) => console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);

/** 当前页面主内容标题（shell 布局的 pageTitle） */
const mainHeading = `(() => {
    const h = document.querySelector('main h1');
    return h ? h.textContent.trim() : null;
})()`;

const main = async () => {
    await mkdir(OUT, { recursive: true });

    const res = await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' });
    const page = await res.json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');

    // 复用浏览器进程时会带上之前的登录态，先清空以模拟新访客
    await send(ws, 'Network.clearBrowserCookies');
    await send(ws, 'Network.clearBrowserCache');

    const stamp = Date.now().toString().slice(-9);
    const email = `verify${stamp}@example.com`;
    const username = `verify${stamp}`;
    const password = 'Verify2026Pass!';
    let failures = 0;

    // 1. 注册页
    await goto(ws, '/register', 'input[name=email]');
    const regTitle = await evaluate(ws, `document.querySelector('main h1')?.textContent.trim()`);
    log(regTitle?.includes('创建'), '注册页渲染', regTitle);
    await shot(ws, '01-register');

    // 2. 真实填表并点击提交
    await evaluate(
        ws,
        `(() => {
            const set = (name, value) => {
                const el = document.querySelector('[name=' + name + ']');
                el.value = value;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            };
            set('email', ${JSON.stringify(email)});
            set('username', ${JSON.stringify(username)});
            set('display_name', '验证用户');
            set('password', ${JSON.stringify(password)});
            set('password_confirmation', ${JSON.stringify(password)});
            document.querySelector('form[action="/register"]').requestSubmit();
            return true;
        })()`
    );
    await waitFor(ws, `location.pathname === '/register/pending'`, 15000, '注册跳转');
    const pendingText = await evaluate(ws, `document.body.innerText`);
    log(pendingText.includes('查收验证邮件'), '注册后跳转到待验证页');

    // 3. 从 mail.log 取链接（按邮件分隔解析，取本次注册那一封）
    const mailLog = await readFile(MAIL_LOG, 'utf8');
    const occurrences = [...mailLog.matchAll(new RegExp(`To: ${email.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&')}`, 'g'))];
    if (occurrences.length === 0) throw new Error('mail.log 中未找到本次注册的邮件');
    const start = occurrences[occurrences.length - 1].index;
    const slice = mailLog.slice(start);
    const match = slice.match(/\/email\/verify\/([A-Za-z0-9_-]+)/);
    if (!match) throw new Error('邮件中未找到验证链接');
    log(occurrences.length === 1, '邮件中包含验证链接', match[1].slice(0, 10) + '…');

    // 4. 访问验证链接
    await goto(ws, `/email/verify/${match[1]}`, 'main, .panel');
    const verified = await evaluate(ws, `document.body.innerText`);
    log(verified.includes('验证成功'), '邮箱验证成功');
    await shot(ws, '02-email-verified');

    // 5. 登录
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
    await waitFor(ws, `location.pathname === '/dashboard'`, 15000, '登录跳转');
    await waitFor(ws, `document.querySelector('main h1') !== null`, 15000, '仪表盘挂载');
    await new Promise((r) => setTimeout(r, 400));

    const dashTitle = await evaluate(ws, mainHeading);
    const dashText = await evaluate(ws, `document.querySelector('main').innerText`);
    const dashOk = dashTitle === '概览' && dashText.includes('双因素');
    if (!dashOk) failures++;
    log(dashOk, '仪表盘渲染', dashTitle);
    await shot(ws, '03-dashboard');

    // 6. 会话 cookie 属性
    const cookies = await send(ws, 'Network.getCookies', { urls: [BASE + '/'] });
    const session = (cookies.cookies || []).find((c) => c.name === 'lyraauth_session');
    const cookieOk = !!session && session.httpOnly === true;
    if (!cookieOk) failures++;
    log(cookieOk, '会话 Cookie 为 HttpOnly', session ? { httpOnly: session.httpOnly, sameSite: session.sameSite } : null);

    // 7. 登出
    await evaluate(ws, `document.querySelector('form[action="/logout"]').requestSubmit()`);
    await waitFor(ws, `location.pathname === '/login'`, 15000, '登出跳转');
    log(true, '登出后回到登录页');

    // 8. 登出后访问受保护页面
    await goto(ws, '/dashboard', null);
    await new Promise((r) => setTimeout(r, 500));
    const guarded = await evaluate(ws, `location.pathname`);
    const guardOk = guarded === '/login';
    if (!guardOk) failures++;
    log(guardOk, '未登录访问仪表盘被重定向', guarded);

    // 9. CSRF 缺失应被拒绝（用 fetch 避免整页导航）
    const csrfStatus = await evaluate(
        ws,
        `(async () => {
            const r = await fetch('/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' },
                body: 'identifier=x&password=y',
            });
            return r.status;
        })()`
    );
    const csrfOk = csrfStatus === 419;
    if (!csrfOk) failures++;
    log(csrfOk, '缺少 CSRF 令牌被拒绝', csrfStatus);

    // 10. 登录页视觉（桌面 + 移动）
    await goto(ws, '/login', 'input[name=identifier]');
    await shot(ws, '04-login-desktop');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await new Promise((r) => setTimeout(r, 350));
    const overflow = await evaluate(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`);
    await shot(ws, '05-login-mobile', 390, 844);
    const noOverflow = overflow <= 0;
    if (!noOverflow) failures++;
    log(noOverflow, '移动端无横向溢出', { overflow });

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\n全部断言通过。' : `\n有 ${failures} 项断言失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
