/**
 * Demo RP 完整流程验证（纯 HTTP，不依赖浏览器表单行为）。
 *
 * 覆盖：授权码 + PKCE → 令牌交换 → id_token 验签 → userinfo
 *       → 刷新令牌 → 单点登出。
 */

const IDP = process.env.IDP_BASE || 'http://192.168.8.211:9501';
const DEMO = process.env.DEMO_BASE || 'http://192.168.8.211:9502';
const EMAIL = process.env.DEMO_USER || 'oauthor@example.com';
const PASSWORD = process.env.DEMO_PASS || '';

class Jar {
    constructor() { this.cookies = new Map(); }
    absorb(response) {
        const raw = response.headers.getSetCookie ? response.headers.getSetCookie() : [];
        for (const line of raw) {
            const [pair] = line.split(';');
            const idx = pair.indexOf('=');
            if (idx > 0) this.cookies.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
        }
    }
    header() {
        return [...this.cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
    }
    get(name) { return this.cookies.get(name); }
}

const idpJar = new Jar();
const demoJar = new Jar();

let failures = 0;
const log = (ok, label, extra) => {
    console.log(`  ${ok ? '✓' : '✗'} ${label}${extra === undefined ? '' : ' ' + JSON.stringify(extra)}`);
    if (!ok) failures += 1;
};

const req = async (url, { method = 'GET', jar = null, body = null, headers = {} } = {}) => {
    const h = { Accept: 'text/html', ...headers };
    if (jar && jar.header()) h.Cookie = jar.header();
    if (body) h['Content-Type'] = 'application/x-www-form-urlencoded';

    const res = await fetch(url, { method, headers: h, body, redirect: 'manual' });
    if (jar) jar.absorb(res);
    return res;
};

const extractToken = (html) => {
    const m = html.match(/name="_token" value="([^"]+)"/);
    return m ? m[1] : '';
};

const main = async () => {
    // 1. Demo 发起登录
    const start = await req(`${DEMO}/login`, { jar: demoJar });
    const authorizeUrl = start.headers.get('location') || '';
    log(start.status === 302, 'Demo 发起登录并重定向', start.status);
    log(authorizeUrl.includes(`${IDP}/oauth/authorize`), '重定向到 IdP 授权端点');
    log(/code_challenge=[A-Za-z0-9_-]{20,}/.test(authorizeUrl), '包含 PKCE challenge');
    log(/code_challenge_method=S256/.test(authorizeUrl), '使用 S256');
    log(/state=[A-Za-z0-9_-]{10,}/.test(authorizeUrl), '包含 state');
    log(/nonce=[A-Za-z0-9_-]{10,}/.test(authorizeUrl), '包含 nonce');
    log(!!demoJar.get('demo_sid'), 'Demo 设置签名会话 Cookie');

    // 2. 访问授权端点（未登录 → 登录页）
    const authz = await req(authorizeUrl, { jar: idpJar });
    log(authz.status === 302 && (authz.headers.get('location') || '').includes('/login'), '未登录时跳转登录页');

    // 3. 登录 IdP
    const loginPage = await req(`${IDP}/login`, { jar: idpJar });
    const loginHtml = await loginPage.text();
    const loginToken = extractToken(loginHtml);
    log(!!loginToken, '登录页包含 CSRF 令牌');

    const loginRes = await req(`${IDP}/login`, {
        method: 'POST',
        jar: idpJar,
        body: new URLSearchParams({ _token: loginToken, identifier: EMAIL, password: PASSWORD }),
    });
    const afterLogin = loginRes.headers.get('location') || '';
    log(loginRes.status === 302, '登录成功并重定向', loginRes.status);
    log(afterLogin.includes('/oauth/authorize'), '登录后回到授权请求而不是默认页', afterLogin.slice(0, 50));

    // 4. 授权端点（已登录）
    const authz2 = await req(new URL(afterLogin, IDP).toString(), { jar: idpJar });
    let callbackUrl = '';

    if (authz2.status === 302) {
        // 已授权过，直接发码
        callbackUrl = authz2.headers.get('location') || '';
        log(true, '静默授权（此前已同意）');
    } else {
        const consentHtml = await authz2.text();
        log(consentHtml.includes('请求访问你的账号'), '展示同意页');
        log(consentHtml.includes('LyraAuth Demo'), '同意页显示应用名');
        const consentToken = extractToken(consentHtml);

        const allow = await req(`${IDP}/oauth/authorize`, {
            method: 'POST',
            jar: idpJar,
            body: new URLSearchParams({ _token: consentToken, decision: 'allow' }),
        });
        callbackUrl = allow.headers.get('location') || '';
        log(allow.status === 302, '同意后重定向回应用');
    }

    log(callbackUrl.startsWith(`${DEMO}/callback?code=`), '回跳携带授权码', callbackUrl.slice(0, 60));

    const codeState = new URL(callbackUrl);
    log(!!codeState.searchParams.get('code'), '授权码非空');
    log(!!codeState.searchParams.get('state'), '回传 state');

    // 5. Demo 回调换取令牌
    const callback = await req(callbackUrl, { jar: demoJar });
    log(callback.status === 302, 'Demo 处理回调并重定向', callback.status);

    const account = await req(`${DEMO}/`, { jar: demoJar });
    const accountHtml = await account.text();
    log(accountHtml.includes('id_token 验签通过'), 'id_token 签名校验通过');
    log(accountHtml.includes('at_hash 校验通过') || accountHtml.includes('与 access_token 绑定'), 'at_hash 校验通过');
    log(accountHtml.includes(EMAIL), '页面展示用户邮箱');
    log(accountHtml.includes('ID Token 声明'), '页面展示 ID Token 声明');
    log(accountHtml.includes('UserInfo'), '页面展示 userinfo');
    log(accountHtml.includes('refresh_token'), '页面展示刷新令牌状态');

    // 6. 刷新令牌
    const refresh = await req(`${DEMO}/refresh`, { method: 'POST', jar: demoJar, body: '' });
    log(refresh.status === 302, '刷新令牌请求成功', refresh.status);
    const afterRefresh = await (await req(`${DEMO}/`, { jar: demoJar })).text();
    log(afterRefresh.includes('已刷新过令牌'), '刷新后页面更新');

    // 7. 单点登出
    const logout = await req(`${DEMO}/logout`, { jar: demoJar });
    const logoutUrl = logout.headers.get('location') || '';
    log(logout.status === 302, 'Demo 跳转 IdP 登出');
    log(logoutUrl.includes('/oauth/logout'), '使用 end_session 端点');
    log(logoutUrl.includes('id_token_hint='), '携带 id_token_hint');
    log(logoutUrl.includes('post_logout_redirect_uri='), '携带回跳地址');

    const logoutPage = await req(logoutUrl, { jar: idpJar });
    const logoutHtml = await logoutPage.text();
    const logoutToken = extractToken(logoutHtml);
    log(logoutPage.status === 200 && logoutHtml.includes('退出登录'), '展示登出确认页');

    const doLogout = await req(`${IDP}/oauth/logout`, {
        method: 'POST',
        jar: idpJar,
        body: new URLSearchParams({
            _token: logoutToken,
            client_id: new URL(logoutUrl).searchParams.get('client_id') || '',
            post_logout_redirect_uri: new URL(logoutUrl).searchParams.get('post_logout_redirect_uri') || '',
            state: new URL(logoutUrl).searchParams.get('state') || '',
        }),
    });
    const backToDemo = doLogout.headers.get('location') || '';
    log(doLogout.status === 302, '提交登出');
    log(backToDemo.startsWith(`${DEMO}/`), '回跳到 Demo 首页', backToDemo.slice(0, 50));

    // 8. 登出后 IdP 会话应失效
    const dash = await req(`${IDP}/dashboard`, { jar: idpJar });
    log(dash.status === 302 && (dash.headers.get('location') || '').includes('/login'), 'IdP 会话已结束');

    // 9. Demo 会话也应清除
    const home = await (await req(`${DEMO}/`, { jar: demoJar })).text();
    log(home.includes('使用 LyraAuth 登录'), 'Demo 会话已清除');

    // 输出 demo_sid 供截图使用
    console.log('\nDEMO_SID=' + (demoJar.get('demo_sid') || ''));

    console.log(failures === 0 ? '\nDemo RP 端到端验证通过。' : `\n有 ${failures} 项失败。`);
    process.exit(failures === 0 ? 0 : 1);
};

main().catch((e) => {
    console.error('验证中断：', e.message);
    process.exit(1);
});
