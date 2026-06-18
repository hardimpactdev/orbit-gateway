<?php

declare(strict_types=1);

namespace App\Support\Streaming;

final readonly class ProgressEventStreamEmitter
{
    public function __construct(
        private string $sapi = PHP_SAPI,
    ) {}

    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     */
    public function tree(string $title, array $steps): void
    {
        $this->emit('tree', [
            'title' => $title,
            'steps' => $steps,
        ]);
    }

    public function stepEvent(string $key, string $status, ?string $message = null): void
    {
        $payload = [
            'key' => $key,
            'status' => $status,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $this->emit('step', $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(int $exitCode, array $data = []): void
    {
        $this->emit('complete', [
            'exit_code' => $exitCode,
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function error(string $message, int $exitCode = 1, array $data = []): void
    {
        $this->emit('error', [
            'exit_code' => $exitCode,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function heartbeat(): void
    {
        echo ": heartbeat\n\n";
        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function event(string $event, array $payload, ?int $id = null): void
    {
        $this->emit($event, $payload, $id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload, ?int $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (! in_array($this->sapi, ['fpm-fcgi', 'cli-server', 'frankenphp'], true)) {
            return;
        }

        @ob_flush();
        @flush();
    }
}
