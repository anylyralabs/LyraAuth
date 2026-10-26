<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hyperf\ViewEngine\Compiler\CompilerInterface;
use Psr\Container\ContainerInterface;

/**
 * 注册视图自定义指令。
 *
 * view-engine 是 Blade 的子集：@csrf / @method / @asset 等 Laravel 惯例指令
 * 并不存在，这里补齐项目所需的少量指令，模板只使用已验证可用的语法。
 *
 * 必须挂在 BeforeWorkerStart 上：指令保存在 worker 进程内的 BladeCompiler 实例里，
 * 主进程注册不会传递给 worker。
 */
#[Listener]
class ViewDirectiveListener implements ListenerInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        $compiler = $this->container->get(CompilerInterface::class);

        // 静态资源地址：@asset('app.css')
        $compiler->directive('asset', static function (string $expression): string {
            $expression = trim(trim($expression), '()');

            return "<?php echo \\App\\Support\\View::asset({$expression}); ?>";
        });

        // 站内绝对地址：@url('/dashboard')
        $compiler->directive('url', static function (string $expression): string {
            $expression = trim(trim($expression), '()');

            return "<?php echo \\App\\Support\\View::url({$expression}); ?>";
        });

        // CSRF 隐藏字段：@csrf
        $compiler->directive('csrf', static function (): string {
            return "<?php echo \\csrf_field(); ?>";
        });

        // 表单方法伪装：@method('PUT')
        $compiler->directive('method', static function (string $expression): string {
            $expression = trim(trim($expression), '()');

            return "<?php echo '<input type=\"hidden\" name=\"_method\" value=\"' . htmlspecialchars((string) ({$expression}), ENT_QUOTES, 'UTF-8') . '\">'; ?>";
        });

        // 相对时间：@since($timestamp)
        $compiler->directive('since', static function (string $expression): string {
            $expression = trim(trim($expression), '()');

            return "<?php echo \\App\\Support\\View::relativeTime({$expression}); ?>";
        });
    }
}
