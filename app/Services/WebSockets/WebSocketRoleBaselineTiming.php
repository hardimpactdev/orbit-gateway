<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

final class WebSocketRoleBaselineTiming
{
    /**
     * @var list<array{step: string, milliseconds: int}>
     */
    private array $records = [];

    public function reset(): void
    {
        $this->records = [];
    }

    public function measure(string $step, callable $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->records[] = [
                'step' => $step,
                'milliseconds' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
        }
    }

    public function record(string $step, int $milliseconds): void
    {
        $this->records[] = [
            'step' => $step,
            'milliseconds' => $milliseconds,
        ];
    }

    /**
     * @return list<array{step: string, milliseconds: int}>
     */
    public function records(): array
    {
        return $this->records;
    }
}
