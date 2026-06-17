<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Cloudflare\CloudflareManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CloudflareController implements Loggable
{
    private ?Node $activitySubject = null;

    #[RequiresPermission('cf:zone:list', servingNode: ServingNode::Gateway)]
    public function zones(Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        return $this->run(fn (): array => $cloudflare->listZones());
    }

    #[RequiresPermission('cf:dns:list', servingNode: ServingNode::Gateway)]
    public function dnsRecords(string $zone, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        return $this->run(fn (): array => $cloudflare->listDnsRecords($zone));
    }

    #[RequiresPermission('cf:dns:add', servingNode: ServingNode::Gateway)]
    public function storeDnsRecord(string $zone, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        $name = $this->stringInput($request, 'name');
        $content = $this->stringInput($request, 'content');

        if ($name === null || $content === null) {
            return $this->error('validation_failed', 'DNS record name and content are required.', ['field' => $name === null ? 'name' : 'content'], 422);
        }

        return $this->run(fn (): array => $cloudflare->addDnsRecord(
            name: $name,
            content: $content,
            type: $this->stringInput($request, 'type') ?? 'A',
            zoneIdentifier: $zone,
            proxied: $request->boolean('proxied'),
        ));
    }

    #[RequiresPermission('cf:dns:remove', servingNode: ServingNode::Gateway)]
    public function removeDnsRecord(string $zone, string $record, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        if (! $request->boolean('destructive_consent')) {
            return $this->error('validation_failed', 'Removing a Cloudflare DNS record requires --force in non-interactive mode.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        return $this->run(fn (): array => $cloudflare->removeDnsRecord($record, $zone));
    }

    #[RequiresPermission('cf:cache:flush', servingNode: ServingNode::Gateway)]
    public function flushCache(Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        $zone = $this->stringInput($request, 'zone');

        if ($zone === null) {
            return $this->error('validation_failed', 'A Cloudflare zone is required.', ['field' => 'zone'], 422);
        }

        return $this->run(fn (): array => $cloudflare->flushCache($zone));
    }

    #[RequiresPermission('cf:cache:rule:add', servingNode: ServingNode::Gateway)]
    public function addCacheRule(string $app, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        return $this->run(fn (): array => $cloudflare->addCacheRule($app));
    }

    #[RequiresPermission('cf:cache:rule:remove', servingNode: ServingNode::Gateway)]
    public function removeCacheRule(string $app, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        if (! $request->boolean('destructive_consent')) {
            return $this->error('validation_failed', 'Removing a Cloudflare cache rule requires --force in non-interactive mode.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        return $this->run(fn (): array => $cloudflare->removeCacheRule($app));
    }

    #[RequiresPermission('cf:ssl:enable', servingNode: ServingNode::Gateway)]
    public function enableSsl(string $zone, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        $mode = $this->stringInput($request, 'mode') ?? 'strict';

        return $this->run(fn (): array => $cloudflare->enableSsl($zone, $mode));
    }

    #[RequiresPermission('cf:ssl:disable', servingNode: ServingNode::Gateway)]
    public function disableSsl(string $zone, Request $request, CloudflareManager $cloudflare): JsonResponse
    {
        $this->captureActivitySubject($request);

        if (! $request->boolean('destructive_consent')) {
            return $this->error('validation_failed', 'Disabling Cloudflare SSL requires --force in non-interactive mode.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        return $this->run(fn (): array => $cloudflare->disableSsl($zone));
    }

    /**
     * @param  callable(): array{data: array<string, mixed>, meta: array<string, mixed>}  $callback
     */
    private function run(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
        } catch (GatewayApiException $exception) {
            return $this->error(
                code: $exception->errorCode() ?? 'cloudflare_unavailable',
                message: $exception->getMessage(),
                meta: $exception->errorMeta(),
                status: $this->statusFor($exception),
            );
        }

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => $result['meta'],
            ],
        ]);
    }

    private function captureActivitySubject(Request $request): void
    {
        /** @var mixed $caller */
        $caller = $request->user();

        $this->activitySubject = $caller instanceof Node ? $caller : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function statusFor(GatewayApiException $exception): int
    {
        return match ($exception->errorCode()) {
            'authorization_failed' => 403,
            'cloudflare_unavailable' => 503,
            default => 422,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return request()->isMethod('GET') ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:'.request()->method().' /'.request()->path();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'zone' => $this->stringInput(request(), 'zone'),
            'app' => request()->route('app'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
