<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Activity\ActivityHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;

#[RequiresPermission('activity:read', servingNode: ServingNode::Gateway)]
final class ActivityShowController implements Loggable
{
    private ?Activity $activity = null;

    private int $activityId = 0;

    private int $relatedCount = 0;

    private string $outcome = 'not_found';

    public function __invoke(string $id, ActivityHistory $history): JsonResponse
    {
        if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Activity id must be a positive integer.',
                    'meta' => [
                        'field' => 'id',
                        'reason' => 'invalid',
                    ],
                ],
            ], 400);
        }

        $this->activityId = (int) $id;
        $result = $history->show($this->activityId);

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'activity_not_found',
                    'message' => "Activity {$this->activityId} was not found or is not visible.",
                    'meta' => [
                        'id' => $this->activityId,
                    ],
                ],
            ], 404);
        }

        $this->activity = Activity::query()->find($this->activityId);
        $this->relatedCount = (int) $result['meta']['related_count'];
        $this->outcome = 'shown';

        return response()->json([
            'success' => [
                'data' => [
                    'activity' => $result['activity'],
                    'related' => $result['related'],
                ],
                'meta' => $result['meta'],
            ],
        ]);
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
        return 'activity.shown';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activity;
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
        return [
            'activity_id' => $this->activityId,
            'related_count' => $this->relatedCount,
            'outcome' => $this->outcome,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): string
    {
        return "shown activity #{$this->activityId}";
    }

    public function activityLogDescription(): string
    {
        return $this->description();
    }
}
