<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Middleware\AuthenticateMiddleware;
use App\Model\User;
use App\Service\AuditService;
use App\Service\MfaService;
use App\Service\SessionGuard;
use App\Service\WebauthnService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * Passkey 管理端点（JSON，供浏览器 WebAuthn API 调用）。
 *
 * 这些接口由同源页面脚本调用，仍需登录态 + CSRF（页面脚本读取 meta 令牌）。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
class PasskeyController extends AbstractController
{
    public function __construct(
        private readonly WebauthnService $webauthn,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit
    ) {
    }

    #[PostMapping(path: '/account/passkeys/options')]
    public function registerOptions(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $options = $this->webauthn->creationOptions($user, (string) $this->request->input('device_name', ''));

        return $this->response->json($options);
    }

    #[PostMapping(path: '/account/passkeys')]
    public function store(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $payload = $this->request->all();
        $credential = $this->webauthn->register($user, $payload);

        if ($credential === null) {
            return $this->error('Passkey 注册失败，请重试。', 422);
        }

        $this->audit->log('account.passkey.added', 'success', $user, 'webauthn_credential', (string) $credential->id, [
            'device_name' => $credential->device_name,
        ]);

        return $this->response->json([
            'ok' => true,
            'id' => $credential->id,
            'device_name' => $credential->device_name,
        ]);
    }

    #[PostMapping(path: '/account/passkeys/{id}/delete')]
    public function destroy(int $id): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $credential = \App\Model\WebauthnCredential::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if ($credential === null) {
            $this->guard->flash('error', 'Passkey 不存在。');

            return $this->redirect('/account/security');
        }

        $credential->delete();
        $this->audit->log('account.passkey.removed', 'success', $user, 'webauthn_credential', (string) $id);
        $this->guard->flash('success', 'Passkey 已删除。');

        return $this->redirect('/account/security');
    }
}
