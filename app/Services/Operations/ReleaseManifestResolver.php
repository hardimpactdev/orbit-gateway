<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\ReleaseManifest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class ReleaseManifestResolver
{
    public function resolve(): ReleaseManifest
    {
        $configuredSnapshot = config('orbit.updates.manifest_snapshot');

        if (is_array($configuredSnapshot) && $configuredSnapshot !== []) {
            return ReleaseManifest::fromArray($configuredSnapshot);
        }

        $url = config('orbit.updates.release_manifest_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('Release manifest URL is not configured.');
        }

        $url = trim($url);

        if (str_starts_with($url, 'file://')) {
            return ReleaseManifest::fromArray($this->readFileManifest(substr($url, 7)));
        }

        return ReleaseManifest::fromArray($this->downloadManifest($url));
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadManifest(string $url): array
    {
        $response = Http::acceptJson()
            ->timeout((int) config('orbit.updates.release_manifest_timeout_seconds', 10))
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Release manifest download failed with HTTP {$response->status()}.");
        }

        return $this->responseJsonObject($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJsonObject(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Release manifest response must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFileManifest(string $path): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("Release manifest file [{$path}] was not found.");
        }

        try {
            $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Release manifest file must contain a JSON object.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Release manifest file must contain a JSON object.');
        }

        return $payload;
    }
}
