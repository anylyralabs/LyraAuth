/**
 * Demo RP 端到端验证。
 *
 * 走完整流程：登录 → 授权同意 → 回调 → id_token 验签 → userinfo
 * → 刷新令牌 → 单点登出。
 */

import { writeFile, mkdir } from 'node:fs/promises';

const IDP = process.env.IDP_BASE || 'http://192.168.8.211:9501';
const DEMO = process.env.DEMO_BASE || 'http://192.168.8.211:9502';
const CDP = process.env.CDP_ENDPOINT || 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';
const EMAIL = process.env.DEMO_USER || 'oauthor@example.com';
const PASSWORD = process.env.DEMO_PASS || '';

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

const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    return ok ? 0 : 1;
};

const nav = async (ws, url) => {
    await send(ws, 'Page.navigate', { url });
    await new Promise((r) => setTimeout(r, 300));
};

const main = async () => {
    await mkdir(OUT, { recursive: true });
    let failures = 0;

    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.clearBrowserCookies');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });

    // 1. 打开 Demo 首页
    await nav(ws, `${DEMO}/`);
    await waitFor(ws, `document.querySelector('.hero') !== null`);
    const home = await ev(ws, `document.body.innerText`);
    failures += log(home.includes('一个真实接入 LyraAuth 的应用'), 'Demo 首页渲染');
    failures += log(home.includes('lyra_c6549') || home.includes('Client ID'), '显示接入参数');

    // 2. 点击登录，跳到 IdP（未登录时先到 /login，登录后再回授权端点）
    await ev(ws, `document.querySelector('a[href="/login"]').click()`);
    await waitFor(ws, `location.hostname === '192.168.8.211' && location.port === '9501'`, 20000);

    // 3. 视情况完成登录与同意，直到回到 Demo
    const onDemo = `location.port === '9502'`;
    for (let step = 0; step < 8; step += 1) {
        if (await ev(ws, onDemo)) break;

        const path = await ev(ws, 'location.pathname');

        if (path === '/login') {
            await waitFor(ws, `document.querySelector('input[name=identifier]') !== null`, 15000);

            // 先在页面内填值并滚动到提交按钮，避免点击坐标落在视口之外
            const box = await ev(ws, `(() => {
                const form = document.querySelector('form[action="/login"]');
                if (!form) return null;
                form.querySelector('[name=identifier]').value = ${JSON.stringify(EMAIL)};
                form.querySelector('[name=password]').value = ${JSON.stringify(PASSWORD)};
                const button = form.querySelector('button[type=submit]');
                if (!button) return null;
                button.scrollIntoView({ block: 'center' });
                const r = button.getBoundingClientRect();
                return { x: Math.round(r.x + r.width / 2), y: Math.round(r.y + r.height / 2), visible: r.width > 0 && r.height > 0 };
            })()`);

            if (!box || !box.visible) throw new Error('未找到可见的登录提交按钮');

            await send(ws, 'Input.dispatchMouseEvent', { type: 'mousePressed', x: box.x, y: box.y, button: 'left', clickCount: 1 });
            await send(ws, 'Input.dispatchMouseEvent', { type: 'mouseReleased', x: box.x, y: box.y, button: 'left', clickCount: 1 });

            // 兜底：若 3 秒后仍停留在登录页，改用编程式提交
            await new Promise((r) => setTimeout(r, 3000));
            if ((await ev(ws, 'location.pathname')) === '/login') {
                await ev(ws, `document.querySelector('form[action="/login"]').submit()`);
                failures += log(true, '登录按钮点击未生效，改用表单提交');
            }
            failures += log(true, '提交登录表单');

            await waitFor(ws, `location.pathname !== '/login'`, 25000);
            await new Promise((r) => setTimeout(r, 500));
            continue;
        }

        if (path === '/mfa') {
            throw new Error('测试账号不应启用 TOTP');
        }

        if (path === '/oauth/authorize') {
            const hasAllow = await ev(ws, `document.querySelector('form[action="/oauth/authorize"] input[value=allow]') !== null`);
            if (hasAllow) {
                const consentText = await ev(ws, 'document.body.innerText');
                failures += log(consentText.includes('请求访问你的账号'), '展示同意页');
                failures += log(consentText.includes('LyraAuth Demo'), '同意页显示应用名');
                await ev(ws, `document.querySelector('form[action="/oauth/authorize"] input[value=allow]').closest('form').requestSubmit()`);
                await new Promise((r) => setTimeout(r, 1800));
                continue;
            }
        }

        await new Promise((r) => setTimeout(r, 1200));
    }

    if (!(await ev(ws, onDemo))) {
        throw new Error('未能回到 Demo 应用，当前地址：' + (await ev(ws, 'location.href')));
    }

    // 4. 回到 Demo 并展示资料
    await waitFor(ws, `document.querySelector('.profile') !== null || document.querySelector('pre.json') !== null`, 20000);
    await new Promise((r) => setTimeout(r, 600));

    const body = await ev(ws, `document.body.innerText`);
    failures += log(ws && body.includes('id_token 验签通过'), 'id_token 验签通过');
    failures += log(body.includes('at_hash 校验') && body.includes('通过'), 'at_hash 校验通过');
    failures += log(body.includes('nonce 校验'), '展示 nonce 校验');
    failures += log(body.includes('oauthor@example.com'), '展示用户邮箱');
    failures += log(body.includes('ID Token 声明'), '展示 ID Token 声明');
    failures += log(body.includes('UserInfo'), '展示 UserInfo');
    failures += log(body.includes('access_token'), '展示令牌信息');

    const claimsText = await ev(ws, `document.querySelector('.grid-2 .card')?.innerText || ''`);
    failures += log(claimsText.includes('sub'), '声明中包含 sub');
    failures += log(claimsText.includes('sid'), '声明中包含 sid');

    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 1400, deviceScaleFactor: 1, mobile: false });
    await ev(ws, 'window.scrollTo(0,0)');
    await new Promise((r) => setTimeout(r, 400));
    let shot = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/21-demo-account.png`, Buffer.from(shot.data, 'base64'));

    // 6. 刷新令牌
    await ev(ws, `document.querySelector('form[action="/refresh"] button').click()`);
    await new Promise((r) => setTimeout(r, 2500));
    const afterRefresh = await ev(ws, `document.body.innerText`);
    failures += log(afterRefresh.includes('已刷新过令牌'), '刷新令牌成功');

    // 7. 重新调用 userinfo
    await ev(ws, `document.querySelector('form[action="/reload-userinfo"] button').click()`);
    await new Promise((r) => setTimeout(r, 2000));
    const afterUserinfo = await ev(ws, `document.body.innerText`);
    failures += log(afterUserinfo.includes('oauthor@example.com'), '重新调用 userinfo 成功');

    // 8. 单点登出
    await ev(ws, `document.querySelector('a[href="/logout"]').click()`);
    await waitFor(ws, `location.port === '9501'`, 20000);
    const logoutPage = await ev(ws, `document.body.innerText`);
    failures += log(logoutPage.includes('退出登录'), '跳转到 IdP 登出页');

    const confirmBtn = await ev(ws, `document.querySelector('form[action="/oauth/logout"] button') !== null`);
    if (confirmBtn) {
        await ev(ws, `document.querySelector('form[action="/oauth/logout"]').requestSubmit()`);
        await new Promise((r) => setTimeout(r, 2500));
    }

    // 9. 回跳到 Demo，会话应已清空
    await waitFor(ws, `location.port === '9502'`, 20000);
    await new Promise((r) => setTimeout(r, 800));
    const afterLogout = await ev(ws, `document.body.innerText`);
    failures += log(afterLogout.includes('使用 LyraAuth 登录'), '登出后 Demo 会话已清除');

    // 10. IdP 会话也应结束
    await nav(ws, `${IDP}/dashboard`);
    await new Promise((r) => setTimeout(r, 1200));
    const idpAfterLogout = await ev(ws, 'location.pathname');
    failures += log(idpAfterLogout === '/login', 'IdP 会话已结束', idpAfterLogout);

    // 移动端截图
    await nav(ws, `${DEMO}/`);
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await new Promise((r) => setTimeout(r, 500));
    const overflow = await ev(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`);
    failures += log(overflow <= 0, '移动端无横向溢出', { overflow });
    shot = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true });
    await writeFile(`${OUT}/22-demo-mobile.png`, Buffer.from(shot.data, 'base64'));

    await send(ws, 'Page.close').catch(() => {});
    ws.close();

    console.log(failures === 0 ? '\nDemo RP 端到端验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
