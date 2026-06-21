<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class WorkspaceReadinessProbe
{
    public function __construct(
        private int $maxAttempts = 10,
        private int $retryDelayMilliseconds = 1_000,
    ) {}

    /**
     * @return array{reachable: bool, status: string}
     */
    public function probe(Workspace $workspace): array
    {
        return $this->probeWith(fn (): array => $this->probeOnce($workspace));
    }

    /**
     * @param  callable(): array{reachable: bool, status: string}  $attempt
     * @return array{reachable: bool, status: string}
     */
    public function probeWith(callable $attempt): array
    {
        $result = ['reachable' => false, 'status' => 'not_run'];

        for ($attemptNumber = 1; $attemptNumber <= $this->maxAttempts; $attemptNumber++) {
            $result = $attempt();

            if ($result['reachable'] || ! $this->shouldRetry($result['status'])) {
                return $result;
            }

            if ($attemptNumber < $this->maxAttempts && $this->retryDelayMilliseconds > 0) {
                usleep($this->retryDelayMilliseconds * 1_000);
            }
        }

        return $result;
    }

    /**
     * @return array{reachable: bool, status: string}
     */
    private function probeOnce(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.node']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            return ['reachable' => false, 'status' => 'no_app'];
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            return ['reachable' => false, 'status' => 'no_node'];
        }

        $url = $workspace->url();

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutVerifying()
                ->get($url);
        } catch (Throwable $exception) {
            return ['reachable' => false, 'status' => 'error: '.$exception->getMessage()];
        }

        $statusCode = $response->status();
        if ($statusCode >= 500) {
            return ['reachable' => false, 'status' => (string) $statusCode];
        }

        $assetStatus = $this->probeViteAssets($url, $response->body());

        if ($assetStatus !== null) {
            return $assetStatus;
        }

        return ['reachable' => true, 'status' => (string) $statusCode];
    }

    /**
     * @return array{reachable: false, status: string}|null
     */
    private function probeViteAssets(string $baseUrl, string $html): ?array
    {
        foreach ($this->viteAssetUrls($baseUrl, $html) as $assetUrl) {
            try {
                $response = Http::timeout(10)
                    ->connectTimeout(5)
                    ->withoutVerifying()
                    ->get($assetUrl);
            } catch (Throwable $exception) {
                return ['reachable' => false, 'status' => 'asset_error: '.$exception->getMessage()];
            }

            if ($response->status() >= 400) {
                return ['reachable' => false, 'status' => 'asset_'.$response->status()];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function viteAssetUrls(string $baseUrl, string $html): array
    {
        preg_match_all('/<script\b[^>]*\btype=["\']module["\'][^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);

        $urls = [];

        foreach ($matches[1] as $src) {
            $url = $this->absoluteUrl($baseUrl, $src);
            $path = parse_url($url, PHP_URL_PATH) ?: '';

            if (str_starts_with($path, '/@vite/') || str_starts_with($path, '/@react-refresh') || str_starts_with($path, '/resources/')) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function absoluteUrl(string $baseUrl, string $src): string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: '';
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $authority = $host.($port !== null ? ":{$port}" : '');

        return "{$scheme}://{$authority}/".ltrim($src, '/');
    }

    private function shouldRetry(string $status): bool
    {
        if (str_starts_with($status, 'asset_')) {
            return true;
        }

        if (str_starts_with($status, 'error: ')) {
            return str_contains($status, 'Operation timed out')
                || str_contains($status, 'Connection refused')
                || str_contains($status, 'Connection reset')
                || str_contains($status, 'Empty reply from server');
        }

        return in_array($status, ['000', '500', '502', '503', '504'], true);
    }
}
