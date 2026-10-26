<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Controller\AbstractController;
use App\Middleware\AuthenticateMiddleware;
use App\Model\OauthClient;
use App\Model\OauthDeviceCode;
use App\Model\OauthScope;
use App\Model\User;
use App\Service\AuditService;
use App\Service\OAuthServerService;
use App\Service\SessionGuard;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 设备码用户批准页（RFC 8628 第 3.3 节）。
 *
 * 用户在电视、CLI 等受限设备上看到 user_code，到这里输入并批准。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
class DeviceController extends AbstractController
{
    /** 连续输错上限 */
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly OAuthServerService $oauth,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/device')]
    public function show(): ResponseInterface
    {
        $code = strtoupper(trim((string) $this->request->query('user_code', '')));

        if ($code !== '') {
            return $this->lookup($code);
        }

        return $this->renderForm();
    }

    #[PostMapping(path: '/device')]
    public function submit(): ResponseInterface
    {
        $code = strtoupper(trim((string) $this->request->input('user_code', '')));

        if ($code === '') {
            return $this->renderForm('请输入设备上显示的代码。')->withStatus(422);
        }

        return $this->lookup($code);
    }

    /** 用户确认批准或拒绝 */
    #[PostMapping(path: '/device/decision')]
    public function decide(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();
        $code = strtoupper(trim((string) $this->request->input('user_code', '')));
        $decision = (string) $this->request->input('decision', 'deny');

        $record = OauthDeviceCode::query()
            ->where('user_code', $code)
            ->where('status', OauthDeviceCode::STATUS_PENDING)
            ->first();

        if ($record === null || $record->expires_at === null || $record->expires_at->getTimestamp() <= time()) {
            return $this->renderForm('该代码已失效，请在设备上重新发起。')->withStatus(400);
        }

        if ($decision !== 'allow') {
            $record->status = OauthDeviceCode::STATUS_DENIED;
            $record->save();
            $this->audit->log('oauth.device.denied', 'denied', $user, 'oauth_device_code', (string) $record->id);

            return $this->renderResult(false);
        }

        $record->status = OauthDeviceCode::STATUS_APPROVED;
        $record->user_id = $user->id;
        $record->session_id = $this->guard->currentSessionId();
        $record->save();

        $this->audit->log('oauth.device.approved', 'success', $user, 'oauth_device_code', (string) $record->id);

        // 状态已落库，league 在令牌轮询时读取实体即可，无需再回调 completeDeviceAuthorizationRequest

        return $this->renderResult(true);
    }

    // ------------------------------------------------------------------ 内部

    private function lookup(string $code): ResponseInterface
    {
        $attempts = (int) (\Hyperf\Context\Context::get('device_attempts') ?? 0);

        $record = OauthDeviceCode::query()
            ->where('user_code', $code)
            ->where('status', OauthDeviceCode::STATUS_PENDING)
            ->first();

        if ($record === null) {
            return $this->renderForm('代码不存在或已被使用，请核对后重试。')->withStatus(404);
        }

        if ($record->expires_at === null || $record->expires_at->getTimestamp() <= time()) {
            return $this->renderForm('该代码已过期，请在设备上重新发起。')->withStatus(410);
        }

        $client = OauthClient::query()->find($record->client_id);
        if ($client === null) {
            return $this->renderForm('该应用已被停用。')->withStatus(403);
        }

        $scopeNames = OauthScope::query()
            ->whereIn('identifier', array_filter(explode(' ', (string) $record->scopes)))
            ->pluck('name', 'identifier')
            ->all();

        return $this->view->render('oauth.device-confirm', [
            'pageTitle' => '设备登录',
            'userCode' => $code,
            'client' => $client,
            'scopes' => $scopeNames,
            'user' => $this->guard->user(),
            'error' => null,
        ]);
    }

    private function renderForm(?string $error = null): ResponseInterface
    {
        return $this->view->render('oauth.device', [
            'pageTitle' => '设备登录',
            'error' => $error,
            'prefill' => (string) $this->request->query('user_code', ''),
        ]);
    }

    private function renderResult(bool $approved): ResponseInterface
    {
        return $this->view->render('oauth.device-result', [
            'pageTitle' => $approved ? '已批准' : '已拒绝',
            'approved' => $approved,
        ]);
    }
}
