<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolPayloadMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ToolListController implements Loggable
{
    use ResolvesVisibleToolNodes;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller, includeMetricsExporterNodes: true);

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to read the tool registry.');
        }

        $node = $request->query('node');
        $app = $request->query('app');
        $nodeFilter = null;

        if (is_string($node) && $node !== '') {
            $nodeFilter = $this->resolveNodeFilter($node, $caller, $visibleNodeIds, includeMetricsExporterNodes: true);

            if (! $nodeFilter instanceof Node) {
                return $this->validationFailed('node', $node, "Invalid value for --node: '{$node}'. Expected a visible tool node name.");
            }
        }

        if (is_string($app) && $app !== '') {
            $appNode = $this->resolveAppNodeFilter($app, $caller, $visibleNodeIds);

            if (! $appNode instanceof Node) {
                return $this->validationFailed('app', $app, "Invalid value for --app: '{$app}'. Expected a visible app name or domain.");
            }

            if ($nodeFilter instanceof Node && $nodeFilter->id !== $appNode->id) {
                return $this->validationFailed('app', $app, "Invalid value for --app: '{$app}'. App is not owned by the selected node.");
            }

            $nodeFilter = $appNode;
        }

        $tools = $this->fetchTools($visibleNodeIds, $nodeFilter);

        return response()->json([
            'success' => [
                'data' => [
                    'tools' => $this->toolPayloads($tools),
                ],
            ],
        ]);
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return Collection<int, NodeTool>
     */
    private function fetchTools(array $visibleNodeIds, ?Node $node): Collection
    {
        return NodeTool::query()
            ->with('node')
            ->whereIn('node_id', $visibleNodeIds)
            ->when($node instanceof Node, fn (Builder $query): Builder => $query->where('node_id', $node->id))
            ->get()
            ->sort(fn (NodeTool $first, NodeTool $second): int => [
                mb_strtolower((string) $first->node?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->node?->name),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, NodeTool>  $tools
     * @return list<array<string, mixed>>
     */
    private function toolPayloads(Collection $tools): array
    {
        return $tools
            ->map(fn (NodeTool $tool): array => app(ToolPayloadMapper::class)->toArray($tool))
            ->all();
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => [],
            ],
        ], 403);
    }

    private function validationFailed(string $field, string $value, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => [
                    'field' => $field,
                    'value' => $value,
                ],
            ],
        ], 400);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /tools';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
