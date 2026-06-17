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

final class FirewallRuleStoreController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(Request $request, FirewallRuleIntent $intent): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $input = $this->validatedInput($request);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            $result = $intent->store(
                action: $input['action'],
                name: $input['name'],
                nodeName: $input['node'],
                direction: $input['direction'],
                source: $input['source'],
                destination: $input['destination'],
                port: $input['port'],
                protocol: $input['protocol'],
                reason: $input['reason'],
                caller: $caller,
            );
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

    /**
     * @return array{action: string, name: string, node: string, direction: string, source: string, destination: ?string, port: string, protocol: string, reason: ?string}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $action = $this->optionalString($request, 'action');
        $name = $this->optionalString($request, 'name');
        $node = $this->optionalString($request, 'node');
        $port = $this->optionalString($request, 'port');

        if ($action === null || $name === null || $node === null || $port === null) {
            return $this->error('validation_failed', 'Required firewall rule input is missing.', ['fields' => ['action', 'name', 'node', 'port']], 422);
        }

        return [
            'action' => $action,
            'name' => $name,
            'node' => $node,
            'direction' => $this->optionalString($request, 'direction') ?? 'incoming',
            'source' => $this->optionalString($request, 'source') ?? 'any',
            'destination' => $this->optionalString($request, 'destination'),
            'port' => $port,
            'protocol' => $this->optionalString($request, 'protocol') ?? 'tcp',
            'reason' => $this->optionalString($request, 'reason'),
        ];
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
            'firewall_rule.name_collision', 'firewall_rule.baseline_conflict' => 409,
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
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /api/firewall-rules';
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
            'name' => $this->optionalString(request(), 'name'),
            'node' => $this->optionalString(request(), 'node'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
