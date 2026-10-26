# LyraAuth Demo RP

一个最小的 OpenID Connect 客户端，用来验证 LyraAuth 作为 IdP 的完整能力。
独立运行在 `9502` 端口，与 LyraAuth 之间只通过标准协议通信。

## 它演示了什么

| 能力 | 说明 |
|---|---|
| 授权码 + PKCE | 生成 `code_verifier`，用 S256 challenge 发起授权 |
| state / nonce | 防 CSRF，并把 nonce 写入 id_token 以便回校验 |
| id_token 验签 | 从 JWKS 取公钥，用 RS256 校验签名与 kid |
| 声明校验 | `iss` / `aud` / `exp` / `nonce` / `at_hash` |
| userinfo | 用 `Authorization: Bearer` 读取用户资料 |
| 刷新令牌 | 演示轮换机制 |
| 单点登出 | RP 发起登出，携带 `id_token_hint` |

## 启动

```bash
cd demo
cp .env.example .env      # 填入 client_id / client_secret
node server.mjs
```

浏览器打开 http://192.168.8.211:9502

零依赖：只用 Node 内置模块（`http` / `crypto` / `fetch`）。

## 注册客户端

在 LyraAuth 根目录执行：

```bash
php bin/hyperf.php oauth:client:create \
  --name="LyraAuth Demo" \
  --redirect="http://192.168.8.211:9502/callback" \
  --redirect="http://localhost:9502/callback" \
  --post-logout="http://192.168.8.211:9502/" \
  --post-logout="http://localhost:9502/" \
  --description="本地 OIDC 演示应用" \
  --allow-private-http
```

`--allow-private-http` 仅用于内网演示，它允许私有网段（10/172.16/192.168）使用 http 回调。
生产环境不要加这个选项：OAuth 规范要求非 loopback 地址必须使用 https。

命令会输出 `client_id` 与 `client_secret`，密钥只显示一次，请填入 `demo/.env`。

## 环境变量

| 变量 | 默认值 | 说明 |
|---|---|---|
| `DEMO_PORT` | `9502` | 监听端口 |
| `DEMO_BASE_URL` | `http://192.168.8.211:9502` | 本应用对外地址，必须与注册的回调前缀一致 |
| `DEMO_ISSUER` | `http://192.168.8.211:9501` | IdP 地址 |
| `DEMO_CLIENT_ID` | — | 必填 |
| `DEMO_CLIENT_SECRET` | — | 机密客户端必填；公共客户端留空 |
| `DEMO_SCOPES` | `openid profile email` | 申请的权限范围 |
| `DEMO_SESSION_SECRET` | `demo-session-secret-change-me` | 会话 Cookie 签名密钥 |

## 流程

```
浏览器             Demo (9502)                  LyraAuth (9501)
  │ 点击登录           │                              │
  │──────────────────▶│ 生成 verifier/state/nonce     │
  │◀──────────────────│ 302 → /oauth/authorize       │
  │──────────────────────────────────────────────────▶│ 校验 client / redirect_uri
  │◀──────────────────────────────────────────────────│ 登录页 / 同意页
  │──────────────────────────────────────────────────▶│ 用户同意
  │◀──────────────────────────────────────────────────│ 302 → /callback?code&state
  │──────────────────▶│ 校验 state                    │
  │                    │ POST /oauth/token ──────────▶│ 校验 PKCE、签发令牌
  │                    │◀─────────────────────────────│ access + id + refresh
  │                    │ 验签 id_token（JWKS）          │
  │                    │ GET /userinfo ──────────────▶│
  │◀──────────────────│ 渲染账号页                     │
```

## 注意

- 会话存在内存，重启即失效；这是演示程序，不用于生产。
- 账号页会展示完整的 access_token 与全部声明，方便观察协议细节。
- 移动端布局已适配，无横向溢出。
