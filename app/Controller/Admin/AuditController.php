<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\AuditLog;
use App\Model\LoginAttempt;
use App\Service\SessionGuard;
use Hyperf\Database\Model\Builder;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 审计日志与登录记录查询。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class AuditController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard
    ) {
    }

    #[GetMapping(path: '/admin/audit-logs')]
    public function index(): ResponseInterface
    {
        $action = trim((string) $this->request->query('action', ''));
        $result = (string) $this->request->query('result', '');
        $keyword = trim((string) $this->request->query('q', ''));
        $tab = (string) $this->request->query('tab', 'audit');

        $logs = null;
        $attempts = null;

        if ($tab === 'logins') {
            $query = LoginAttempt::query();
            if ($keyword !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
                $query->where('identifier', 'like', $like);
            }
            if (in_array($result, ['success', 'bad_credentials', 'locked', 'blocked', 'mfa_failed'], true)) {
                $query->where('result', $result);
            }
            $attempts = $query->orderByDesc('id')->paginate(30);
        } else {
            $query = AuditLog::query();
            if ($action !== '') {
                $query->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%');
            }
            if (in_array($result, ['success', 'failure', 'denied'], true)) {
                $query->where('result', $result);
            }
            if ($keyword !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
                $query->where(function (Builder $q) use ($like) {
                    $q->where('actor_label', 'like', $like)
                        ->orWhere('target_id', 'like', $like)
                        ->orWhere('ip_address', 'like', $like);
                });
            }
            $logs = $query->orderByDesc('id')->paginate(30);
        }

        return $this->view->render('admin.audit.index', [
            'pageTitle' => '审计日志',
            'pageDescription' => '登录、授权与配置变更的完整记录。',
            'navSection' => 'admin-audit',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'tab' => $tab,
            'action' => $action,
            'result' => $result,
            'keyword' => $keyword,
            'logs' => $logs,
            'attempts' => $attempts,
        ]);
    }
}
