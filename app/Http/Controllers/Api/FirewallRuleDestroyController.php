<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FirewallRuleDestroyController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(string $name, Request $request, FirewallRuleIntent $intent): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('destructive_consent_required', 'Use --force to remove this firewall rule.', ['field' => 'force'], 422);
        }

        $node = $this->optionalString($request, 'node');

        if ($node === null) {
            return $this->error('validation_failed', 'A firewall target node is required.', ['field' => 'node'], 422);
        }

        try {
            $result = $intent->remove($name, $node, $caller);
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->statusFor($e));
        }

        $this->activitySubject = $caller;

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => $result['meta'],
            ],
        ]);
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function statusFor(GatewayApiException $e): int
    {
        return match ($e->errorCode()) {
            'authorization_failed' => 403,
            'firewall_rule.baseline_conflict' => 409,
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
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /api/firewall-rules/{name}';
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
        return ['name' => request()->route('name')];
    }

    public function description(): ?string
    {
        return null;
    }
}
