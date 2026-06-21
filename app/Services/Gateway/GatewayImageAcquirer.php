<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class GatewayImageAcquirer
{
    public function ensure(GatewayImageReference $image, ?string $archive = null): void
    {
        $archive = is_string($archive) && trim($archive) !== '' ? trim($archive) : null;

        if ($archive !== null) {
            $this->run('docker load -i '.escapeshellarg($archive), 'load gateway image archive');
        } else {
            $this->run('docker pull '.escapeshellarg($image->canonical()), 'pull gateway image');
        }

        $this->run('docker image inspect '.escapeshellarg($image->canonical()), 'inspect gateway image');
    }

    private function run(string $command, string $step): void
    {
        $result = Process::timeout(300)->run($command);

        if ($result->successful()) {
            return;
        }

        $message = trim($result->errorOutput().' '.$result->output());

        throw new RuntimeException("Failed to {$step}: ".($message !== '' ? $message : 'unknown error'));
    }
}
