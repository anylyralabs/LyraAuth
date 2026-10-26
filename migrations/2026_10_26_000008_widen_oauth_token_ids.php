<?php

declare(strict_types=1);

use Hyperf\Database\Exception\QueryException;
use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

/**
 * league/oauth2-server 生成的令牌标识是 80-96 字符，
 * 原先 64 字符的列会截断；同时把空串的 session_id 规范为 NULL。
 *
 * 这里用原生 ALTER 而不是 Blueprint::change()，避免为一次列宽调整引入 doctrine/dbal。
 */
return new class extends Migration
{
    public function up(): void
    {
        $statements = [
            'ALTER TABLE `oauth_access_tokens` MODIFY `jti` VARCHAR(128) NOT NULL',
            'ALTER TABLE `oauth_access_tokens` MODIFY `session_id` VARCHAR(128) NULL DEFAULT NULL',
            'ALTER TABLE `oauth_refresh_tokens` MODIFY `jti` VARCHAR(128) NOT NULL',
            'ALTER TABLE `oauth_auth_codes` MODIFY `code_hash` VARCHAR(128) NOT NULL',
            'UPDATE `oauth_access_tokens` SET `session_id` = NULL WHERE `session_id` = \'\'',
        ];

        foreach ($statements as $sql) {
            try {
                Db::statement($sql);
            } catch (QueryException $e) {
                // 已应用过的变更（例如手工调整）不应阻塞迁移
                if (! str_contains($e->getMessage(), 'Duplicate')) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        Db::statement('ALTER TABLE `oauth_auth_codes` MODIFY `code_hash` VARCHAR(64) NOT NULL');
        Db::statement('ALTER TABLE `oauth_refresh_tokens` MODIFY `jti` VARCHAR(64) NOT NULL');
        Db::statement('ALTER TABLE `oauth_access_tokens` MODIFY `session_id` VARCHAR(128) NULL DEFAULT NULL');
        Db::statement('ALTER TABLE `oauth_access_tokens` MODIFY `jti` VARCHAR(64) NOT NULL');
    }
};
