<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // 邮箱验证 / 密码重置 / 管理员邀请，统一存哈希令牌
        Schema::create('verification_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('purpose', 32)->comment('email_verify / password_reset / invite');
            $table->string('token_hash', 64)->unique();
            $table->string('channel', 255)->nullable()->comment('目标邮箱，可用于变更邮箱场景');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_tokens');
    }
};
