<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\OauthClient;
use App\Model\OauthClientScope;
use App\Model\OauthScope;
use App\Support\Support;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * 创建 OAuth2 客户端。
 *
 *   php bin/hyperf.php oauth:client:create \
 *     --name="演示应用" --redirect="http://localhost:9502/callback" --public
 */
#[Command]
class CreateClientCommand extends HyperfCommand
{
    protected ?string $name = 'oauth:client:create';

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('创建一个 OAuth2 / OIDC 客户端');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, '应用名称');
        $this->addOption('redirect', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, '重定向 URI，可多次指定');
        $this->addOption('post-logout', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, '登出后回跳 URI');
        $this->addOption('public', null, InputOption::VALUE_NONE, '创建公共客户端（无密钥，强制 PKCE）');
        $this->addOption('first-party', null, InputOption::VALUE_NONE, '第一方应用（跳过同意页）');
        $this->addOption('scopes', null, InputOption::VALUE_REQUIRED, '允许的 scope，空格分隔，留空表示全部');
        $this->addOption('description', null, InputOption::VALUE_REQUIRED, '应用说明');
        $this->addOption('allow-private-http', null, InputOption::VALUE_NONE, '允许私有网段（10/172.16/192.168）使用 http，仅限内网演示');
    }

    public function handle(): int
    {
        $name = (string) ($this->input->getOption('name') ?: '');
        if ($name === '') {
            $this->error('必须提供 --name。');

            return 1;
        }

        $redirects = array_values(array_filter((array) $this->input->getOption('redirect')));
        if ($redirects === []) {
            $this->error('至少提供一个 --redirect。');

            return 1;
        }

        $allowPrivateHttp = (bool) $this->input->getOption('allow-private-http');

        foreach ($redirects as $uri) {
            if (! $this->isValidRedirect($uri, $allowPrivateHttp)) {
                $this->error("非法的重定向 URI：{$uri}（必须是绝对地址，且除 loopback 外必须使用 https，不允许 fragment）");
                if (! $allowPrivateHttp) {
                    $this->comment('内网演示可加 --allow-private-http 临时允许私有网段使用 http；生产环境不要使用该选项。');
                }

                return 1;
            }
        }

        if ($allowPrivateHttp) {
            $this->comment('已允许私有网段使用 http：仅限本地/内网演示，请勿用于生产。');
        }

        $isPublic = (bool) $this->input->getOption('public');
        $secret = $isPublic ? null : Support::clientSecret();

        $grantTypes = ['authorization_code', 'refresh_token'];
        if (! $isPublic) {
            $grantTypes[] = 'client_credentials';
        }
        $grantTypes[] = 'urn:ietf:params:oauth:grant-type:device_code';

        $client = OauthClient::create([
            'uuid' => Support::uuid(),
            'client_id' => Support::clientId(),
            'client_secret_hash' => $secret !== null ? hash('sha256', $secret) : null,
            'name' => $name,
            'description' => $this->input->getOption('description'),
            'client_type' => $isPublic ? OauthClient::TYPE_PUBLIC : OauthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => $redirects,
            'post_logout_redirect_uris' => array_values(array_filter((array) $this->input->getOption('post-logout'))) ?: null,
            'grant_types' => $grantTypes,
            'token_endpoint_auth_method' => $isPublic ? OauthClient::AUTH_NONE : OauthClient::AUTH_BASIC,
            'require_pkce' => $isPublic,
            'is_first_party' => (bool) $this->input->getOption('first-party'),
            'is_active' => true,
        ]);

        $scopes = trim((string) $this->input->getOption('scopes'));
        if ($scopes !== '') {
            foreach (preg_split('/\s+/', $scopes) ?: [] as $scope) {
                if (OauthScope::query()->where('identifier', $scope)->exists()) {
                    OauthClientScope::create([
                        'client_id' => $client->id,
                        'scope_identifier' => $scope,
                    ]);
                }
            }
        }

        $this->line('');
        $this->info('客户端创建成功：');
        $this->line('  client_id:     ' . $client->client_id);
        if ($secret !== null) {
            $this->line('  client_secret: ' . $secret);
            $this->comment('  密钥只显示这一次，请立即保存。');
        } else {
            $this->line('  类型:          公共客户端（强制 PKCE）');
        }
        $this->line('  redirect_uris: ' . implode(', ', $redirects));
        $this->line('  授权端点:       ' . rtrim((string) \Hyperf\Support\env('APP_URL', 'http://localhost:9501'), '/') . '/oauth/authorize');
        $this->line('');

        return 0;
    }

    private function isValidRedirect(string $uri, bool $allowPrivateHttp = false): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        if ($scheme === 'https' || $isLoopback) {
            return true;
        }

        // 内网演示：仅当显式开启时允许私有网段使用 http
        if ($allowPrivateHttp && $this->isPrivateHost($host)) {
            return true;
        }

        return false;
    }

    /** 判断是否为 RFC 1918 私有地址 */
    private function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8'] as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if ((ip2long($host) & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
