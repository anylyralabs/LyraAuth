<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

/**
 * 令牌与会话的绑定关系。
 *
 * 授权码流程横跨两个请求：授权请求（有会话）与令牌请求（无会话）。
 * 为了在签发的访问令牌里写入 OIDC 的 sid，需要把会话 ID 随授权码一起保存，
 * 刷新令牌同样继承原会话。
 */
return new class extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE `oauth_auth_codes` ADD COLUMN `session_id` VARCHAR(128) NULL DEFAULT NULL AFTER `redirect_uri`');
        Db::statement('ALTER TABLE `oauth_auth_codes` ADD INDEX `oauth_auth_codes_session_id_index` (`session_id`)');

        Db::statement('ALTER TABLE `oauth_refresh_tokens` ADD COLUMN `session_id` VARCHAR(128) NULL DEFAULT NULL AFTER `access_token_id`');
        Db::statement('ALTER TABLE `oauth_refresh_tokens` ADD INDEX `oauth_refresh_tokens_session_id_index` (`session_id`)');
    }

    public function down(): void
    {
        Db::statement('ALTER TABLE `oauth_refresh_tokens` DROP INDEX `oauth_refresh_tokens_session_id_index`');
        Db::statement('ALTER TABLE `oauth_refresh_tokens` DROP COLUMN `session_id`');

        Db::statement('ALTER TABLE `oauth_auth_codes` DROP INDEX `oauth_auth_codes_session_id_index`');
        Db::statement('ALTER TABLE `oauth_auth_codes` DROP COLUMN `session_id`');
    }
};
