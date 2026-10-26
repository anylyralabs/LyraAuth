<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\Organization;
use App\Model\OauthClient;
use App\Model\User;
use App\Model\UserSession;
use App\Service\AuditService;
use App\Service\SessionGuard;
use Hyperf\Database\Model\Builder;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 用户管理（管理员）。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class UserController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/admin/users')]
    public function index(): ResponseInterface
    {
        $keyword = trim((string) $this->request->query('q', ''));
        $status = (string) $this->request->query('status', '');

        $query = User::query();
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('email', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('display_name', 'like', $like);
            });
        }
        if (in_array($status, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_PENDING], true)) {
            $query->where('status', $status);
        }

        $users = $query->orderByDesc('id')->paginate(20);

        return $this->view->render('admin.users.index', [
            'pageTitle' => '用户',
            'pageDescription' => '查看、停用与调整账号权限。',
            'navSection' => 'admin-users',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'users' => $users,
            'keyword' => $keyword,
            'status' => $status,
        ]);
    }

    #[GetMapping(path: '/admin/users/{id}')]
    public function show(int $id): ResponseInterface
    {
        $user = User::withTrashed()->find($id);
        if ($user === null) {
            return $this->redirect('/admin/users');
        }

        $sessions = UserSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity_at')
            ->limit(10)
            ->get();

        $clients = OauthClient::query()
            ->where('owner_id', $user->id)
            ->get();

        $organizations = Organization::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        return $this->view->render('admin.users.show', [
            'pageTitle' => $user->display_name,
            'pageDescription' => $user->email,
            'navSection' => 'admin-users',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'user' => $user,
            'sessions' => $sessions,
            'clients' => $clients,
            'organizations' => $organizations,
            'totpEnabled' => $user->totp()->exists(),
            'passkeyCount' => $user->webauthnCredentials()->count(),
        ]);
    }

    /** 停用 / 启用账号 */
    #[PostMapping(path: '/admin/users/{id}/status')]
    public function updateStatus(int $id): ResponseInterface
    {
        $user = User::query()->find($id);
        if ($user === null) {
            return $this->redirect('/admin/users');
        }

        /** @var User $actor */
        $actor = $this->guard->user();
        if ($user->id === $actor->id) {
            $this->guard->flash('error', '不能修改自己的账号状态。');

            return $this->redirect('/admin/users/' . $id);
        }

        $target = (string) $this->request->input('status', '');
        if (! in_array($target, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED], true)) {
            $this->guard->flash('error', '状态值不合法。');

            return $this->redirect('/admin/users/' . $id);
        }

        $user->status = $target;
        $user->save();

        // 停用后立即撤销所有会话
        if ($target === User::STATUS_SUSPENDED) {
            $this->guard->revokeAll($user->id);
        }

        $this->audit->log('admin.user.status_changed', 'success', $actor, 'user', (string) $user->id, [
            'status' => $target,
        ]);
        $this->guard->flash('success', $target === User::STATUS_ACTIVE ? '账号已启用。' : '账号已停用并退出所有设备。');

        return $this->redirect('/admin/users/' . $id);
    }

    /** 调整平台角色 */
    #[PostMapping(path: '/admin/users/{id}/role')]
    public function updateRole(int $id): ResponseInterface
    {
        $user = User::query()->find($id);
        if ($user === null) {
            return $this->redirect('/admin/users');
        }

        /** @var User $actor */
        $actor = $this->guard->user();
        if ($user->id === $actor->id) {
            $this->guard->flash('error', '不能修改自己的角色。');

            return $this->redirect('/admin/users/' . $id);
        }

        $role = (string) $this->request->input('platform_role', User::ROLE_USER);
        if (! in_array($role, [User::ROLE_USER, User::ROLE_ADMIN], true)) {
            $this->guard->flash('error', '角色不合法。');

            return $this->redirect('/admin/users/' . $id);
        }

        $user->platform_role = $role;
        $user->save();

        $this->audit->log('admin.user.role_changed', 'success', $actor, 'user', (string) $user->id, ['role' => $role]);
        $this->guard->flash('success', '角色已更新。');

        return $this->redirect('/admin/users/' . $id);
    }

    /** 强制要求启用双因素 */
    #[PostMapping(path: '/admin/users/{id}/mfa-policy')]
    public function updateMfaPolicy(int $id): ResponseInterface
    {
        $user = User::query()->find($id);
        if ($user === null) {
            return $this->redirect('/admin/users');
        }

        $user->mfa_enforced = $this->request->input('mfa_enforced') === '1';
        $user->save();

        /** @var User $actor */
        $actor = $this->guard->user();
        $this->audit->log('admin.user.mfa_policy_changed', 'success', $actor, 'user', (string) $user->id, [
            'enforced' => $user->mfa_enforced,
        ]);
        $this->guard->flash('success', $user->mfa_enforced ? '已要求该账号启用双因素。' : '已取消双因素强制要求。');

        return $this->redirect('/admin/users/' . $id);
    }

    /** 撤销该用户的所有会话 */
    #[PostMapping(path: '/admin/users/{id}/revoke-sessions')]
    public function revokeSessions(int $id): ResponseInterface
    {
        $user = User::query()->find($id);
        if ($user === null) {
            return $this->redirect('/admin/users');
        }

        $count = $this->guard->revokeAll($user->id);

        /** @var User $actor */
        $actor = $this->guard->user();
        $this->audit->log('admin.user.sessions_revoked', 'success', $actor, 'user', (string) $user->id, ['count' => $count]);
        $this->guard->flash('success', "已退出该账号的 {$count} 个会话。");

        return $this->redirect('/admin/users/' . $id);
    }

    /** 发送重置密码邮件 */
    #[PostMapping(path: '/admin/users/{id}/reset-password')]
    public function sendReset(int $id): ResponseInterface
    {
        $user = User::query()->find($id);
        if ($user === null) {
            return $this->redirect('/admin/users');
        }

        $tokens = $this->container->get(\App\Service\VerificationTokenService::class);
        $mail = $this->container->get(\App\Service\MailService::class);

        $token = $tokens->issue($user, \App\Model\VerificationToken::PURPOSE_PASSWORD_RESET, $user->email);
        $mail->sendPasswordReset($user, $token);

        /** @var User $actor */
        $actor = $this->guard->user();
        $this->audit->log('admin.user.reset_link_sent', 'success', $actor, 'user', (string) $user->id);
        $this->guard->flash('success', '重置密码邮件已发送（开发环境见 runtime/logs/mail.log）。');

        return $this->redirect('/admin/users/' . $id);
    }
}
