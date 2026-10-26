<?php

declare(strict_types=1);

/**
 * English strings.
 *
 * English is the fallback locale; keep keys in sync with zh_CN/messages.php.
 */
return [
    'app' => [
        'name' => 'LyraAuth',
        'tagline' => 'Identity Provider',
    ],
    'nav' => [
        'dashboard' => 'Overview',
        'profile' => 'Profile',
        'security' => 'Security',
        'apps' => 'Authorized apps',
        'organizations' => 'Organizations',
        'admin' => 'Admin',
    ],
    'auth' => [
        'login' => 'Sign in',
        'logout' => 'Sign out',
        'register' => 'Create account',
        'forgot' => 'Forgot password?',
        'identifier' => 'Email or username',
        'password' => 'Password',
        'remember' => 'Remember this device',
        'two_factor' => 'Two-factor authentication',
        'code' => 'Verification code',
        'recovery_code' => 'Recovery code',
    ],
    'common' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'edit' => 'Edit',
        'back' => 'Back',
        'confirm' => 'Confirm',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'yes' => 'Yes',
        'no' => 'No',
        'loading' => 'Working…',
    ],
    'security' => [
        'password_changed' => 'Password updated. Other sessions have been signed out.',
        'session_revoked' => 'That device has been signed out.',
        'totp_enabled' => 'Two-factor authentication enabled.',
        'totp_disabled' => 'Two-factor authentication disabled. Consider re-enabling it.',
    ],
];
