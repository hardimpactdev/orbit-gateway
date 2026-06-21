<?php

declare(strict_types=1);

use App\Services\CurlRequestProfiler;

describe('curl timing derivation', function (): void {
    it('derives TLS timing from microsecond app connect timing when available', function (): void {
        $timings = gatewayCurlProfileTimingsFromInfo([
            'namelookup_time_us' => 2100,
            'connect_time_us' => 6226,
            'appconnect_time_us' => 12837,
            'starttransfer_time_us' => 48550,
            'total_time_us' => 61234,
        ]);

        expect($timings)
            ->toMatchArray([
                'dns_ms' => 2.1,
                'connect_ms' => 6.226,
                'tls_ms' => 6.611,
                'ttfb_ms' => 48.55,
                'total_ms' => 61.234,
            ]);
    });

    it('prefers microsecond timing over second timing', function (): void {
        $timings = gatewayCurlProfileTimingsFromInfo([
            'connect_time' => 0.006,
            'appconnect_time' => 0.006,
            'connect_time_us' => 6000,
            'appconnect_time_us' => 13000,
        ]);

        expect($timings['tls_ms'])
            ->toBeGreaterThan(6.999)
            ->toBeLessThan(7.001);
    });

    it('falls back to pretransfer timing when app connect timing is absent', function (): void {
        $timings = gatewayCurlProfileTimingsFromInfo([
            'connect_time_us' => 6000,
            'pretransfer_time_us' => 13000,
        ]);

        expect($timings['tls_ms'])
            ->toBeGreaterThan(6.999)
            ->toBeLessThan(7.001);
    });

    it('preserves second-based TLS timing derivation', function (): void {
        $timings = gatewayCurlProfileTimingsFromInfo([
            'connect_time' => 0.006,
            'appconnect_time' => 0.013,
        ]);

        expect($timings['tls_ms'])
            ->toBeGreaterThan(6.999)
            ->toBeLessThan(7.001);
    });

    it('clamps negative TLS timing to zero', function (): void {
        $timings = gatewayCurlProfileTimingsFromInfo([
            'connect_time_us' => 13000,
            'appconnect_time_us' => 6000,
        ]);

        expect($timings['tls_ms'])->toBe(0.0);
    });
});

it('profiles a completed local http request', function (): void {
    $documentRoot = sys_get_temp_dir().'/orbit-curl-profiler-test';

    if (! is_dir($documentRoot)) {
        mkdir($documentRoot, 0777, true);
    }

    file_put_contents($documentRoot.'/index.php', <<<'PHP'
<?php
header('Content-Type: text/plain');
echo 'profile-ok';
PHP);

    [$process, $pipes, $port] = startProfileTestServer($documentRoot);

    try {
        $result = app(CurlRequestProfiler::class)->profile("http://127.0.0.1:{$port}/index.php?probe=1");

        expect($result['request']['method'])->toBe('GET')
            ->and($result['request']['url'])->toBe("http://127.0.0.1:{$port}/index.php?probe=1")
            ->and($result['request']['uri'])->toBe('/index.php?probe=1')
            ->and($result['request']['status'])->toBe(200)
            ->and($result['request']['bytes'])->toBeGreaterThan(0)
            ->and($result['request']['completed'])->toBeTrue()
            ->and($result['timings']['dns_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['connect_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['tls_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['ttfb_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['download_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['total_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['response_headers']['content-type'])->toContain('text/plain')
            ->and($result['error'])->toBeNull();
    } finally {
        stopProfileTestServer($process, $pipes);
    }
});

/**
 * @return array{resource, array<int, resource>, int}
 */
function startProfileTestServer(string $documentRoot): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException("Failed to reserve a port: {$errorMessage}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $port = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);
    $command = sprintf(
        'exec %s -S 127.0.0.1:%d -t %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($documentRoot),
    );

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start PHP HTTP server.');
    }

    $deadline = microtime(true) + 5.0;

    do {
        $status = proc_get_status($process);

        if (! $status['running']) {
            $stderr = stream_get_contents($pipes[2]);
            stopProfileTestServer($process, $pipes);

            throw new RuntimeException('PHP HTTP server exited early: '.trim($stderr));
        }

        if (profileTestServerIsReady($port)) {
            return [$process, $pipes, $port];
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    stopProfileTestServer($process, $pipes);

    throw new RuntimeException('Timed out waiting for the PHP HTTP server to start.');
}

function profileTestServerIsReady(int $port): bool
{
    $handle = curl_init("http://127.0.0.1:{$port}/index.php");

    if ($handle === false) {
        return false;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 100,
        CURLOPT_CONNECTTIMEOUT_MS => 100,
    ]);

    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    return $status === 200 && $response === 'profile-ok';
}

/**
 * @param  array<string, mixed>  $info
 * @return array{dns_ms: float, connect_ms: float, tls_ms: float, ttfb_ms: float, download_ms: float, total_ms: float}
 */
function gatewayCurlProfileTimingsFromInfo(array $info): array
{
    $method = new ReflectionMethod(CurlRequestProfiler::class, 'timingsFromCurlInfo');
    /** @var array{dns_ms: float, connect_ms: float, tls_ms: float, ttfb_ms: float, download_ms: float, total_ms: float} $timings */
    $timings = $method->invoke(new CurlRequestProfiler, $info);

    return $timings;
}

/**
 * @param  resource  $process
 * @param  array<int, resource>  $pipes
 */
function stopProfileTestServer($process, array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_terminate($process);
    proc_close($process);
}
