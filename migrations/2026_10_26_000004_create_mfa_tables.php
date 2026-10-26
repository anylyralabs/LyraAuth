<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // TOTP 密钥以 APP_KEY 派生的密钥加密存储
        Schema::create('user_mfa_totp', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('secret_ciphertext');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_counter')->default(0)->comment('防重放：已使用的最大时间步');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('code_hash', 255);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // WebAuthn / Passkey 凭证
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('credential_id', 255)->unique()->comment('base64url 编码的凭据 ID');
            $table->text('public_key');
            $table->string('aaguid', 36)->nullable();
            $table->string('transports', 128)->nullable();
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backup_state')->default(false);
            $table->string('device_name', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 已信任的 2FA 设备，避免每次登录都要验证
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64);
            $table->string('device_label', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'token_hash']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('webauthn_credentials');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('user_mfa_totp');
    }
};
