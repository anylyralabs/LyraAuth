<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // OAuth2 客户端（应用）
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->string('client_id', 64)->unique();
            // 公共客户端无 secret；机密客户端存 HMAC-SHA256 摘要
            $table->string('client_secret_hash', 255)->nullable();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('client_type', 20)->default('confidential')->comment('confidential / public');
            $table->json('redirect_uris');
            $table->json('post_logout_redirect_uris')->nullable();
            $table->json('grant_types');
            $table->string('token_endpoint_auth_method', 40)->default('client_secret_basic');
            $table->boolean('require_pkce')->default(true);
            $table->boolean('is_first_party')->default(false)->comment('第一方应用可跳过同意页');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('logo_color', 7)->default('#2F4D5E');
            $table->text('tos_uri')->nullable();
            $table->text('policy_uri')->nullable();
            $table->text('client_uri')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
            $table->index('organization_id');
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // 权限范围
        Schema::create('oauth_scopes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('identifier', 64)->unique()->comment('如 openid / profile / email');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false)->comment('授权时默认勾选');
            $table->boolean('is_system')->default(false)->comment('OIDC 标准 scope，不可删除');
            $table->timestamps();
        });

        // 客户端与 scope 的可选绑定（为空表示允许全部 scope）
        Schema::create('oauth_client_scopes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id');
            $table->string('scope_identifier', 64);
            $table->timestamps();

            $table->unique(['client_id', 'scope_identifier']);
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
        });

        // 授权码
        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code_hash', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('user_id');
            $table->text('scopes');
            $table->string('redirect_uri', 2000);
            $table->string('code_challenge', 128)->nullable();
            $table->string('code_challenge_method', 10)->nullable();
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('user_id');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 访问令牌（JWT 也落库，便于自省与吊销）
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('jti', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('user_id')->nullable()->comment('客户端凭证模式为空');
            $table->text('scopes');
            $table->string('session_id', 128)->nullable()->comment('签发时的登录会话，用于单点登出');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'revoked_at']);
            $table->index('user_id');
            $table->index('session_id');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 刷新令牌链：parent_id 用于检测重放
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('token_hash', 64)->unique();
            $table->string('jti', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('scopes');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('user_id');
            $table->index('parent_id');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 设备码（RFC 8628）
        Schema::create('oauth_device_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('device_code_hash', 64)->unique();
            $table->string('user_code', 16)->unique()->comment('用户可读的短码');
            $table->unsignedBigInteger('client_id');
            $table->text('scopes');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending / approved / denied / used');
            $table->unsignedInteger('interval')->default(5);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('client_id');
            $table->index('status');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        // 授权同意记录，支持静默 SSO 与“已授权应用”
        Schema::create('oauth_consents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->text('scopes');
            $table->timestamp('last_granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('oauth_clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_consents');
        Schema::dropIfExists('oauth_device_codes');
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_client_scopes');
        Schema::dropIfExists('oauth_scopes');
        Schema::dropIfExists('oauth_clients');
    }
};
