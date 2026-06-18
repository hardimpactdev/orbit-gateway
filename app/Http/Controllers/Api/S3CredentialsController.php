<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\S3\S3CredentialsAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class S3CredentialsController implements Loggable
{
    private ?Node $activitySubject = null;

    private string $activityNode = '';

    public function __invoke(Request $request, S3CredentialsAction $action): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $nodeName = $this->requestString($request, 'node');

        $result = $action->read($caller, $nodeName);

        if (isset($result['error'])) {
            return $this->errorResponse($result['error']);
        }

        $this->activitySubject = $caller;

        /** @var array{success: array{data: array{credentials: array{node: string, private_endpoint: string, public_endpoints: list<string>, region: string, access_key_id: string, secret_access_key: string, bucket_endpoint_style: string, backend_pool: list<string>}}, meta: array{tool: string}}} $result */
        $this->activityNode = $result['success']['data']['credentials']['node'];

        return response()->json([
            'success' => [
                'data' => $result['success']['data'],
                'meta' => $result['success']['meta'],
            ],
        ]);
    }

    /**
     * @param  array{code: string, message: string, meta: array<string, mixed>, status: int}  $error
     */
    private function errorResponse(array $error): JsonResponse
    {
        $status = match ($error['code']) {
            'authorization_failed' => 403,
            's3.credentials_missing' => 422,
            'validation_failed' => 422,
            default => $error['status'] ?? 400,
        };

        return response()->json([
            'error' => [
                'code' => $error['code'],
                'message' => $error['message'],
                'meta' => $error['meta'] === [] ? (object) [] : $error['meta'],
            ],
        ], $status);
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => (object) [],
            ],
        ], 403);
    }

    private function requestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /s3/credentials';
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
            'node' => $this->activityNode,
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
