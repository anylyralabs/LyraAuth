<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 255)->nullable()->comment('操作者快照，用户删除后仍可追溯');
            $table->string('action', 64)->comment('如 auth.login / oauth.token.issue');
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('result', 20)->default('success')->comment('success / failure / denied');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('meta')->nullable()->comment('已脱敏的上下文');
            $table->timestamp('created_at')->nullable();

            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });

        // 登录尝试：风控与限流依据，同时保留审计价值
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('identifier', 255)->nullable()->comment('提交的账号或邮箱');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('result', 20)->comment('success / bad_credentials / locked / mfa_failed / blocked');
            $table->timestamp('created_at')->nullable();

            $table->index(['identifier', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        // 站点设置：值可加密
        Schema::create('settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->string('group', 40)->default('general');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('audit_logs');
    }
};
