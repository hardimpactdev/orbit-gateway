<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Contracts\RequestProfiler;
use Illuminate\Support\Str;

class ShowProfile
{
    public function __construct(
        private readonly RequestProfiler $profiler,
    ) {}

    /**
     * @param  array{app: string, workspace: string|null, node: string|null, domain: string}  $target
     * @return array<string, mixed>
     */
    public function handle(string $url, string $authMode, array $target, string $origin = 'gateway', ?string $user = null): array
    {
        $requestId = (string) Str::uuid();
        $probe = $this->profiler->profile($url, $this->headers($authMode, $requestId, $user));

        if (! $probe['request']['completed']) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'profile_request_failed',
                    'message' => 'Failed to complete profile request.',
                    'data' => [
                        'request' => $probe['request'],
                        'timings' => $probe['timings'],
                        'profile_error' => $probe['error'],
                    ],
                    'meta' => [
                        'origin' => $origin,
                        'url' => $url,
                    ],
                ],
            ];
        }

        $data = [
            'source' => 'baseline',
            'instrumented' => false,
            'auth_mode' => $authMode,
            'request_id' => $requestId,
            'origin' => $origin,
            'target' => $target,
            ...$probe,
        ];

        $summary = $this->extractToolbarSummary($probe['response_headers']);

        if ($summary !== null) {
            $data['source'] = 'baseline+toolbar';
            $data['instrumented'] = true;
            $data['toolbar'] = $summary;
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $authMode, string $requestId, ?string $user): array
    {
        $headers = [
            'X-REQUEST-ID' => $requestId,
            'X-TOOLBAR-AUTH' => $authMode,
        ];

        if (is_string($user) && $user !== '') {
            $headers['X-TOOLBAR-USER'] = $user;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $responseHeaders
     * @return array<string, mixed>|null
     */
    private function extractToolbarSummary(array $responseHeaders): ?array
    {
        $encoded = $responseHeaders['x-toolbar-summary'] ?? null;

        if ($encoded === null) {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return null;
        }

        $summary = json_decode($decoded, associative: true);

        return is_array($summary) ? $summary : null;
    }
}
