# LyraAuth 项目

LyraAuth 是基于 Swoole + Hyperf 的 OAuth 2.0 / OpenID Connect 统一身份认证平台，
提供单点登录（SSO）、TOTP 双因素、Passkey 免密登录与设备码登录。

## 技术栈

| 层 | 选型 | 说明 |
|---|---|---|
| 运行时 | PHP 8.4 + Swoole 6.2 | 常驻协程模型 |
| 框架 | Hyperf 3.2 | 注解路由、DI、连接池 |
| 数据库 | MariaDB 11.8 (`lyraauth`) | utf8mb4 / InnoDB / 外键约束 |
| 缓存与会话 | Redis 7（带密码，DB 0） | 会话、限流、缓存共用实例，键前缀隔离 |
| 模板 | hyperf/view-engine（Blade 子集） | 纯 SSR，**不使用 Vue 或任何前端框架** |
| 样式 | Tailwind CSS 4 + esbuild | 本地构建，产物在 `public/assets/` |
| OAuth2 | league/oauth2-server 9.4 | 自研 OIDC 薄封装 |
| WebAuthn | web-auth/webauthn-lib 5.3 | CredentialRecord 模型 |
| TOTP | spomky-labs/otphp 11.5 | 32 字符 base32 密钥 |

端口：应用 `9501`（Swoole 直连，无 nginx）。
开发域名固定 `http://localhost:9501`——WebAuthn 的 RP ID 不接受 IP，用 `127.0.0.1` 会导致 Passkey 无法创建。

## 目录约定

```
app/
  Controller/Web/      页面与表单端点（注解路由）
  Controller/{OAuth,Admin}/  后续阶段
  Middleware/          SecurityHeader / StaticAsset / Csrf / Authenticate / Admin
  Model/               每个表一个模型，属性含义写在类注释
  Service/             SessionGuard、AuthService、MfaService、WebauthnService、
                       AuditService、RateLimiter、SettingsService、MailService
  Support/             Crypto、QrCode、Support（UUID/随机）、View（模板辅助）
  Listener/            ViewDirectiveListener、RuntimeDirectoryListener
storage/view/          Blade 模板（layouts / auth / account / dashboard / partials）
storage/keys/oauth/    OAuth 签名密钥（0600，gitignore）
migrations/            表结构
seeders/DatabaseSeeder.php  角色、scope、管理员（无命名空间，Hyperf 限制）
resources/             Tailwind 与 JS 源文件
public/assets/         构建产物
tmp/                   本地验证脚本
```

## 关键约定

### 视图
- view-engine 是 Blade **子集**：没有 `@class`、`@vite`、`@error`、`$errors` 对象。
- 自定义指令（`app/Listener/ViewDirectiveListener.php`，挂在 `BeforeWorkerStart`）：
  `@asset`、`@url`、`@csrf`、`@method`、`@since`。
- 全局函数（`app/Support/helpers.php`，composer `files` 加载）：
  `csrf_field()`、`old()`、`error_for()`、`flash()`、`__text()`。
- 条件样式用 `{{ $active ? 'a' : 'b' }}`，不要用 `@class`。
- 每次改动模板后必须跑 `pnpm build`，否则新用到的 Tailwind 类不会生成。

### 协程与并发
- BladeCompiler 在 worker 内实例化，缓存目录必须在主进程预建
  （`RuntimeDirectoryListener` 监听 `BeforeMainServerStart`），否则多 worker 并发 mkdir 会崩。
- 自定义指令监听器必须挂 `BeforeWorkerStart`，主进程注册不会传递给 worker。
- 协程内不要用阻塞 IO；SMTP 已改用 `Swoole\Coroutine\Client`。

### 安全
- 所有非幂等请求经 `CsrfMiddleware` 校验 `_token`；
  OAuth token / introspect / revoke / device_authorization 与 `.well-known` 端点豁免。
- 会话：登录后 `invalidate()` 换 ID；闲置 2 小时 / 绝对 12 小时；
  `user_sessions` 表是权威来源，撤销即失效。
- 密码 Argon2id（m=65536, t=4, p=2），登录成功自动 rehash；
  不存在的账号也执行一次哈希校验，避免时序枚举。
- 登录失败按账号 5 次 / IP 20 次限流，超限锁定 15 分钟。
- TOTP 密钥用 `Crypto`（sodium secretbox，密钥由 APP_KEY 经 BLAKE2b 派生）加密；
  `last_used_counter` 防同码重放。
- 恢复码存 SHA-256 摘要，单次使用。
- 审计 `meta` 对 token/password/secret 等键统一脱敏，且以 JSON 字符串入库。
- 站内跳转一律经 `View::safeRedirect()`，只允许单前导斜线的相对路径。
- 静态资源由 `StaticAssetMiddleware` 提供：扩展名白名单 + `realpath` 前缀校验防目录遍历。

### 数据库
- 对外标识用 `users.uuid`（OIDC `sub`），内部用自增主键。
- `db:seed` 的 Seeder 类不能声明命名空间（Hyperf 按文件名解析类名）。
- 迁移文件按时间戳命名，一次 `migrate` 幂等。

## 本地开发

```bash
php bin/hyperf.php start          # 前台启动，监听 9501
php bin/hyperf.php migrate        # 执行迁移
php bin/hyperf.php db:seed        # 初始数据（管理员密码写入 runtime/logs/seed-credentials.log）
pnpm build                        # 构建 CSS/JS
pnpm dev:css                      # 样式监听（另开终端）
```

管理员：`admin@lyraauth.local`，初始密码见 `runtime/logs/seed-credentials.log`，首次登录强制改密。
开发环境邮件不真正发送，验证链接写入 `runtime/logs/mail.log`。

## 视觉规范（WebUI）

界面定位是「精密凭证 / 仪器面板」，而不是通用的卡片式后台。
样式唯一来源是 `resources/css/app.css`：改样式先改令牌与组件类，不要在模板里堆任意值。

### 色彩与字体

| 角色 | 令牌 | 值 |
|---|---|---|
| 画布 | `--color-canvas` | `#F5F5F2` 冷灰纸白 |
| 表面 | `--color-surface` | `#FFFFFF` |
| 墨色 | `--color-ink` | `#14171A` |
| 次级文字 | `--color-ink-soft` / `--color-muted` | `#43484E` / `#767C82` |
| 细线 | `--color-line` / `--color-line-strong` | `#E4E4DE` / `#C8C8C0` |
| 黄铜强调 | `--color-brand` | `#96631C` |

- 黄铜色只用于主操作、当前状态与关键读数，不要扩大成装饰色。
- 不使用渐变（品牌区网格线除外）、彩色阴影与 Emoji；层次靠 1px 细线建立。
- 字体已本地化到 `public/assets/fonts/`，由 `@font-face` 引入：
  `Bricolage Grotesque`（标题/读数）、`Instrument Sans`（正文）、`IBM Plex Mono`（数据/代码）。
  中文回退到系统无衬线字体。
- 深色区域统一 `bg-ink`，文字用 `#F5F5F2`；侧栏未激活项用 `#B4B9BE`。

### 组件类

- 布局：`.page-head`（左侧 2px 铜线标题）、`.section-title`、`.panel`、
  `.panel-header`、`.panel-body`、`.panel-title`、`.panel-note`。
- 表单：`.field-label`、`.field-input`、`.field-hint`、`.field-error`；
  `.btn` + `.btn-primary` / `.btn-secondary` / `.btn-ghost` / `.btn-danger`、
  `.btn-sm`、`.btn-block`。
- 数据：`.data-table`、`.spec`（键值表）、`.readout` / `.readout-label` /
  `.readout-value` / `.readout-note`。
- 状态：`.badge` + `-brand` / `-success` / `-warning` / `-danger` / `-neutral`；
  `.alert` + `-error` / `-success` / `-info` / `-warning`。
- 等宽：`.mono`。圆角只允许 2–4px（面板 3px、控件 4px），不要用 `rounded-full`。

### 模板约定

- 页面标题与说明由控制器传入 `$pageTitle` / `$pageDescription`，`layouts/shell` 统一渲染；
  页面内容不要重复写 `<h1>`。
- 长表格用 `class="data-table table-fixed"`，列宽写在 `<th>` 的百分比类上。
  需要截断的单元格加 `max-w-0 truncate` 并补 `title`，否则移动端会把操作列挤出屏幕。
- 行内操作菜单写 `<details data-menu>`，内层用 `.panel absolute`。
  `resources/js/app.js` 的 `initRowMenus()` 会在展开时改为 fixed 定位，
  避免被 `.overflow-x-auto` / `.overflow-hidden` 裁切，不要依赖纯 CSS 绝对定位。
- 动态背景色（用户头像、客户端图标）必须用 `$user->avatarInk()` /
  `$client->logoInk()` 设置前景色，防止浅色底出现白字不可读。
- 空状态与页脚放在内容末尾；`layouts/shell` 用 `flex-1` 占位把页脚推到底部。

## 验证方式

真实浏览器验证脚本在 `tmp/`，使用 Chromium + CDP（Node 24 内置 WebSocket，零依赖）：

```bash
chromium --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage \
  --remote-debugging-port=9222 --user-data-dir=/tmp/opencode/chrome-profile about:blank &

VERIFY_BASE=http://127.0.0.1:9501 node tmp/verify-auth-flow.mjs   # 注册→验证→登录→登出
MFA_EMAIL=admin@lyraauth.local MFA_PASSWORD=... node tmp/verify-mfa.mjs  # TOTP 全流程
ADMIN_PASSWORD=... TOTP_SECRET=... node tmp/ui-audit.mjs /tmp/opencode/shots  # 全站视觉审计
```

验证结论必须来自真实浏览器——jsdom 无法判断布局与 WebAuthn。

## OIDC 特殊说明

- 签名密钥放 `storage/keys/oauth/`（文件），不放数据库；JWT 头带 `kid`。
- 前端 `resources/js/webauthn.js` 负责 base64url ↔ ArrayBuffer 转换，
  服务端 `WebauthnService` 用 `WebauthnSerializerFactory` 反序列化。
- WebAuthn 挑战存会话、一次性消费，5 分钟过期。

## 待办

- `[x]` 阶段 4：OAuth2 授权码 / PKCE / 刷新轮换 / 设备码 / revoke / introspect
- `[x]` 阶段 5：OIDC discovery、JWKS、id_token、userinfo、单点登出
- `[x]` 阶段 6：组织管理、审计日志页、设置中心、用户管理后台
- `[x]` 阶段 7：i18n、PHPUnit、README
- `[ ]` 社交登录（GitHub / Google）——仅预留，不实现

## OAuth2 / OIDC 实现要点

### 分层
- `app/OAuth/Entity/`：league 的实体实现（Client / Scope / AccessToken / RefreshToken / AuthCode / DeviceCode）
- `app/OAuth/Repository/`：6 个 Repository 接口实现
- `app/OAuth/ResponseType/OidcBearerTokenResponse.php`：在 Bearer 响应中补 id_token
- `app/Service/OAuthServerService.php`：装配 AuthorizationServer（4 种 grant）
- `app/Service/OAuthKeyService.php`：RSA 密钥生成 / 轮换 / JWKS
- `app/Service/IdTokenService.php`：id_token 签发与 at_hash
- `app/Service/OAuthContext.php`：请求级上下文（sid / amr / auth_time / nonce）

### 关键约束（踩过的坑）
- **league 9.4 的 API 与文档有出入**：
  - `completeAuthorizationRequest($authRequest, $response)`、`respondToAccessTokenRequest($request, $response)` 都必须传 response
  - `AuthCodeGrant` 默认已强制公共客户端 PKCE，没有 `setRequireCodeChallengeForPublicClients()`
  - `RefreshTokenGrant` 没有 `setRefreshTokenRotationEnabled()`，轮换是默认行为
  - `DeviceCodeGrant::__construct()` 需要 4 个参数：repo、refreshRepo、ttl、verificationUri
  - `AuthorizationServer` 构造函数第 5 个参数是加密密钥，**没有** `setEncryptionKey()`
- **JWT 的 `iss` 是注册声明**：必须用 `->issuedBy()`，用 `withClaim('iss', ...)` 会抛 `RegisteredClaimGiven`
- **`toString()` 必须缓存**：每次重建会生成新的 `iat`，导致 `at_hash` 与实际返回的令牌不一致
- **令牌标识长度**：league 生成的 jti 是 80–96 字符，`jti` / `code_hash` 列需 128
- **Hyperf 容器默认非单例**：跨方法传递状态必须用 `OAuthContext`，不能依赖用 `$container->get()` 拿到的 repo 实例字段
- **常驻内存**：`OAuthContext` 在 `OAuthContextMiddleware` 中每请求 `clear()`
- **授权码无法由明文反查**：库中存的是内部 auth_code_id 的 sha256；令牌请求按 `client + 未使用 + 未过期` 取最近一条恢复 sid/nonce
- **设备码 persist 幂等**：league 轮询时会重复 persist，必须先查 hash 再插入
- **`DeviceCodeTrait::setLastPolledAt()` 不接受 null**：仅在非空时调用
- **设备码批准不要调 `completeDeviceAuthorizationRequest()`**：状态已落库，重复调用会因找不到记录抛 invalidRequest
- **`token_endpoint_auth_method`**：公共客户端为 `none`，机密客户端默认 `client_secret_basic`

### 已验证的协议行为
| 场景 | 结果 |
|---|---|
| 授权码 + PKCE（S256） | 通过 |
| 授权码重用 | `invalid_grant` |
| 错误 code_verifier | `invalid_grant` |
| 刷新令牌轮换 | 新令牌签发，旧的失效 |
| 旧刷新令牌重放 | `invalid_grant` |
| 客户端凭证模式 | 可用 |
| 设备码：未批准轮询 | `authorization_pending` |
| 设备码：批准后兑换 | 成功 |
| revoke / introspect | 吊销后 `active=false` |
| userinfo 无令牌 | 401 |
| id_token RS256 签名 | 用 JWKS 公钥验签通过 |
| id_token at_hash | 与访问令牌一致 |
| 单点登出 | 会话内令牌全部吊销，`userinfo` 返回 401 |
| 未注册的 post_logout_redirect_uri | 回退到 `/login` |

### 命令
```bash
php bin/hyperf.php oauth:client:create --name="应用名" --redirect="https://a.com/cb" [--public] [--first-party]
php bin/hyperf.php oauth:keys:rotate [--force]
php bin/hyperf.php user:create --email=a@b.c --password=... [--admin] [--verified]
php bin/hyperf.php mail:test --to=you@example.com [--host=... --port=... --encryption=tls|ssl|none --verify=true|false]
```

### 验证脚本
```bash
CLIENT_ID=... OAUTH_EMAIL=... OAUTH_PASSWORD=... node tmp/verify-oauth.mjs   # 26 项
CLIENT_ID=... OAUTH_EMAIL=... OAUTH_PASSWORD=... node tmp/verify-oidc.mjs    # 18 项
CLIENT_ID=... OAUTH_EMAIL=... OAUTH_PASSWORD=... node tmp/verify-device.mjs  # 15 项
ADMIN_PASSWORD=... TOTP_SECRET=... node tmp/verify-admin.mjs                  # 8 项
```

测试账号：`oauthor@example.com` / `OAuthTest2026!`（未启用 TOTP）。
演示客户端：`演示应用`，公共客户端 + 第一方，redirect `http://localhost:9502/callback`。

## 管理后台结构

| 路径 | 说明 |
|---|---|
| `/admin` | 概览：用户/应用/会话/令牌统计、最近登录、最近操作 |
| `/admin/users` | 搜索、状态筛选、分页 |
| `/admin/users/{id}` | 详情：资料、会话、组织、应用、停用/角色/强制双因素/强制下线/发送重置邮件 |
| `/admin/clients` | 应用列表：新建、编辑、轮换密钥、启停、删除 |
| `/admin/clients/create`、`/admin/clients/{id}/edit` | 表单（含 scope 勾选） |
| `/admin/organizations` | 组织总览 |
| `/admin/scopes` | 权限范围 CRUD（系统内置不可删） |
| `/admin/audit-logs?tab=audit\|logins` | 操作日志 / 登录记录 |
| `/admin/settings` | 通用 + 邮件设置 + 微软 OAuth2 授权 + 密钥轮换提示 |
| `/admin/settings/mail/oauth/authorize`、`/callback` | 微软账号 OAuth2 授权（PKCE + state 校验） |
| `/organizations`、`/organizations/{slug}` | 用户侧组织：创建、成员管理 |
| `/account/profile`、`/account/apps` | 用户侧资料与已授权应用 |

## 实现补充说明

- **分页**：使用 `hyperf/paginator`，`$query->paginate(20)`，模板里 `previousPageUrl()` / `nextPageUrl()`。
- **视图里的 Carbon**：用 `\Carbon\Carbon::parse()`，不要用 `\Illuminate\Support\Carbon`（Hyperf 无此别名）。
- **Blade `@switch/@case`**：view-engine 支持，可放心使用。
- **审计去重**：登录成功只在 `AuthService::recordAttempt()` 里记一次；MFA 通过另记 `auth.mfa.passed`。
- **设置项覆盖 .env**：`MailService` 优先读 `settings` 表（`mail_driver` 等），为空才回退 `.env`。
- **SMTP 加密**：`Swoole\Coroutine\Client` 用 `SWOOLE_SOCK_TCP | SWOOLE_SSL` 做隐式 TLS；
  STARTTLS 则先明文握手、发 `STARTTLS`、再 `enableSSL()` 并按 RFC 3207 重新 EHLO。
  配置项 `mail_verify_peer` 控制证书校验（自签证书测试时设 false）。
- **SMTP 响应读取**：必须支持多行响应（`250-xxx` … `250 xxx`），
  且对端在返回 535 后立即断开时不能丢内容——`readResponse()` 会保留已收到的缓冲区。
- **邮件测试命令**：`php bin/hyperf.php mail:test --to=...`，会临时覆盖配置并清除同名的 settings 覆盖项。
- **SMTP 本地验证**：`tmp/smtp-test-server.py` 提供 plain / starttls / ssl 三种模式，
  自签证书可用 `openssl req -x509 ... -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"` 生成。
- **微软 OAuth2 邮件**：`MicrosoftOAuthService` 负责授权码 + PKCE 流程与令牌刷新，
  scope 必须是 `https://outlook.office.com/SMTP.Send`（**不是** Graph 的 `Mail.Send`），
  租户用 `consumers`；`AUTH XOAUTH2` 的 SASL 串是
  `base64("user=<email>\x01auth=Bearer <token>\x01\x01")`。
  `Swoole\Coroutine\Http\Client::post()` 只接受 2 个参数，请求头要用 `setHeaders()`。
- **Outlook 个人邮箱限制**：密码认证已关闭（`535 5.7.3`，且与密码对错无关）；
  OAuth2 也可能被服务端以 `535 5.7.139 SmtpClientAuthentication is disabled` 拒绝，
  这是账户级策略，代码无法绕过。
- **验证脚本的导航**：SPA 式等待 `document.readyState` 会误判；用 `Page.frameStoppedLoading` 事件或轮询目标选择器，并在开头 `Network.clearBrowserCookies`。
- **`Hyperf\Testing\Client::get()` 返回解包后的数组**：要断言状态码/响应头必须用 `request()`，它返回 `TestResponse`。
- **测试命令**：`./vendor/bin/co-phpunit --prepend test/bootstrap.php`（35 个测试）。

## Demo RP（独立的 OIDC 客户端）

`demo/` 是一个零依赖的 Node.js 应用，监听 9502，用于验证 IdP 的完整协议链路。
它不是 LyraAuth 的一部分，仅用于演示与回归。

```bash
cd demo && node server.mjs      # http://192.168.8.211:9502
```

演示内容：授权码 + PKCE、state/nonce、id_token 的 RS256 验签（用 JWKS 公钥）、
at_hash 校验、userinfo 调用、refresh token、RP 发起的单点登出。

关键实现细节：

- **验签**：`crypto.createPublicKey({ key: jwk, format: 'jwk' })` 直接吃 JWKS 的 RSA 公钥，
  再用 `crypto.verify('RSA-SHA256', ...)` 校验 `header.payload`。
- **PKCE**：`base64url(randomBytes(48))` 作 verifier，S256 challenge 是它的 SHA-256。
- **会话**：内存 Map + HMAC 签名 Cookie；演示用途，重启即清空。
- **内网回调**：新建客户端时需要 `--allow-private-http`，否则私有网段 IP 的 http 回调会被拒绝。
  该开关只影响命令行创建客户端，运行时的重定向校验仍然要求与注册值完全一致。

## 首次授权的 nonce 传递（重要）

同意页是 POST 提交，**没有 query string**。若直接从 POST 请求里取 `nonce`，会得到 null，
导致 id_token 缺少 nonce、客户端校验失败。

处理方式：

1. 渲染同意页时，把 nonce 存入会话（`SessionGuard::rememberAuthNonce()`）；
2. 同意提交后，`prepareAuthCodeContext()` 优先用 query 的 nonce，否则回退到会话值；
3. 无论走哪条分支都调用 `pullAuthNonce()` 清空，避免上一个请求的 nonce 泄漏到下一次授权。

静默授权（`prompt=none` 或已同意）仍从 query 取 nonce，所以两条路径都要覆盖。

## 已验证：Demo 全流程（33 项）

授权请求参数（PKCE/state/nonce）→ 登录后回到授权请求 → 同意页 → 授权码回跳
→ 令牌交换 → id_token 验签 → at_hash → userinfo → 刷新 → 单点登出
→ IdP 会话失效 → Demo 会话清除。

## Demo 常见错误

**「服务异常 / 演示应用内部错误」**：会话存在于内存但还没有 `tokens`（例如点过登录又返回首页、
或直接带着未完成授权的 Cookie 访问），早期版本会把它当成已登录去渲染账号页，导致
`Cannot read properties of undefined (reading 'scope')`。

现有实现已加两层防护：

1. `isAuthed(session)`：只有 `session.tokens.access_token` 存在才算登录，首页据此分支；
2. `accountPage()` 内部对所有字段做默认值兜底，缺字段只会显示为空，不会整页 500。

其余边界：`/refresh`、`/reload-userinfo` 在未登录时返回 400 提示页；`/logout` 清会话后回首页；
`/favicon.ico` 返回内联 SVG（避免 404 噪音）。排查时先看 `demo/` 的终端日志，
未捕获异常会打印在 `[demo] 未捕获异常:` 之后。
