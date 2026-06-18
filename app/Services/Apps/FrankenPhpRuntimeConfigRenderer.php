<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;

final readonly class FrankenPhpRuntimeConfigRenderer
{
    private const array AppDevelopmentThreadPoolLines = [
        'max_threads auto',
        'max_idle_time 1h',
    ];

    public function classic(App $app): ?string
    {
        $lines = $this->threadPoolLines($app);

        if ($lines === []) {
            return null;
        }

        return $this->render($lines);
    }

    public function worker(App $app, string $workerFile, string|int $workers): string
    {
        return $this->render([
            ...$this->threadPoolLines($app),
            ...$this->workerLines($workerFile, $workers),
        ]);
    }

    /**
     * @return list<string>
     */
    private function threadPoolLines(App $app): array
    {
        $app->loadMissing('node.roleAssignments');

        if ($app->node?->hasActiveRole('app-dev') !== true) {
            return [];
        }

        return self::AppDevelopmentThreadPoolLines;
    }

    /**
     * @return list<string>
     */
    private function workerLines(string $workerFile, string|int $workers): array
    {
        $lines = [
            'worker {',
            "\tfile {$workerFile}",
        ];

        if (is_int($workers) && $workers > 0) {
            $lines[] = "\tnum {$workers}";
        }

        $lines[] = '}';

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function render(array $lines): string
    {
        return implode("\n", $lines);
    }
}
