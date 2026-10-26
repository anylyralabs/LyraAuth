<?php

declare(strict_types=1);

/**
 * 简体中文文案。
 *
 * 模板中使用 __text('key') 或直接写中文；本文件为将来完整 i18n 预留，
 * 已将高频通用文案集中于此，便于与 en 同步维护。
 */
return [
    'app' => [
        'name' => 'LyraAuth',
        'tagline' => '统一身份认证',
    ],
    'nav' => [
        'dashboard' => '概览',
        'profile' => '个人资料',
        'security' => '账号安全',
        'apps' => '已授权应用',
        'organizations' => '组织',
        'admin' => '管理',
    ],
    'auth' => [
        'login' => '登录',
        'logout' => '退出登录',
        'register' => '创建账号',
        'forgot' => '忘记密码？',
        'identifier' => '邮箱或用户名',
        'password' => '密码',
        'remember' => '在此设备上记住我',
        'two_factor' => '两步验证',
        'code' => '验证码',
        'recovery_code' => '恢复码',
    ],
    'common' => [
        'save' => '保存',
        'cancel' => '取消',
        'delete' => '删除',
        'edit' => '编辑',
        'back' => '返回',
        'confirm' => '确认',
        'enabled' => '已启用',
        'disabled' => '未启用',
        'yes' => '是',
        'no' => '否',
        'loading' => '处理中…',
    ],
    'security' => [
        'password_changed' => '密码已更新，其他设备的登录状态已失效。',
        'session_revoked' => '该设备已退出登录。',
        'totp_enabled' => '双因素认证已启用。',
        'totp_disabled' => '双因素认证已关闭，建议尽快重新启用。',
    ],
];
