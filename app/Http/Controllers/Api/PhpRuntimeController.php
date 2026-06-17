<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Php\PhpRuntimeFailure;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class PhpRuntimeController implements Loggable
{
    public function __construct(
        private PhpRuntimeManager $php,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->failure(new PhpRuntimeFailure('authorization_failed', 'Peer identity unknown.'));
        }

        $node = $this->resolvedNodeName($request);

        if (! $this->canAccessNode($caller, $node)) {
            return $this->failure(new PhpRuntimeFailure('authorization_failed', 'This node is not authorized to inspect the PHP runtime target.', [
                'node' => $node,
            ]));
        }

        $result = $this->php->view(
            app: $this->nullableString($request->query('app')),
            workspace: $this->nullableString($request->query('workspace')),
            node: $this->nullableString($request->query('node')),
            live: filter_var($request->query('live'), FILTER_VALIDATE_BOOL),
        );

        if ($result->failed()) {
            return $this->failure($result->failure);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'php' => $result->payload,
                ],
                'meta' => $result->meta === [] ? (object) [] : $result->meta,
            ],
        ]);
    }

    private function canAccessNode(Node $caller, ?string $node): bool
    {
        if ($caller->hasActiveRole('gateway')) {
            return true;
        }

        if ($node === null) {
            return true;
        }

        return Node::query()
            ->where('name', $node)
            ->whereHas('consumingNodes', fn ($query) => $query->whereKey($caller->id))
            ->exists();
    }

    private function resolvedNodeName(Request $request): ?string
    {
        $node = $this->nullableString($request->query('node'));

        if ($node !== null) {
            return $node;
        }

        $appSelector = $this->nullableString($request->query('app'));

        if ($appSelector !== null) {
            $app = App::query()
                ->with('node')
                ->where('name', $appSelector)
                ->orWhere('domain', $appSelector)
                ->first();

            return $app?->node?->name;
        }

        $workspaceSelector = $this->nullableString($request->query('workspace'));

        if ($workspaceSelector === null) {
            return null;
        }

        $workspace = Workspace::query()
            ->with('app.node')
            ->where('name', $workspaceSelector)
            ->first();

        return $workspace?->app?->node?->name;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function failure(?PhpRuntimeFailure $failure): JsonResponse
    {
        $failure ??= new PhpRuntimeFailure('validation_failed', 'Required input is missing or invalid.');

        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $failure->meta,
            ],
        ], $failure->code === 'authorization_failed' ? 403 : 400);
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
        return 'api:GET /php/runtime';
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
