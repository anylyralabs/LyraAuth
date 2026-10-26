<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\OauthClient;
use App\Model\OauthScope;
use App\Model\User;
use App\Service\AuditService;
use App\Service\SessionGuard;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 权限范围（scope）管理。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class ScopeController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/admin/scopes')]
    public function index(): ResponseInterface
    {
        return $this->render();
    }

    #[PostMapping(path: '/admin/scopes')]
    public function store(): ResponseInterface
    {
        $identifier = strtolower(trim((string) $this->request->input('identifier', '')));
        $name = trim((string) $this->request->input('name', ''));
        $description = trim((string) $this->request->input('description', ''));

        if (preg_match('/^[a-z][a-z0-9_.:-]{1,63}$/', $identifier) !== 1) {
            return $this->render('标识需以小写字母开头，只含小写字母、数字与 _ . : -，长度 2-64。')->withStatus(422);
        }

        if (OauthScope::query()->where('identifier', $identifier)->exists()) {
            return $this->render('该标识已存在。')->withStatus(422);
        }

        if ($name === '') {
            return $this->render('请填写显示名称。')->withStatus(422);
        }

        $scope = OauthScope::create([
            'identifier' => $identifier,
            'name' => mb_substr($name, 0, 100),
            'description' => $description !== '' ? mb_substr($description, 0, 500) : null,
            'is_default' => $this->request->input('is_default') === '1',
            'is_system' => false,
        ]);

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.scope.created', 'success', $user, 'oauth_scope', $identifier);
        $this->guard->flash('success', "权限范围「{$scope->name}」已创建。");

        return $this->redirect('/admin/scopes');
    }

    #[PostMapping(path: '/admin/scopes/{id}')]
    public function update(int $id): ResponseInterface
    {
        $scope = OauthScope::query()->find($id);
        if ($scope === null) {
            return $this->redirect('/admin/scopes');
        }

        $scope->name = mb_substr(trim((string) $this->request->input('name', $scope->name)), 0, 100);
        $scope->description = mb_substr(trim((string) $this->request->input('description', '')), 0, 500) ?: null;
        $scope->is_default = $this->request->input('is_default') === '1';
        $scope->save();

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.scope.updated', 'success', $user, 'oauth_scope', $scope->identifier);
        $this->guard->flash('success', '权限范围已更新。');

        return $this->redirect('/admin/scopes');
    }

    #[PostMapping(path: '/admin/scopes/{id}/delete')]
    public function destroy(int $id): ResponseInterface
    {
        $scope = OauthScope::query()->find($id);
        if ($scope === null) {
            return $this->redirect('/admin/scopes');
        }

        if ($scope->is_system) {
            $this->guard->flash('error', '系统内置的权限范围不可删除。');

            return $this->redirect('/admin/scopes');
        }

        $identifier = $scope->identifier;
        $scope->delete();

        \App\Model\OauthClientScope::query()->where('scope_identifier', $identifier)->delete();

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.scope.deleted', 'success', $user, 'oauth_scope', $identifier);
        $this->guard->flash('success', '权限范围已删除。');

        return $this->redirect('/admin/scopes');
    }

    private function render(?string $error = null): ResponseInterface
    {
        return $this->view->render('admin.scopes.index', [
            'pageTitle' => '权限范围',
            'pageDescription' => '定义应用可以申请的用户信息与能力。',
            'navSection' => 'admin-scopes',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'scopes' => OauthScope::query()->orderByDesc('is_system')->orderBy('identifier')->get(),
            'error' => $error,
        ]);
    }
}
