<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Proxy\ProxyRouteIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProxyRouteStoreController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(Request $request, ProxyRouteIntent $intent): JsonResponse
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
            $result = $intent->add(
                domain: $input['domain'],
                nodeName: $input['node'],
                upstream: $input['upstream'],
                redirect: $input['redirect'],
                code: $input['code'],
                force: $input['force'],
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
     * @return array{domain: string, node: string, upstream: ?string, redirect: ?string, code: ?int, force: bool}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $domain = $this->optionalString($request, 'domain');
        $node = $this->optionalString($request, 'node');
        $upstream = $this->optionalString($request, 'upstream');
        $redirect = $this->optionalString($request, 'redirect');
        $code = $request->input('code');

        if ($domain === null) {
            return $this->error('validation_failed', 'The proxy route domain is required.', ['field' => 'domain'], 422);
        }

        if ($node === null) {
            return $this->error('validation_failed', 'A serving node is required.', ['field' => 'node'], 422);
        }

        if ($code !== null && ! in_array((int) $code, [301, 302, 307, 308], true)) {
            return $this->error('validation_failed', 'Invalid redirect code.', [
                'field' => 'code',
                'allowed' => [301, 302, 307, 308],
            ], 422);
        }

        return [
            'domain' => $domain,
            'node' => $node,
            'upstream' => $upstream,
            'redirect' => $redirect,
            'code' => $code === null ? null : (int) $code,
            'force' => $request->boolean('force'),
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
            'proxy.domain_conflict', 'proxy.replacement_consent_required' => 409,
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
        return 'api:POST /proxy-routes';
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
            'domain' => $this->optionalString(request(), 'domain'),
            'node' => $this->optionalString(request(), 'node'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
