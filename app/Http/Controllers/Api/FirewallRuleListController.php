<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class FirewallRuleListController implements Loggable
{
    public function __construct(
        private FirewallRuleQuery $rules,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->fail('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $result = $this->rules->list(
                node: $this->stringQuery($request, 'node'),
                caller: $caller,
            );
        } catch (GatewayApiException $e) {
            return $this->fail(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $e->errorCode() === 'authorization_failed' ? 403 : 400,
            );
        }

        return response()->json([
            'success' => [
                'data' => [
                    'rules' => $result['rules'],
                ],
                'meta' => $result['meta'],
            ],
        ]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, string $message, array $meta, int $status): JsonResponse
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
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /api/firewall-rules';
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
