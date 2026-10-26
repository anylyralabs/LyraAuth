<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;

/**
 * 主进程启动前创建运行期目录。
 *
 * 多个 worker 会同时实例化 BladeCompiler，若目录不存在会并发 mkdir 而报
 * “File exists”，因此统一在主进程里预建。
 */
#[Listener]
class RuntimeDirectoryListener implements ListenerInterface
{
    private const DIRECTORIES = [
        '/runtime',
        '/runtime/cache',
        '/runtime/container',
        '/runtime/logs',
        '/runtime/session',
        '/runtime/view',
        '/storage/keys/oauth',
    ];

    public function listen(): array
    {
        return [
            BeforeMainServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        foreach (self::DIRECTORIES as $directory) {
            $path = BASE_PATH . $directory;
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
        }
    }
}
