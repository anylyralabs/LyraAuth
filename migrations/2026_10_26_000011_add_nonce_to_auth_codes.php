<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

/**
 * 保存授权请求的 nonce。
 *
 * nonce 由客户端在授权请求中给出，必须在 id_token 中回传（OIDC Core §3.1.2.1）。
 * 授权与令牌交换是两个请求，因此需要随授权码持久化。
 */
return new class extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE `oauth_auth_codes` ADD COLUMN `nonce` VARCHAR(255) NULL DEFAULT NULL AFTER `redirect_uri`');
    }

    public function down(): void
    {
        Db::statement('ALTER TABLE `oauth_auth_codes` DROP COLUMN `nonce`');
    }
};
