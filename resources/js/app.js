import { ensureCsrfMeta, initPasskeyLogin, initPasskeyRegister } from './webauthn.js';

/**
 * LyraAuth 前端脚本 —— 原生 JavaScript，无框架依赖。
 * 只做渐进增强：所有关键流程在禁用 JS 时依然可用。
 */

const onReady = (fn) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
};

/** 密码可见性切换 */
const initPasswordToggles = (root = document) => {
    root.querySelectorAll('[data-password-toggle]').forEach((button) => {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggle);
            if (!input) return;
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            const label = button.dataset.labelShow || '显示密码';
            const labelHide = button.dataset.labelHide || '隐藏密码';
            button.setAttribute('aria-label', reveal ? labelHide : label);
        });
    });
};

/** 复制到剪贴板 */
const initCopyButtons = (root = document) => {
    root.querySelectorAll('[data-copy]').forEach((button) => {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        button.addEventListener('click', async () => {
            const value = button.dataset.copy || '';
            if (!value) return;
            const original = button.textContent;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(value);
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = value;
                    helper.setAttribute('readonly', '');
                    helper.style.position = 'fixed';
                    helper.style.opacity = '0';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    document.body.removeChild(helper);
                }
                button.textContent = button.dataset.copiedLabel || '已复制';
                window.setTimeout(() => {
                    button.textContent = original;
                }, 1600);
            } catch {
                /* 剪贴板不可用时保持静默，用户仍可手动选择 */
            }
        });
    });
};

/** 危险操作二次确认 */
const initConfirmForms = (root = document) => {
    root.querySelectorAll('form[data-confirm]').forEach((form) => {
        if (form.dataset.bound === '1') return;
        form.dataset.bound = '1';
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
};

/** 自动提交筛选表单 */
const initAutoSubmit = (root = document) => {
    root.querySelectorAll('[data-auto-submit]').forEach((select) => {
        if (select.dataset.bound === '1') return;
        select.dataset.bound = '1';
        select.addEventListener('change', () => select.form?.submit());
    });
};

/** 一次性密钥（secret）逐段显示，便于人工抄录 */
const initSecretGroups = (root = document) => {
    root.querySelectorAll('[data-secret]').forEach((node) => {
        if (node.dataset.bound === '1') return;
        node.dataset.bound = '1';
        const value = (node.dataset.secret || '').replace(/\s+/g, '');
        node.textContent = value.replace(/(.{4})/g, '$1 ').trim();
    });
};

onReady(() => {
    ensureCsrfMeta();
    initPasswordToggles();
    initCopyButtons();
    initConfirmForms();
    initAutoSubmit();
    initSecretGroups();
    initPasskeyRegister();
    initPasskeyLogin();
});

export {
    initPasswordToggles,
    initCopyButtons,
    initConfirmForms,
    initAutoSubmit,
    initSecretGroups,
};
