<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\AbstractController;
use App\Middleware\AuthenticateMiddleware;
use App\Model\Organization;
use App\Model\OrganizationMember;
use App\Model\User;
use App\Service\AuditService;
use App\Service\SessionGuard;
use App\Support\Support;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 组织：用户可创建组织、管理成员。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/organizations')]
    public function index(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $organizations = Organization::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('name')
            ->get();

        $roles = OrganizationMember::query()
            ->where('user_id', $user->id)
            ->pluck('role', 'organization_id')
            ->all();

        return $this->view->render('organizations.index', [
            'pageTitle' => '组织',
            'pageDescription' => '组织用于对成员与接入应用进行分组管理。',
            'navSection' => 'organizations',
            'currentUser' => $user,
            'isAdmin' => $user->isAdmin(),
            'organizations' => $organizations,
            'roles' => $roles,
        ]);
    }

    #[PostMapping(path: '/organizations')]
    public function store(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $name = trim((string) $this->request->input('name', ''));
        $slug = strtolower(trim((string) $this->request->input('slug', '')));
        $description = trim((string) $this->request->input('description', ''));

        if ($name === '' || mb_strlen($name) > 100) {
            $this->guard->flash('error', '请填写组织名称（不超过 100 字）。');

            return $this->redirect('/organizations');
        }

        if ($slug === '') {
            $slug = 'org-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $slug) !== 1) {
            $this->guard->flash('error', '标识需 3-64 位，仅含小写字母、数字与连字符。');

            return $this->redirect('/organizations');
        }

        if (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $this->guard->flash('error', '该标识已被使用。');

            return $this->redirect('/organizations');
        }

        $organization = Organization::create([
            'uuid' => Support::uuid(),
            'slug' => $slug,
            'name' => mb_substr($name, 0, 100),
            'description' => $description !== '' ? mb_substr($description, 0, 500) : null,
            'owner_id' => $user->id,
            'status' => 'active',
        ]);

        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationMember::ROLE_OWNER,
            'joined_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log('organization.created', 'success', $user, 'organization', $organization->uuid, ['slug' => $slug]);
        $this->guard->flash('success', "组织「{$organization->name}」已创建。");

        return $this->redirect('/organizations/' . $organization->slug);
    }

    #[GetMapping(path: '/organizations/{slug}')]
    public function show(string $slug): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $organization = Organization::query()->where('slug', $slug)->first();
        if ($organization === null) {
            return $this->redirect('/organizations');
        }

        $role = $organization->roleOf($user->id);
        if ($role === null && ! $user->isAdmin()) {
            return $this->redirect('/organizations');
        }

        $members = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->get();

        $memberUsers = User::query()
            ->whereIn('id', $members->pluck('user_id')->all())
            ->get()
            ->keyBy('id');

        return $this->view->render('organizations.show', [
            'pageTitle' => $organization->name,
            'pageDescription' => $organization->description ?: '组织成员与应用。',
            'navSection' => 'organizations',
            'currentUser' => $user,
            'isAdmin' => $user->isAdmin(),
            'organization' => $organization,
            'role' => $role,
            'canManage' => $organization->acceptsManagementBy($user->id) || $user->isAdmin(),
            'members' => $members,
            'memberUsers' => $memberUsers,
        ]);
    }

    /** 添加成员 */
    #[PostMapping(path: '/organizations/{slug}/members')]
    public function addMember(string $slug): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $organization = Organization::query()->where('slug', $slug)->first();
        if ($organization === null) {
            return $this->redirect('/organizations');
        }

        if (! $organization->acceptsManagementBy($user->id) && ! $user->isAdmin()) {
            $this->guard->flash('error', '只有组织管理员可以添加成员。');

            return $this->redirect('/organizations/' . $slug);
        }

        $email = strtolower(trim((string) $this->request->input('email', '')));
        $role = (string) $this->request->input('role', OrganizationMember::ROLE_MEMBER);
        if (! in_array($role, [OrganizationMember::ROLE_ADMIN, OrganizationMember::ROLE_MEMBER], true)) {
            $role = OrganizationMember::ROLE_MEMBER;
        }

        $target = User::query()->where('email', $email)->first();
        if ($target === null) {
            $this->guard->flash('error', '未找到该邮箱对应的账号。');

            return $this->redirect('/organizations/' . $slug);
        }

        $exists = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $target->id)
            ->exists();

        if ($exists) {
            $this->guard->flash('error', '该用户已在组织中。');

            return $this->redirect('/organizations/' . $slug);
        }

        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $target->id,
            'role' => $role,
            'joined_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log('organization.member_added', 'success', $user, 'organization', $organization->uuid, [
            'member' => $target->email,
            'role' => $role,
        ]);
        $this->guard->flash('success', "已添加 {$target->display_name}。");

        return $this->redirect('/organizations/' . $slug);
    }

    /** 修改成员角色 */
    #[PostMapping(path: '/organizations/{slug}/members/{id}/role')]
    public function updateMemberRole(string $slug, int $id): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $organization = Organization::query()->where('slug', $slug)->first();
        $member = OrganizationMember::query()
            ->where('id', $id)
            ->where('organization_id', $organization?->id)
            ->first();

        if ($organization === null || $member === null) {
            return $this->redirect('/organizations');
        }

        if (! $organization->acceptsManagementBy($user->id) && ! $user->isAdmin()) {
            $this->guard->flash('error', '没有管理权限。');

            return $this->redirect('/organizations/' . $slug);
        }

        if ($member->role === OrganizationMember::ROLE_OWNER) {
            $this->guard->flash('error', '不能修改组织拥有者的角色。');

            return $this->redirect('/organizations/' . $slug);
        }

        $role = (string) $this->request->input('role', OrganizationMember::ROLE_MEMBER);
        if (! in_array($role, [OrganizationMember::ROLE_ADMIN, OrganizationMember::ROLE_MEMBER], true)) {
            $role = OrganizationMember::ROLE_MEMBER;
        }

        $member->role = $role;
        $member->save();

        $this->audit->log('organization.member_role_changed', 'success', $user, 'organization', $organization->uuid, [
            'member_id' => $member->user_id,
            'role' => $role,
        ]);
        $this->guard->flash('success', '成员角色已更新。');

        return $this->redirect('/organizations/' . $slug);
    }

    /** 移除成员 */
    #[PostMapping(path: '/organizations/{slug}/members/{id}/remove')]
    public function removeMember(string $slug, int $id): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $organization = Organization::query()->where('slug', $slug)->first();
        $member = OrganizationMember::query()
            ->where('id', $id)
            ->where('organization_id', $organization?->id)
            ->first();

        if ($organization === null || $member === null) {
            return $this->redirect('/organizations');
        }

        if (! $organization->acceptsManagementBy($user->id) && ! $user->isAdmin()) {
            $this->guard->flash('error', '没有管理权限。');

            return $this->redirect('/organizations/' . $slug);
        }

        if ($member->role === OrganizationMember::ROLE_OWNER) {
            $this->guard->flash('error', '不能移除组织拥有者。');

            return $this->redirect('/organizations/' . $slug);
        }

        $member->delete();

        $this->audit->log('organization.member_removed', 'success', $user, 'organization', $organization->uuid, [
            'member_id' => $member->user_id,
        ]);
        $this->guard->flash('success', '成员已移除。');

        return $this->redirect('/organizations/' . $slug);
    }
}
