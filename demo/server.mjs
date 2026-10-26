/**
 * LyraAuth Demo RP —— 一个最小的 OpenID Connect 客户端。
 *
 * 目的：用真实代码演示第三方应用如何接入 LyraAuth，覆盖：
 *   1. 授权码 + PKCE（S256）
 *   2. id_token 的 RS256 验签（使用 JWKS 公钥）
 *   3. 校验 iss / aud / exp / nonce / at_hash
 *   4. 用 access_token 调用 userinfo
 *   5. RP 发起的单点登出
 *
 * 零依赖：只用 Node 内置的 http / crypto / fetch。
 *
 * 运行：
 *   cd demo && node server.mjs
 *   浏览器打开 http://192.168.8.211:9502
 *
 * 注意：会话存在内存里，重启即失效；这是演示程序，不用于生产。
 */

import { createServer } from 'node:http';
import { createHash, createHmac, randomBytes, timingSafeEqual, createPublicKey, verify } from 'node:crypto';

// ------------------------------------------------------------------ 配置

const CONFIG = {
    port: Number(process.env.DEMO_PORT || 9502),
    host: process.env.DEMO_HOST || '0.0.0.0',
    // 本应用的对外地址，必须与客户端注册时的 redirect_uri 前缀一致
    baseUrl: (process.env.DEMO_BASE_URL || 'http://192.168.8.211:9502').replace(/\/$/, ''),
    // IdP 地址
    issuer: (process.env.DEMO_ISSUER || 'http://192.168.8.211:9501').replace(/\/$/, ''),
    clientId: process.env.DEMO_CLIENT_ID || '',
    clientSecret: process.env.DEMO_CLIENT_SECRET || '',
    scopes: process.env.DEMO_SCOPES || 'openid profile email',
    // 仅演示用；生产应由环境变量注入并以安全方式保存
    sessionSecret: process.env.DEMO_SESSION_SECRET || 'demo-session-secret-change-me',
};

if (!CONFIG.clientId) {
    console.error('缺少 DEMO_CLIENT_ID。请先设置环境变量，或在 demo/.env 中填写。');
    process.exit(1);
}

const REDIRECT_URI = `${CONFIG.baseUrl}/callback`;
const POST_LOGOUT_URI = `${CONFIG.baseUrl}/`;

/** 内存会话：sessionId -> { data, expiresAt } */
const SESSIONS = new Map();
const SESSION_TTL = 60 * 60 * 1000;

// ------------------------------------------------------------ 会话与工具

const now = () => Date.now();

const createSession = (data) => {
    const id = randomBytes(24).toString('base64url');
    SESSIONS.set(id, { data, expiresAt: now() + SESSION_TTL });
    return id;
};

const readSession = (id) => {
    if (!id) return null;
    const entry = SESSIONS.get(id);
    if (!entry) return null;
    if (entry.expiresAt < now()) {
        SESSIONS.delete(id);
        return null;
    }
    return entry.data;
};

const updateSession = (id, data) => {
    const entry = SESSIONS.get(id);
    if (entry) {
        entry.data = { ...entry.data, ...data };
        entry.expiresAt = now() + SESSION_TTL;
    }
};

const destroySession = (id) => {
    if (id) SESSIONS.delete(id);
};

/** 签名 cookie：`sid.hmac`，防止伪造会话 ID */
const signSessionId = (id) => {
    const mac = createHmac('sha256', CONFIG.sessionSecret).update(id).digest('base64url');
    return `${id}.${mac}`;
};

const verifySignedSessionId = (value) => {
    if (typeof value !== 'string' || !value.includes('.')) return null;
    const index = value.lastIndexOf('.');
    const id = value.slice(0, index);
    const mac = value.slice(index + 1);
    const expected = createHmac('sha256', CONFIG.sessionSecret).update(id).digest('base64url');

    if (mac.length !== expected.length) return null;
    return timingSafeEqual(Buffer.from(mac), Buffer.from(expected)) ? id : null;
};

const parseCookies = (header) => {
    const out = {};
    if (!header) return out;
    for (const part of header.split(';')) {
        const index = part.indexOf('=');
        if (index === -1) continue;
        out[part.slice(0, index).trim()] = decodeURIComponent(part.slice(index + 1).trim());
    }
    return out;
};

/** 只有拿到了令牌才算完成登录；刚发起授权的会话仍视为未登录 */
const isAuthed = (session) => Boolean(session && session.tokens && session.tokens.access_token);

const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const b64url = (buf) => Buffer.from(buf).toString('base64')
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

const decodeSegment = (segment) => {
    const normalized = String(segment).replace(/-/g, '+').replace(/_/g, '/');
    return Buffer.from(normalized + '='.repeat((4 - (normalized.length % 4)) % 4), 'base64');
};

const send = (res, status, body, headers = {}) => {
    res.writeHead(status, {
        'Content-Type': 'text/html; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-Content-Type-Options': 'nosniff',
        'X-Frame-Options': 'DENY',
        'Referrer-Policy': 'strict-origin-when-cross-origin',
        'Content-Security-Policy': "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; form-action 'self' http://192.168.8.211:9501; frame-ancestors 'none'",
        ...headers,
    });
    res.end(body);
};

const redirect = (res, location, headers = {}) => {
    res.writeHead(302, { Location: location, 'Cache-Control': 'no-store', ...headers });
    res.end();
};

const cookieHeader = (res, id) => {
    const secure = CONFIG.baseUrl.startsWith('https') ? '; Secure' : '';
    res.setHeader('Set-Cookie', `demo_sid=${signSessionId(id)}; Path=/; HttpOnly; SameSite=Lax; Max-Age=3600${secure}`);
};

const clearCookie = (res) => {
    res.setHeader('Set-Cookie', 'demo_sid=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0');
};

// --------------------------------------------------------------- JWKS 缓存

let jwksCache = { keys: [], fetchedAt: 0 };

const getJwks = async () => {
    if (jwksCache.keys.length > 0 && now() - jwksCache.fetchedAt < 5 * 60 * 1000) {
        return jwksCache.keys;
    }
    const res = await fetch(`${CONFIG.issuer}/.well-known/jwks.json`);
    if (!res.ok) throw new Error(`获取 JWKS 失败：HTTP ${res.status}`);
    const data = await res.json();
    jwksCache = { keys: data.keys || [], fetchedAt: now() };
    return jwksCache.keys;
};

/**
 * 验证 id_token。
 *
 * 严格按 OIDC Core 的要求检查签名与声明，任何一项不通过都视为失败。
 */
const verifyIdToken = async (idToken, expectedNonce) => {
    const parts = String(idToken).split('.');
    if (parts.length !== 3) return { ok: false, reason: '格式不是三段式 JWT' };

    const [headerB64, payloadB64, signatureB64] = parts;
    const header = JSON.parse(decodeSegment(headerB64).toString('utf8'));
    const claims = JSON.parse(decodeSegment(payloadB64).toString('utf8'));

    if (header.alg !== 'RS256') {
        return { ok: false, reason: `不支持的签名算法：${header.alg}`, claims, header };
    }

    const keys = await getJwks();
    const jwk = keys.find((k) => k.kid === header.kid);
    if (!jwk) {
        return { ok: false, reason: `JWKS 中找不到 kid=${header.kid} 的公钥`, claims, header };
    }

    const publicKey = createPublicKey({ key: jwk, format: 'jwk' });
    const signatureOk = verify(
        'RSA-SHA256',
        Buffer.from(`${headerB64}.${payloadB64}`),
        publicKey,
        decodeSegment(signatureB64)
    );

    if (!signatureOk) {
        return { ok: false, reason: '签名校验失败', claims, header };
    }

    const problems = [];
    if (claims.iss !== CONFIG.issuer) problems.push(`iss 不匹配（期望 ${CONFIG.issuer}）`);
    if (claims.aud !== CONFIG.clientId) problems.push('aud 不是本应用');
    if (!claims.exp || claims.exp * 1000 < now()) problems.push('id_token 已过期');
    if (expectedNonce && claims.nonce !== expectedNonce) problems.push('nonce 不匹配');

    return {
        ok: problems.length === 0,
        reason: problems.join('；'),
        claims,
        header,
        signatureOk,
    };
};

// --------------------------------------------------------------- 页面渲染

const layout = (title, body) => `<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)} · LyraAuth Demo</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/">
    <span class="brand-mark">L</span>
    <span class="brand-text">LyraAuth Demo</span>
  </a>
  <span class="badge-neutral">OIDC 客户端</span>
</header>
<main class="page">
${body}
</main>
<footer class="footer">
  <span>演示应用 · 数据仅存于内存，重启即清空</span>
  <span class="mono">${esc(CONFIG.issuer)}</span>
</footer>
</body>
</html>`;

const homePage = () => layout('首页', `
<section class="hero">
  <p class="eyebrow">OpenID Connect 演示</p>
  <h1>一个真实接入 LyraAuth 的应用</h1>
  <p class="lede">
    这个页面是一个独立的 Web 应用，通过授权码 + PKCE 登录，验证 id_token 签名，
    再调用 userinfo 读取资料。它和 LyraAuth 之间只依赖标准协议。
  </p>
  <div class="actions">
    <a class="btn-primary" href="/login">使用 LyraAuth 登录</a>
    <a class="btn-secondary" href="/flow">查看流程说明</a>
  </div>
</section>

<section class="grid-3">
  <div class="card">
    <h2>1. 授权</h2>
    <p>跳转到 LyraAuth，携带 client_id、redirect_uri、scope 与 PKCE challenge。</p>
  </div>
  <div class="card">
    <h2>2. 换令牌</h2>
    <p>用授权码和 code_verifier 换取 access_token、id_token、refresh_token。</p>
  </div>
  <div class="card">
    <h2>3. 验签</h2>
    <p>从 JWKS 取公钥，校验 id_token 的 RS256 签名与 iss / aud / nonce。</p>
  </div>
</section>

<section class="card muted">
  <h2>接入参数</h2>
  <dl class="kv">
    <dt>Issuer</dt><dd class="mono">${esc(CONFIG.issuer)}</dd>
    <dt>Client ID</dt><dd class="mono">${esc(CONFIG.clientId)}</dd>
    <dt>Redirect URI</dt><dd class="mono">${esc(REDIRECT_URI)}</dd>
    <dt>Scope</dt><dd class="mono">${esc(CONFIG.scopes)}</dd>
  </dl>
</section>
`);

const flowPage = () => layout('流程说明', `
<section class="card">
  <h1>授权码 + PKCE 流程</h1>
  <ol class="steps">
    <li>
      <strong>生成 PKCE 与 state</strong>
      <p>随机生成 code_verifier，计算 <code>challenge = BASE64URL(SHA256(verifier))</code>；
      同时生成 state 与 nonce，前者防 CSRF，后者会写进 id_token。</p>
    </li>
    <li>
      <strong>跳转授权端点</strong>
      <p>GET <code class="mono">/oauth/authorize?response_type=code&amp;client_id=...&amp;code_challenge=...</code>。
      LyraAuth 校验 client 与 redirect_uri，展示登录页与同意页。</p>
    </li>
    <li>
      <strong>回调换取令牌</strong>
      <p>本应用收到 <code>code</code> 后校验 state，再 POST 到 <code>/oauth/token</code>，
      附上 code_verifier 与客户端密钥。</p>
    </li>
    <li>
      <strong>验证 id_token</strong>
      <p>用 JWKS 里的公钥验签，检查 iss、aud、exp、nonce，并核对 at_hash 与 access_token 的绑定关系。</p>
    </li>
    <li>
      <strong>调用 userinfo</strong>
      <p>带上 <code>Authorization: Bearer &lt;access_token&gt;</code> 读取用户资料。</p>
    </li>
  </ol>
  <a class="btn-secondary" href="/">返回首页</a>
</section>
`);

const accountPage = (session) => {
    // 防御性取值：任何字段缺失都不应让整页崩掉
    const tokens = session.tokens || {};
    const claims = session.claims || {};
    const verification = session.verification || { ok: false, reason: '尚未完成授权' };
    const header = verification.header || {};
    const userinfo = session.userinfo || {};
    const flow = session.flow || {};
    const atHashOk = session.atHashOk === true;

    const scopeList = String(claims.scope || tokens.scope || '').split(' ').filter(Boolean);
    const displayName = userinfo.name || claims.name || '已登录用户';
    const email = userinfo.email || claims.email || '';
    const username = userinfo.preferred_username || claims.preferred_username || '';
    const emailVerified = userinfo.email_verified === true || claims.email_verified === true;

    return layout('我的账号', `
<section class="profile">
  <div class="avatar">${esc(String(displayName).slice(0, 1))}</div>
  <div class="profile-main">
    <h1>${esc(displayName)}</h1>
    <p class="mono">${esc(email)}</p>
    <div class="chips">
      ${emailVerified ? '<span class="chip-ok">邮箱已验证</span>' : ''}
      ${username ? `<span class="chip">${esc(username)}</span>` : ''}
    </div>
  </div>
  <a class="btn-secondary" href="/logout">退出登录</a>
</section>

<section class="card ${verification.ok ? 'ok' : 'danger'}">
  <h2>${verification.ok ? 'id_token 验签通过' : 'id_token 校验失败'}</h2>
  <p>${verification.ok
      ? '签名与关键声明均符合 OIDC 规范，说明令牌确实由 LyraAuth 签发且未被篡改。'
      : esc(verification.reason || '未知原因')}</p>
  <dl class="kv">
    <dt>算法</dt><dd class="mono">${esc(header.alg || '—')}</dd>
    <dt>kid</dt><dd class="mono">${esc(header.kid || '—')}</dd>
    <dt>nonce 校验</dt><dd>${claims.nonce && claims.nonce === session.nonce ? '通过' : '不匹配'}</dd>
    <dt>at_hash 校验</dt><dd>${atHashOk ? '通过（与 access_token 绑定）' : '失败'}</dd>
  </dl>
</section>

<section class="grid-2">
  <div class="card">
    <h2>ID Token 声明</h2>
    <dl class="kv">
      ${Object.entries(claims)
          .filter(([k]) => k !== 'at_hash')
          .map(([k, v]) => `<dt class="mono">${esc(k)}</dt><dd class="mono">${esc(Array.isArray(v) ? v.join(', ') : v)}</dd>`)
          .join('')}
    </dl>
  </div>
  <div class="card">
    <h2>UserInfo</h2>
    <pre class="json">${esc(JSON.stringify(userinfo, null, 2))}</pre>
  </div>
</section>

<section class="card">
  <h2>令牌</h2>
  <dl class="kv">
    <dt>access_token</dt><dd class="mono ellipsis">${esc(tokens.access_token || '')}</dd>
    <dt>token_type</dt><dd class="mono">${esc(tokens.token_type || '')}</dd>
    <dt>expires_in</dt><dd class="mono">${esc(tokens.expires_in ?? '')} 秒</dd>
    <dt>scope</dt><dd class="mono">${esc(scopeList.join(' '))}</dd>
    <dt>refresh_token</dt><dd class="mono">${tokens.refresh_token ? '已获取（每次刷新会轮换）' : '未获取'}</dd>
  </dl>
  <div class="actions">
    <form method="POST" action="/refresh"><button class="btn-secondary" type="submit">刷新令牌</button></form>
    <form method="POST" action="/reload-userinfo"><button class="btn-secondary" type="submit">重新调用 userinfo</button></form>
  </div>
  ${flow.refreshed ? '<p class="hint">已刷新过令牌：旧 refresh_token 已被服务端吊销，重放会被拒绝。</p>' : ''}
</section>
`);
};

const errorPage = (title, message) => layout(title, `
<section class="card danger">
  <h1>${esc(title)}</h1>
  <p>${esc(message)}</p>
  <a class="btn-secondary" href="/">返回首页</a>
</section>
`);

// ----------------------------------------------------------------- 路由

/** 计算 at_hash：SHA-256 左半 + base64url */
const computeAtHash = (accessToken) => b64url(createHash('sha256').update(accessToken).digest().subarray(0, 16));

const exchangeCode = async (code, verifier) => {
    const body = new URLSearchParams({
        grant_type: 'authorization_code',
        code,
        redirect_uri: REDIRECT_URI,
        code_verifier: verifier,
        client_id: CONFIG.clientId,
    });

    const headers = { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' };
    if (CONFIG.clientSecret) {
        headers.Authorization = 'Basic ' + Buffer.from(`${CONFIG.clientId}:${CONFIG.clientSecret}`).toString('base64');
    }

    const res = await fetch(`${CONFIG.issuer}/oauth/token`, { method: 'POST', headers, body });
    const text = await res.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch {
        throw new Error(`令牌端点返回非 JSON（HTTP ${res.status}）：${text.slice(0, 200)}`);
    }
    if (!res.ok) {
        throw new Error(`令牌交换失败：${json.error || res.status} ${json.error_description || ''}`);
    }
    return json;
};

const fetchUserinfo = async (accessToken) => {
    const res = await fetch(`${CONFIG.issuer}/userinfo`, {
        headers: { Authorization: `Bearer ${accessToken}` },
    });
    const text = await res.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch {
        throw new Error(`userinfo 返回非 JSON（HTTP ${res.status}）`);
    }
    if (!res.ok) {
        throw new Error(`userinfo 调用失败：${json.error || res.status}`);
    }
    return json;
};

const handle = async (req, res) => {
    const url = new URL(req.url, CONFIG.baseUrl);
    const cookies = parseCookies(req.headers.cookie);
    const sessionId = verifySignedSessionId(cookies.demo_sid);
    const session = readSession(sessionId);

    // 静态样式
    if (url.pathname === '/assets/app.css') {
        const css = await readCss();
        res.writeHead(200, { 'Content-Type': 'text/css; charset=utf-8', 'Cache-Control': 'public, max-age=300' });
        res.end(css);
        return;
    }

    // 浏览器会自动请求 favicon，返回一个内联 SVG，避免 404 日志噪音
    if (url.pathname === '/favicon.ico' || url.pathname === '/favicon.svg') {
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">`
            + `<rect width="32" height="32" rx="8" fill="#2F4D5E"/>`
            + `<text x="16" y="22" font-family="sans-serif" font-size="18" font-weight="600" fill="#fff" text-anchor="middle">L</text></svg>`;
        res.writeHead(200, { 'Content-Type': 'image/svg+xml; charset=utf-8', 'Cache-Control': 'public, max-age=86400' });
        res.end(svg);
        return;
    }

    if (url.pathname === '/healthz') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, issuer: CONFIG.issuer }));
        return;
    }

    if (url.pathname === '/' && req.method === 'GET') {
        send(res, 200, isAuthed(session) ? accountPage(session) : homePage());
        return;
    }

    if (url.pathname === '/flow') {
        send(res, 200, flowPage());
        return;
    }

    // 第一步：跳转授权
    if (url.pathname === '/login') {
        const verifier = b64url(randomBytes(48));
        const challenge = b64url(createHash('sha256').update(verifier).digest());
        const state = b64url(randomBytes(16));
        const nonce = b64url(randomBytes(16));

        if (sessionId) destroySession(sessionId);
        const id = createSession({ verifier, state, nonce });
        cookieHeader(res, id);

        const authorize = new URL(`${CONFIG.issuer}/oauth/authorize`);
        authorize.searchParams.set('response_type', 'code');
        authorize.searchParams.set('client_id', CONFIG.clientId);
        authorize.searchParams.set('redirect_uri', REDIRECT_URI);
        authorize.searchParams.set('scope', CONFIG.scopes);
        authorize.searchParams.set('state', state);
        authorize.searchParams.set('nonce', nonce);
        authorize.searchParams.set('code_challenge', challenge);
        authorize.searchParams.set('code_challenge_method', 'S256');

        redirect(res, authorize.toString());
        return;
    }

    // 第二步：处理回调
    if (url.pathname === '/callback') {
        const error = url.searchParams.get('error');
        if (error) {
            send(res, 400, errorPage('授权被拒绝', `${error}：${url.searchParams.get('error_description') || ''}`));
            return;
        }

        const code = url.searchParams.get('code');
        const state = url.searchParams.get('state');

        if (!session || !code) {
            send(res, 400, errorPage('回调无效', '缺少会话或授权码，请重新登录。'));
            return;
        }
        if (state !== session.state) {
            send(res, 400, errorPage('state 不匹配', '可能是 CSRF 攻击或页面已过期，请重新登录。'));
            return;
        }

        try {
            const tokens = await exchangeCode(code, session.verifier);
            const verification = await verifyIdToken(tokens.id_token, session.nonce);
            const claims = verification.claims || {};
            const userinfo = await fetchUserinfo(tokens.access_token);
            const atHashOk = claims.at_hash ? claims.at_hash === computeAtHash(tokens.access_token) : true;

            updateSession(sessionId, {
                tokens,
                claims,
                verification: {
                    ok: verification.ok,
                    reason: verification.reason,
                    header: verification.header,
                },
                userinfo,
                atHashOk,
                flow: { refreshed: false },
                state: undefined,
                verifier: undefined,
            });

            redirect(res, '/');
        } catch (e) {
            send(res, 500, errorPage('登录失败', e.message));
        }
        return;
    }

    // 刷新令牌
    if (url.pathname === '/refresh' && req.method === 'POST') {
        if (!isAuthed(session) || !session.tokens.refresh_token) {
            send(res, 400, errorPage('无法刷新', '当前会话没有 refresh_token。'));
            return;
        }

        try {
            const body = new URLSearchParams({
                grant_type: 'refresh_token',
                refresh_token: session.tokens.refresh_token,
                client_id: CONFIG.clientId,
            });
            const headers = { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' };
            if (CONFIG.clientSecret) {
                headers.Authorization = 'Basic ' + Buffer.from(`${CONFIG.clientId}:${CONFIG.clientSecret}`).toString('base64');
            }

            const res2 = await fetch(`${CONFIG.issuer}/oauth/token`, { method: 'POST', headers, body });
            const json = await res2.json();
            if (!res2.ok) throw new Error(json.error_description || json.error || '刷新失败');

            updateSession(sessionId, {
                tokens: json,
                claims: session.claims,
                flow: { ...session.flow, refreshed: true },
            });

            redirect(res, '/');
        } catch (e) {
            send(res, 500, errorPage('刷新失败', e.message));
        }
        return;
    }

    // 重新调用 userinfo
    if (url.pathname === '/reload-userinfo' && req.method === 'POST') {
        if (!isAuthed(session)) {
            send(res, 400, errorPage('尚未登录', '请先完成登录再调用 userinfo。'));
            return;
        }
        try {
            const userinfo = await fetchUserinfo(session.tokens.access_token);
            updateSession(sessionId, { userinfo });
            redirect(res, '/');
        } catch (e) {
            send(res, 500, errorPage('调用 userinfo 失败', e.message));
        }
        return;
    }

    // 单点登出
    if (url.pathname === '/logout') {
        if (!isAuthed(session)) {
            if (sessionId) destroySession(sessionId);
            clearCookie(res);
            redirect(res, '/');
            return;
        }

        const logout = new URL(`${CONFIG.issuer}/oauth/logout`);
        logout.searchParams.set('client_id', CONFIG.clientId);
        logout.searchParams.set('post_logout_redirect_uri', POST_LOGOUT_URI);
        logout.searchParams.set('state', b64url(randomBytes(8)));
        if (session.tokens?.id_token) {
            logout.searchParams.set('id_token_hint', session.tokens.id_token);
        }

        destroySession(sessionId);
        clearCookie(res);
        redirect(res, logout.toString());
        return;
    }

    send(res, 404, errorPage('页面不存在', '这个地址没有对应的页面。'));
};

// -------------------------------------------------------------- 静态资源

let cssCache = null;

const readCss = async () => {
    if (cssCache !== null) return cssCache;
    const { readFile } = await import('node:fs/promises');
    const { fileURLToPath } = await import('node:url');
    const { dirname, join } = await import('node:path');
    const here = dirname(fileURLToPath(import.meta.url));
    cssCache = await readFile(join(here, 'style.css'), 'utf8');
    return cssCache;
};

// ---------------------------------------------------------------- 启动

createServer((req, res) => {
    const started = Date.now();
    res.on('finish', () => {
        console.log(`[demo] ${req.method} ${req.url.split('?')[0]} -> ${res.statusCode} (${Date.now() - started}ms)`);
    });

    handle(req, res).catch((e) => {
        console.error('[demo] 未捕获异常:', e);
        if (!res.headersSent) {
            send(res, 500, errorPage('服务异常', '演示应用内部错误，请查看终端日志。'));
        } else {
            res.end();
        }
    });
}).listen(CONFIG.port, CONFIG.host, () => {
    console.log(`[demo] LyraAuth Demo RP 已启动`);
    console.log(`[demo]   本应用:  ${CONFIG.baseUrl}`);
    console.log(`[demo]   IdP:     ${CONFIG.issuer}`);
    console.log(`[demo]   Client:  ${CONFIG.clientId}`);
    console.log(`[demo]   回跳:    ${REDIRECT_URI}`);
});
