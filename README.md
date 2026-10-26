# LyraAuth

基于 **Swoole + Hyperf** 的 OAuth 2.0 / OpenID Connect 统一身份认证平台。
前后端不分离，纯服务端渲染；用一套账号打通多个接入应用，支持 SSO 与单点登出。

## 功能

**协议**
- OAuth 2.0：授权码 + PKCE（S256）、刷新令牌轮换、客户端凭证、设备码（RFC 8628）
- 令牌管理：吊销（RFC 7009）、自省（RFC 7662）
- OpenID Connect 1.0：Discovery、JWKS、`id_token`（RS256，含 `at_hash`）、`userinfo`
- 单点登录：已登录用户静默授权；RP 发起的单点登出会吊销该会话签发的全部令牌

**账号与安全**
- 邮箱 / 用户名 + 密码（Argon2id，登录后自动 rehash）
- TOTP 双因素（二维码绑定、恢复码、同码防重放）
- Passkey / WebAuthn（免密登录、多凭据管理）
- 邮箱验证、找回密码（统一提示，防账号枚举）
- 登录设备列表、远程下线、退出其他设备
- 登录限流（账号 5 次 / IP 20 次）、审计日志、登录记录

**管理**
- 应用管理（密钥仅展示一次、轮换、启停、重定向白名单校验）
- 用户管理（搜索、停用、角色、强制双因素、发送重置邮件、强制下线）
- 组织与成员、权限范围（scope）、站点设置、审计日志查询

## 环境要求

| 依赖 | 版本 |
|---|---|
| PHP | 8.2+（开发环境 8.4.27） |
| Swoole | 5.0+（开发环境 6.2.3） |
| MariaDB | 10.5+（开发环境 11.8.6） |
| Redis | 5.0+ |
| Node.js + pnpm | 用于构建前端资源 |

PHP 扩展：`swoole`、`redis`、`pdo_mysql`、`openssl`、`mbstring`、`intl`、`sodium`、`bcmath`、`gd`。

## 快速开始

```bash
# 1. 依赖
composer install
pnpm install

# 2. 配置
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # 填入 APP_KEY
# 编辑 .env：DB_*、REDIS_AUTH

# 3. 初始化
php bin/hyperf.php migrate
php bin/hyperf.php db:seed        # 管理员密码会打印并写入 runtime/logs/seed-credentials.log

# 4. 构建前端资源
pnpm build

# 5. 启动
php bin/hyperf.php start          # http://localhost:9501
```

开发环境邮件不真正发送，验证与重置链接写入 `runtime/logs/mail.log`。

> **WebAuthn 限制**：RP ID 必须是域名，请用 `localhost` 访问，用 `127.0.0.1` 会导致 Passkey 无法创建。

## 命令行

```bash
# 创建 OAuth 客户端（--public 为公共客户端，--first-party 跳过同意页）
php bin/hyperf.php oauth:client:create \
  --name="我的应用" \
  --redirect="https://app.example.com/callback" \
  --post-logout="https://app.example.com/" \
  --scopes="openid profile email"

# 创建账号
php bin/hyperf.php user:create --email=user@example.com --password='...' --verified [--admin]

# 轮换签名密钥（旧公钥保留在 JWKS 中，已签发令牌仍可验证）
php bin/hyperf.php oauth:keys:rotate
```

## 接入方式

```
Issuer:      http://localhost:9501
Discovery:   http://localhost:9501/.well-known/openid-configuration
Authorize:   /oauth/authorize
Token:       /oauth/token
UserInfo:    /userinfo
JWKS:        /.well-known/jwks.json
Logout:      /oauth/logout
Device:      /oauth/device_authorization  +  /device
Revoke:      /oauth/revoke
Introspect:  /oauth/introspect
```

客户端自动发现配置即可接入大多数 OIDC 库（NextAuth、Auth.js、Spring Security、Passport 等）。

## 目录结构

```
app/
  Command/           oauth:client:create / oauth:keys:rotate / user:create
  Controller/        Abstract / Home / Web / OAuth / Admin
  Exception/Handler/ 统一错误页渲染
  Listener/          视图指令、运行期目录
  Middleware/        安全头、静态资源、CSRF、认证、管理员、OAuth 上下文
  Model/             每个表一个模型
  OAuth/             Entity / Repository / ResponseType
  Service/           认证、会话、MFA、WebAuthn、OAuth 服务、审计、限流、邮件
  Support/           Crypto / QrCode / Support / View / helpers
storage/view/        Blade 模板
storage/keys/oauth/  RSA 签名密钥（0600）
migrations/          数据库迁移
seeders/             初始数据
resources/           Tailwind 与 JS 源文件
public/assets/       构建产物
test/                PHPUnit（Unit + Http）
tmp/                 浏览器验证脚本
```


## Demo 应用

`demo/` 目录是一个独立的 OpenID Connect 客户端（Node.js，零依赖），
监听 `9502`，用来验证 LyraAuth 的完整协议链路，也是接入示例。

```bash
# 1. 注册客户端（--allow-private-http 仅用于内网演示）
php bin/hyperf.php oauth:client:create \
  --name="LyraAuth Demo" \
  --redirect="http://192.168.8.211:9502/callback" \
  --post-logout="http://192.168.8.211:9502/" \
  --allow-private-http

# 2. 填入凭据并启动
cd demo
cp .env.example .env    # 填写 DEMO_CLIENT_ID / DEMO_CLIENT_SECRET
node server.mjs         # http://192.168.8.211:9502
```

它会演示：授权码 + PKCE、id_token 的 RS256 验签（从 JWKS 取公钥）、
at_hash 与 nonce 校验、userinfo、刷新令牌、单点登出。

## 测试

```bash
# 单元与 HTTP 测试（需要数据库与 Redis）
./vendor/bin/co-phpunit --prepend test/bootstrap.php

# 真实浏览器端到端验证（Chromium + CDP，零依赖）
chromium --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage \
  --remote-debugging-port=9222 --user-data-dir=/tmp/opencode/chrome-profile about:blank &

node tmp/verify-auth-flow.mjs      # 注册 → 邮箱验证 → 登录 → 登出（10 项）
node tmp/verify-mfa.mjs            # TOTP 全流程（9 项）
node tmp/verify-oauth.mjs          # 授权码 / PKCE / 刷新 / 吊销（26 项）
node tmp/verify-oidc.mjs           # id_token 签名与 claims（18 项）
node tmp/verify-device.mjs         # 设备码与单点登出（15 项）
node tmp/verify-admin.mjs          # 应用管理（8 项）
node tmp/verify-admin-panel.mjs    # 用户/审计/scope/设置/组织（21 项）
```

## 邮件配置

支持 `log`（写文件）与 `smtp` 两种驱动，可在**管理后台 → 站点设置 → 邮件**配置，也可用 `.env`。
后台设置优先于 `.env`；密码以 APP_KEY 加密存储，保存后不回显。

### 方式一：用户名 + 密码

| 项 | 说明 |
|---|---|
| 驱动 | `log` 写入 `runtime/logs/mail.log`；`smtp` 真实投递 |
| SMTP 主机 / 端口 | 如 `smtp.example.com` / `587` |
| 认证方式 | `login`（AUTH LOGIN） |
| 加密方式 | `tls` = STARTTLS（587，推荐）；`ssl` = 隐式 TLS（465）；留空 = 明文（仅内网） |
| 用户名 / 密码 | 支持 AUTH LOGIN；留空则不认证 |
| 校验服务器证书 | 生产环境保持开启；自签证书内网服务器可关闭 |

### 方式二：微软 OAuth2（Outlook 个人邮箱）

Outlook / Hotmail 个人账户已关闭 SMTP 密码认证，必须用 OAuth2（`AUTH XOAUTH2`）。
在**管理后台 → 站点设置 → 微软账号授权**中配置，全程手动点选，无需命令行：

1. 打开 [Azure 门户 → 应用注册](https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade)，新建应用：
   - 账户类型选「**个人 Microsoft 账户**」（或「任何组织目录中的账户和个人 Microsoft 账户」）
   - 「身份验证」→ 添加平台 → **Web**，重定向 URI 填后台显示的那一串
     （默认 `http://localhost:9501/admin/settings/mail/oauth/callback`）
   - 「证书和密码」→ 新建客户端密码，复制**值**列（不是「密码 ID」）
   - 「API 权限」→ Microsoft Graph → 委托的权限，添加 `SMTP.Send` 与 `offline_access`
2. 后台填入 Client ID、密码值，租户保持 `consumers`，点「保存应用凭据」
3. 点「开始授权」，跳转微软登录页登录并同意
4. 回调后自动保存令牌，显示「已授权」及账号邮箱
5. 把「认证方式」切换为「微软 OAuth2（XOAUTH2）」并保存，然后发送测试邮件

令牌自动刷新：access_token 过期前 2 分钟用 refresh_token 换新，refresh_token 加密存储。
`state` 与 PKCE verifier 存会话、一次性消费、10 分钟过期，回调时用 `hash_equals` 校验。

`.env` 对应项：

```env
MAIL_AUTH_METHOD=xoauth2
MAIL_HOST=smtp-mail.outlook.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
```

### 验证配置

```bash
php bin/hyperf.php mail:test --to=you@example.com
```

或在后台点击「发送测试」，失败原因写入 `runtime/logs/hyperf.log`（例如
`SMTP AUTH 密码阶段响应异常（期望 235）：535 5.7.8 Authentication credentials invalid`）。

常见问题：

- **587 端口认证失败**：确认加密方式为 `tls`；部分服务商要求先开启「SMTP」或使用「应用专用密码」。
- **465 端口连接超时**：必须选 `ssl`（隐式 TLS），用 `tls` 会因服务器不返回 220 而失败。
- **证书校验失败**：自签证书场景关闭「校验服务器证书」，或把 CA 加入系统信任链。
- **发件人被拒**：多数服务商要求 From 地址与认证账号一致。
- **Outlook 报 `535 5.7.3`**：个人邮箱已停用密码认证，改用上面的 OAuth2 方式。
- **Outlook OAuth2 仍报 `535 5.7.139 SmtpClientAuthentication is disabled`**：微软在服务端关闭了该邮箱的
  SMTP 提交权限，个人账户无管理开关，只能改用 Resend / Brevo 等专业邮件服务。

## 设计与安全说明

- **密码**：Argon2id（m=65536, t=4, p=2）；不存在的账号也执行一次哈希校验，避免时序枚举。
- **令牌**：访问令牌为 RS256 JWT（15 分钟），刷新令牌不透明（30 天）且每次刷新轮换，检测到重放会吊销整条链。
- **密钥**：RSA 私钥存文件（0600），JWT 头带 `kid`，JWKS 可同时暴露多把公钥以支持平滑轮换。
- **会话**：登录后更换会话 ID；闲置 2 小时 / 绝对 12 小时；数据库记录是权威来源，撤销立即生效。
- **CSRF**：所有非幂等请求校验 `_token`；OAuth 机器端点豁免（由 client_secret / PKCE 保护）。
- **重定向**：精确匹配，禁通配符与 fragment，非 loopback 必须 https；站内跳转只允许相对路径。
- **加密**：TOTP 密钥与 SMTP 密码用 sodium secretbox（密钥由 APP_KEY 派生）加密存储。
- **审计**：`meta` 对 token / password / secret 等键统一脱敏。

## 已知限制

- 社交登录（GitHub / Google）尚未实现，仅保留扩展位置。
- 授权码与令牌换取的 sid/nonce 关联按「客户端 + 未使用授权码」定位；同一客户端高并发授权时理论上可能取到相邻记录，只影响 `sid`/`nonce` 声明，不影响授权正确性。
- 前端资源需手动 `pnpm build`（开发时可用 `pnpm dev:css` 监听）。
- 未内置 HTTPS 终止，生产环境建议前置反向代理。

## License

Apache-2.0
