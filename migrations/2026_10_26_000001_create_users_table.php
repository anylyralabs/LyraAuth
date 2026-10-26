<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique()->comment('对外暴露的用户标识，即 OIDC sub');
            $table->string('username', 64)->unique();
            $table->string('email', 255)->unique();
            $table->string('display_name', 100);
            $table->string('password_hash', 255);
            $table->string('status', 20)->default('active')->comment('active / suspended / pending');
            $table->string('platform_role', 20)->default('user')->comment('user / admin');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->boolean('mfa_enforced')->default(false)->comment('管理员强制要求启用双因素');
            $table->string('locale', 10)->default('zh_CN');
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->string('avatar_color', 7)->default('#2F4D5E');
            $table->text('profile_bio')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('platform_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
