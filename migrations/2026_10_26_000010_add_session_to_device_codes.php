<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

/**
 * 设备码批准时记录 IdP 会话，使设备码签发的令牌也能参与单点登出。
 */
return new class extends Migration
{
    public function up(): void
    {
        Db::statement('ALTER TABLE `oauth_device_codes` ADD COLUMN `session_id` VARCHAR(128) NULL DEFAULT NULL AFTER `user_id`');
        Db::statement('ALTER TABLE `oauth_device_codes` ADD INDEX `oauth_device_codes_session_id_index` (`session_id`)');
    }

    public function down(): void
    {
        Db::statement('ALTER TABLE `oauth_device_codes` DROP INDEX `oauth_device_codes_session_id_index`');
        Db::statement('ALTER TABLE `oauth_device_codes` DROP COLUMN `session_id`');
    }
};
