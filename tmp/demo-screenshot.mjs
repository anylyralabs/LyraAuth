/**
 * Demo 截图与首次授权验证。
 *
 * 先清除同意记录，确保出现同意页；通过 HTTP 完成登录与授权，
 * 再把会话 Cookie 注入 Chromium 截图（避免自动化表单点击的不确定性）。
 */

import { writeFile, mkdir } from 'node:fs/promises';
import { execSync } from 'node:child_process';

const IDP = process.env.IDP_BASE || 'http://192.168.8.211:9501';
const DEMO = process.env.DEMO_BASE || 'http://192.168.8.211:9502';
const CDP = 'http://127.0.0.1:9222';
const OUT = process.argv[2] || '/tmp/opencode/shots';
const EMAIL = process.env.DEMO_USER || 'oauthor@example.com';
const PASSWORD = process.env.DEMO_PASS || '';
if (!PASSWORD) throw new Error('请通过 DEMO_PASS 提供演示账号密码');
const CLIENT_ID = process.env.DEMO_CLIENT_ID || 'lyra_c6549addd7745b4ff6f66398';

class Jar {
    constructor() { this.cookies = new Map(); }
    absorb(r) {
        for (const line of (r.headers.getSetCookie ? r.headers.getSetCookie() : [])) {
            const [pair] = line.split(';');
            const i = pair.indexOf('=');
            if (i > 0) this.cookies.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
        }
    }
    header() { return [...this.cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; '); }
    get(n) { return this.cookies.get(n); }
}

const jarA = new Jar();
const jarD = new Jar();

const req = async (url, { method = 'GET', jar = null, body = null } = {}) => {
    const headers = { Accept: 'text/html' };
    if (jar?.header()) headers.Cookie = jar.header();
    if (body) headers['Content-Type'] = 'application/x-www-form-urlencoded';
    const res = await fetch(url, { method, headers, body, redirect: 'manual' });
    if (jar) jar.absorb(res);
    return res;
};
const tokenOf = (html) => (html.match(/name="_token" value="([^"]+)"/) || [])[1] || '';

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
    if (r.exceptionDetails) throw new Error('脚本异常');
    return r.result.value;
};

const shot = async (ws, name, full = false) => {
    await new Promise((r) => setTimeout(r, 600));
    const data = await send(ws, 'Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: full });
    await writeFile(`${OUT}/${name}.png`, Buffer.from(data.data, 'base64'));
};

const main = async () => {
    await mkdir(OUT, { recursive: true });

    // 清除同意记录，强制出现同意页
    try {
        const pass = execSync("grep '^DB_PASSWORD=' /home/YanSui/Project/LyraAuth/.env | cut -d= -f2", { encoding: 'utf8' }).trim();
        const sql = `DELETE c FROM oauth_consents c JOIN oauth_clients cl ON cl.id=c.client_id WHERE cl.client_id='${CLIENT_ID}';`;
        execSync(`mysql -h127.0.0.1 -ulyraauth -p'${pass}' lyraauth -e ${JSON.stringify(sql)}`, { stdio: 'pipe' });
    } catch (e) {
        console.log('  ! 清除同意记录失败：', String(e.message).slice(0, 80));
    }

    // 1. Demo 发起登录
    const start = await req(`${DEMO}/login`, { jar: jarD });
    const authorizeUrl = start.headers.get('location');

    // 1.1 先以未登录状态访问授权端点，让 IdP 记住登录后要回到这里
    const firstHit = await req(authorizeUrl, { jar: jarA });
    if (firstHit.status !== 302) {
        console.log('  ! 授权端点在未登录时未重定向，状态：', firstHit.status);
    }

    // 2. IdP 登录
    const loginPage = await req(`${IDP}/login`, { jar: jarA });
    const t1 = tokenOf(await loginPage.text());
    const login = await req(`${IDP}/login`, {
        method: 'POST', jar: jarA,
        body: new URLSearchParams({ _token: t1, identifier: EMAIL, password: PASSWORD }),
    });
    const authorizeUrl2 = new URL(login.headers.get('location'), IDP).toString();

    // 3. 取同意页（不提交）
    const consent = await req(authorizeUrl2, { jar: jarA });
    const consentHtml = await consent.text();
    const isConsent = consentHtml.includes('请求访问你的账号');
    console.log(isConsent ? '  ✓ 首次授权出现同意页' : '  ! 未出现同意页（可能有残留同意记录）');

    const idpSid = jarA.get('lyraauth_session');
    const demoSid = jarD.get('demo_sid');

    // 4. 浏览器截图：同意页
    const page = await (await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' })).json();
    const ws = await connect(page.webSocketDebuggerUrl);
    await send(ws, 'Page.enable');
    await send(ws, 'Runtime.enable');
    await send(ws, 'Network.enable');
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await send(ws, 'Network.clearBrowserCookies');

    if (idpSid) {
        await send(ws, 'Network.setCookie', { name: 'lyraauth_session', value: idpSid, domain: '192.168.8.211', path: '/' });
    }

    if (isConsent) {
        await send(ws, 'Page.navigate', { url: authorizeUrl2 });
        await new Promise((r) => setTimeout(r, 2000));
        await shot(ws, '23-demo-consent');
        console.log('  ✓ 同意页截图完成');
    }

    // 5. 完成授权：若已静默发码则直接使用，否则提交同意
    let callbackUrl = '';
    if (consent.status === 302) {
        callbackUrl = consent.headers.get('location') || '';
    } else {
        const t2 = tokenOf(consentHtml);
        const allow = await req(`${IDP}/oauth/authorize`, {
            method: 'POST', jar: jarA,
            body: new URLSearchParams({ _token: t2, decision: 'allow' }),
        });
        callbackUrl = allow.headers.get('location') || '';
    }

    if (!callbackUrl) {
        throw new Error('未取得授权码回跳地址');
    }
    await req(callbackUrl, { jar: jarD });

    // 6. 注入 Demo 会话并截图账号页
    if (demoSid) {
        await send(ws, 'Network.setCookie', { name: 'demo_sid', value: demoSid, domain: '192.168.8.211', path: '/' });
    }
    await send(ws, 'Page.navigate', { url: `${DEMO}/` });
    await new Promise((r) => setTimeout(r, 2500));
    await shot(ws, '24-demo-account', true);
    const text = await ev(ws, `document.body.innerText`);
    if (!text.includes('id_token 验签通过')) {
        console.log('  ! 账号页内容：', text.replace(/\s+/g, ' ').slice(0, 160));
        const ck = (await send(ws, 'Network.getCookies', { urls: [`${DEMO}/`] })).cookies;
        console.log('  ! 浏览器 Cookie：', ck.map((c) => c.name).join(', ') || '(无)');
        console.log('  ! Demo 会话 Cookie 是否为空：', !demoSid);
    } else {
        console.log('  ✓ 账号页显示验签通过');
    }

    // 7. 移动端
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await new Promise((r) => setTimeout(r, 600));
    const overflow = await ev(ws, `document.documentElement.scrollWidth - document.documentElement.clientWidth`);
    await shot(ws, '25-demo-mobile');
    console.log(overflow <= 0 ? '  ✓ 移动端无横向溢出' : `  ✗ 移动端横向溢出 ${overflow}px`);

    // 8. 首页截图
    await send(ws, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await send(ws, 'Network.clearBrowserCookies');
    await send(ws, 'Page.navigate', { url: `${DEMO}/` });
    await new Promise((r) => setTimeout(r, 1500));
    await shot(ws, '26-demo-home');

    await send(ws, 'Page.close').catch(() => {});
    ws.close();
};

main().catch((e) => {
    console.error('截图失败：', e.message);
    process.exit(1);
});
