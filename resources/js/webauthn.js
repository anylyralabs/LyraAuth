/**
 * WebAuthn / Passkey 浏览器端逻辑。
 *
 * 只使用原生 Web Crypto 与 navigator.credentials，无第三方依赖。
 * 所有 base64url 与 ArrayBuffer 的转换集中在此文件。
 */

const CSRF_TOKEN = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.content;
    const input = document.querySelector('input[name="_token"]');
    return input ? input.value : '';
};

const postJson = async (url, payload) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN(),
        },
        body: JSON.stringify(payload ?? {}),
        credentials: 'same-origin',
    });

    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (!response.ok) {
        throw new Error((data && (data.message || data.error)) || `请求失败（${response.status}）`);
    }

    return data ?? {};
};

const b64urlToBuffer = (value) => {
    const normalized = String(value).replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
};

const bufferToB64url = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 1) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

/** 序列化注册凭据为服务端可解析的 JSON */
const serializeRegistration = (credential) => ({
    id: credential.id,
    rawId: bufferToB64url(credential.rawId),
    type: credential.type,
    clientExtensionResults: credential.getClientExtensionResults(),
    response: {
        clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
        attestationObject: bufferToB64url(credential.response.attestationObject),
        transports: credential.response.getTransports ? credential.response.getTransports() : [],
    },
});

/** 序列化断言响应 */
const serializeAssertion = (credential) => ({
    id: credential.id,
    rawId: bufferToB64url(credential.rawId),
    type: credential.type,
    clientExtensionResults: credential.getClientExtensionResults(),
    response: {
        clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
        authenticatorData: bufferToB64url(credential.response.authenticatorData),
        signature: bufferToB64url(credential.response.signature),
        userHandle: credential.response.userHandle ? bufferToB64url(credential.response.userHandle) : null,
    },
});

const setStatus = (node, message, isError = false) => {
    if (!node) return;
    node.textContent = message;
    node.classList.remove('hidden');
    node.classList.toggle('text-danger', isError);
    node.classList.toggle('text-muted', !isError);
};

const unsupportedMessage = () => {
    if (!window.isSecureContext) {
        return '当前环境不是安全上下文，Passkey 需要在 HTTPS 或 localhost 下使用。';
    }
    if (!window.PublicKeyCredential) {
        return '当前浏览器不支持 Passkey。';
    }
    return null;
};

/** 账号安全页：注册新 Passkey */
const initPasskeyRegister = () => {
    const button = document.querySelector('[data-passkey-register]');
    if (!button) return;

    const status = document.querySelector('[data-passkey-status]');

    button.addEventListener('click', async () => {
        const unsupported = unsupportedMessage();
        if (unsupported) {
            setStatus(status, unsupported, true);
            return;
        }

        button.disabled = true;
        setStatus(status, '正在等待设备验证…');

        try {
            const options = await postJson('/account/passkeys/options', {
                device_name: navigator.platform || '未知设备',
            });

            const publicKey = {
                challenge: b64urlToBuffer(options.challenge),
                rp: options.rp,
                user: {
                    id: b64urlToBuffer(options.user.id),
                    name: options.user.name,
                    displayName: options.user.displayName,
                },
                pubKeyCredParams: options.pubKeyCredParams,
                authenticatorSelection: options.authenticatorSelection,
                attestation: options.attestation,
                excludeCredentials: (options.excludeCredentials || []).map((c) => ({
                    type: c.type,
                    id: b64urlToBuffer(c.id),
                })),
                timeout: options.timeout,
            };

            const credential = await navigator.credentials.create({ publicKey });
            const result = await postJson('/account/passkeys', serializeRegistration(credential));

            setStatus(status, '添加成功，正在刷新…');
            window.location.href = result.ok ? '/account/security' : '/account/security';
        } catch (error) {
            button.disabled = false;
            const message = error.name === 'NotAllowedError' ? '操作已取消或超时。' : error.message;
            setStatus(status, message, true);
        }
    });
};

/** 登录页 / 二次验证页：使用 Passkey 登录 */
const initPasskeyLogin = () => {
    const buttons = document.querySelectorAll('[data-passkey-login]');
    if (buttons.length === 0) return;

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            const status = document.querySelector('[data-passkey-status]');
            const unsupported = unsupportedMessage();
            if (unsupported) {
                setStatus(status, unsupported, true);
                return;
            }

            button.disabled = true;
            setStatus(status, '正在等待设备验证…');

            const endpoint = button.dataset.passkeyLogin === 'conditional'
                ? '/mfa/passkey/verify'
                : '/mfa/passkey/verify';

            try {
                const options = await postJson('/mfa/passkey/options');
                const publicKey = {
                    challenge: b64urlToBuffer(options.challenge),
                    rpId: options.rpId,
                    allowCredentials: (options.allowCredentials || []).map((c) => ({
                        type: c.type,
                        id: b64urlToBuffer(c.id),
                    })),
                    userVerification: options.userVerification,
                    timeout: options.timeout,
                };

                const credential = await navigator.credentials.get({ publicKey });
                const result = await postJson(endpoint, serializeAssertion(credential));

                window.location.href = result.redirect || '/dashboard';
            } catch (error) {
                button.disabled = false;
                const message = error.name === 'NotAllowedError' ? '操作已取消或超时。' : error.message;
                setStatus(status, message, true);
            }
        });
    });
};

/** 注入 CSRF meta，便于脚本读取 */
const ensureCsrfMeta = () => {
    if (document.querySelector('meta[name="csrf-token"]')) return;
    const token = CSRF_TOKEN();
    if (!token) return;
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = token;
    document.head.appendChild(meta);
};

export { CSRF_TOKEN, postJson, b64urlToBuffer, bufferToB64url, initPasskeyRegister, initPasskeyLogin, ensureCsrfMeta };
