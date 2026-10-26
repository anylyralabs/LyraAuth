<?php

declare(strict_types=1);

use App\OAuth\Repository\AccessTokenRepository;
use App\OAuth\Repository\AuthCodeRepository;
use App\OAuth\Repository\ClientRepository;
use App\OAuth\Repository\DeviceCodeRepository;
use App\OAuth\Repository\RefreshTokenRepository;
use App\OAuth\Repository\ScopeRepository;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

return [
    ClientRepositoryInterface::class => ClientRepository::class,
    ScopeRepositoryInterface::class => ScopeRepository::class,
    AccessTokenRepositoryInterface::class => AccessTokenRepository::class,
    RefreshTokenRepositoryInterface::class => RefreshTokenRepository::class,
    AuthCodeRepositoryInterface::class => AuthCodeRepository::class,
    DeviceCodeRepositoryInterface::class => DeviceCodeRepository::class,
    // 请求级上下文必须单例，否则跨方法传递会丢失（Hyperf 默认非单例）
    OAuthContext::class => OAuthContext::class,
];
