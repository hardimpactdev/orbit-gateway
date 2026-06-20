<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class ActivityHistory
{
    /**
     * @param  array{app: string|null, node: string|null, effect: string|null, correlation: string|null, limit: int}  $filters
     * @return array{
     *     activities: list<array<string, mixed>>,
     *     meta: array{
     *         filters: array{app: string|null, node: string|null, effect: string|null, correlation: string|null},
     *         limit: int,
     *         count: int,
     *         has_more: bool
     *     }
     * }
     */
    public function list(array $filters): array
    {
        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->when($filters['effect'] !== null, fn (Builder $query): Builder => $query->where('properties->type', $filters['effect']))
            ->when($filters['correlation'] !== null, fn (Builder $query): Builder => $query->where('batch_uuid', $filters['correlation']))
            ->when($filters['node'] !== null, fn (Builder $query): Builder => $this->applyNodeFilter($query, $filters['node']))
            ->when($filters['app'] !== null, fn (Builder $query): Builder => $this->applyAppFilter($query, $filters['app']))
            ->orderByDesc('id');

        $rows = $query
            ->limit($filters['limit'] + 1)
            ->get();

        $hasMore = $rows->count() > $filters['limit'];
        $activities = $rows
            ->take($filters['limit'])
            ->values();

        return [
            'activities' => $this->activityPayloads($activities),
            'meta' => [
                'filters' => [
                    'app' => $filters['app'],
                    'node' => $filters['node'],
                    'effect' => $filters['effect'],
                    'correlation' => $filters['correlation'],
                ],
                'limit' => $filters['limit'],
                'count' => $activities->count(),
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * @return array{
     *     activity: array<string, mixed>,
     *     related: list<array<string, mixed>>,
     *     meta: array{related_count: int}
     * }|null
     */
    public function show(int $id): ?array
    {
        $activity = Activity::query()
            ->with(['causer', 'subject'])
            ->find($id);

        if (! $activity instanceof Activity) {
            return null;
        }

        $related = $this->relatedActivities($activity);

        return [
            'activity' => $this->activityPayload($activity, includeDetails: true),
            'related' => $related,
            'meta' => [
                'related_count' => count($related),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relatedActivities(Activity $activity): array
    {
        if (! is_string($activity->batch_uuid) || $activity->batch_uuid === '') {
            return [];
        }

        return Activity::query()
            ->with(['causer', 'subject'])
            ->where('batch_uuid', $activity->batch_uuid)
            ->whereKeyNot($activity->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Activity $related): array => [
                'id' => $related->id,
                'occurred_at' => $related->created_at?->toIso8601String(),
                'type' => $related->event,
                'effect' => $related->properties->get('type'),
            ])
            ->all();
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function applyNodeFilter(Builder $query, string $node): Builder
    {
        return $query->where(function (Builder $query) use ($node): void {
            $query
                ->whereHasMorph('causer', [Node::class], fn (Builder $query): Builder => $query->where('name', $node))
                ->orWhereHasMorph('subject', [Node::class], fn (Builder $query): Builder => $query->where('name', $node))
                ->orWhere('properties->node', $node)
                ->orWhere('properties->target_node', $node)
                ->orWhere('properties->serving_node', $node);
        });
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function applyAppFilter(Builder $query, string $app): Builder
    {
        return $query->where(function (Builder $query) use ($app): void {
            $query
                ->whereHasMorph('subject', [App::class], fn (Builder $query): Builder => $query->where('name', $app))
                ->orWhere('properties->app', $app)
                ->orWhere('properties->app_name', $app);
        });
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return list<array<string, mixed>>
     */
    private function activityPayloads(Collection $activities): array
    {
        return $activities->map(fn (Activity $activity): array => $this->activityPayload($activity))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function activityPayload(Activity $activity, bool $includeDetails = false): array
    {
        $payload = [
            'id' => $activity->id,
            'occurred_at' => $activity->created_at?->toIso8601String(),
            'correlation_id' => $activity->batch_uuid,
            'type' => $activity->event,
            'effect' => $activity->properties->get('type'),
            'subject' => $this->subjectPayload($activity->subject),
            'actor' => $this->actorPayload($activity->causer),
            'command' => $activity->properties->get('command'),
            'summary' => $activity->description,
        ];

        if ($includeDetails) {
            $payload['details'] = $activity->properties
                ->except(['type', 'command'])
                ->toArray();
        }

        return $payload;
    }

    /**
     * @return array{type: string, name: string|null}|null
     */
    private function subjectPayload(?Model $subject): ?array
    {
        if ($subject instanceof App) {
            return [
                'type' => 'app',
                'name' => $subject->name,
            ];
        }

        if ($subject instanceof Node) {
            return [
                'type' => 'node',
                'name' => $subject->name,
            ];
        }

        if ($subject !== null) {
            return [
                'type' => Str::of(class_basename($subject))->snake()->toString(),
                'name' => (string) $subject->getKey(),
            ];
        }

        return null;
    }

    /**
     * @return array{node: string|null}|null
     */
    private function actorPayload(?Model $causer): ?array
    {
        if (! $causer instanceof Node) {
            return null;
        }

        return [
            'node' => $causer->name,
        ];
    }
}
