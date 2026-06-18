<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Profile\ShowProfile;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App as AppModel;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProfileController implements Loggable
{
    private ?AppModel $activitySubject = null;

    public function __invoke(Request $request, ShowProfile $showProfile): JsonResponse
    {
        $resolution = $this->resolveProfileRequest($request);

        if ($resolution instanceof JsonResponse) {
            return $resolution;
        }

        $this->activitySubject = $resolution['app'];
        $result = $showProfile->handle(
            url: $resolution['request']['url'],
            authMode: $resolution['auth_mode'],
            target: $resolution['target'],
            origin: 'gateway',
            user: $resolution['user'],
        );

        if (($result['success'] ?? false) !== true) {
            $error = $result['error'] ?? [];

            return response()->json([
                'error' => [
                    'code' => is_string($error['code'] ?? null) ? $error['code'] : 'profile_request_failed',
                    'message' => is_string($error['message'] ?? null) ? $error['message'] : 'Failed to complete profile request.',
                    'data' => is_array($error['data'] ?? null) ? $error['data'] : [],
                    'meta' => is_array($error['meta'] ?? null) ? $error['meta'] : [],
                ],
            ], 422);
        }

        return response()->json([
            'success' => [
                'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            ],
        ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $resolution = $this->resolveProfileRequest($request);

        if ($resolution instanceof JsonResponse) {
            return $resolution;
        }

        $this->activitySubject = $resolution['app'];

        return response()->json([
            'success' => [
                'data' => [
                    'auth_mode' => $resolution['auth_mode'],
                    'target' => $resolution['target'],
                    'request' => $resolution['request'],
                ],
            ],
        ]);
    }

    /**
     * @return array{
     *     app: AppModel,
     *     auth_mode: string,
     *     user: string|null,
     *     target: array{app: string, workspace: null, node: string|null, domain: string},
     *     request: array{method: string, url: string, uri: string}
     * }|JsonResponse
     */
    private function resolveProfileRequest(Request $request): array|JsonResponse
    {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ], 403);
        }

        $target = $this->stringQuery($request, 'target') ?? $this->stringQuery($request, 'domain');

        if ($target === null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Profile target is required.',
                    'meta' => [
                        'field' => 'target',
                        'reason' => 'missing_required_input',
                    ],
                ],
            ], 422);
        }

        $uri = $this->normalizedUri($request);

        if ($uri === null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Profile URI must be a non-empty path.',
                    'meta' => [
                        'field' => 'uri',
                        'reason' => 'invalid_path',
                    ],
                ],
            ], 422);
        }

        $app = $this->resolveVisibleApp($target, $caller, $this->stringQuery($request, 'node'));

        if (! $app instanceof AppModel) {
            return response()->json([
                'error' => [
                    'code' => 'app.not_found',
                    'message' => "App '{$target}' not found or not visible.",
                    'meta' => [
                        'app' => $target,
                    ],
                ],
            ], 404);
        }

        $authMode = $this->stringQuery($request, 'auth_mode') ?? 'guest';
        $user = $this->stringQuery($request, 'user');

        return [
            'app' => $app,
            'auth_mode' => $authMode,
            'user' => $user,
            'target' => [
                'app' => $app->name,
                'workspace' => null,
                'node' => $app->node?->name,
                'domain' => $this->domain($app),
            ],
            'request' => [
                'method' => 'GET',
                'url' => $this->profileUrl($app, $uri),
                'uri' => $uri,
            ],
        ];
    }

    private function resolveVisibleApp(string $selector, Node $caller, ?string $nodeConstraint): ?AppModel
    {
        $baseQuery = AppModel::query()
            ->with('node')
            ->when(
                ! app(NodeRoleAssignments::class)->nodeIsGateway($caller),
                fn (Builder $query): Builder => $query->whereIn('node_id', $this->visibleAppNodeIds($caller)),
            )
            ->when(
                $nodeConstraint !== null,
                fn (Builder $query): Builder => $query->whereHas('node', fn (Builder $query): Builder => $query->where('name', $nodeConstraint)),
            );

        if (str_starts_with($selector, '/')) {
            $normalizedSelector = realpath($selector) ?: $selector;

            return $baseQuery->get()->first(function (AppModel $app) use ($normalizedSelector): bool {
                $path = realpath($app->path) ?: $app->path;

                return $normalizedSelector === $path
                    || str_starts_with($normalizedSelector, rtrim($path, '/').'/');
            });
        }

        $nameMatch = (clone $baseQuery)
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof AppModel) {
            return $nameMatch;
        }

        $domainMatch = (clone $baseQuery)
            ->where('domain', $selector)
            ->first();

        if ($domainMatch instanceof AppModel) {
            return $domainMatch;
        }

        return $baseQuery
            ->get()
            ->first(fn (AppModel $app): bool => $this->domain($app) === $selector);
    }

    /**
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller): array
    {
        return DB::table('node_access')
            ->join('nodes', 'nodes.id', '=', 'node_access.serving_node_id')
            ->where('node_access.consumer_node_id', $caller->id)
            ->whereIn('nodes.id', app(NodeRoleAssignments::class)->activeAppHostNodeIds())
            ->where('nodes.status', 'active')
            ->pluck('nodes.id')
            ->all();
    }

    private function profileUrl(AppModel $app, string $uri): string
    {
        return rtrim($app->url(), '/').$uri;
    }

    private function domain(AppModel $app): string
    {
        if (is_string($app->domain) && $app->domain !== '') {
            return $app->domain;
        }

        $host = parse_url($app->url(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $app->name;
    }

    private function normalizedUri(Request $request): ?string
    {
        $uri = $this->stringQuery($request, 'uri') ?? '/';

        if ($uri === '') {
            return null;
        }

        return str_starts_with($uri, '/') ? $uri : "/{$uri}";
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /profile';
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
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
