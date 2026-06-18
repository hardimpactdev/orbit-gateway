<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Orbit\Core\Enums\OperationStatus;

/**
 * @property string $id
 * @property string $operation_id
 * @property string|null $internal_command
 * @property string|null $operation_type
 * @property string $lane
 * @property OperationStatus $status
 * @property int|null $caller_node_id
 * @property int|null $target_node_id
 * @property string|null $correlation_id
 * @property string|null $queue
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $exit_code
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $error
 * @property string|null $stdout_summary
 * @property string|null $stderr_summary
 * @property-read Node|null $callerNode
 * @property-read Node|null $targetNode
 * @property-read Collection<int, OperationEvent> $events
 * @property-read Collection<int, UpdateLease> $updateLeases
 * @property-read OperationUpdatePlan|null $updatePlan
 */
class OperationRun extends Model
{
    use HasUuids;

    #[\Override]
    protected $fillable = [
        'id',
        'operation_id',
        'internal_command',
        'operation_type',
        'lane',
        'status',
        'caller_node_id',
        'target_node_id',
        'correlation_id',
        'queue',
        'started_at',
        'finished_at',
        'exit_code',
        'result',
        'error',
        'stdout_summary',
        'stderr_summary',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => OperationStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'exit_code' => 'integer',
            'result' => 'array',
            'error' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function callerNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'caller_node_id');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'target_node_id');
    }

    /**
     * @return HasMany<OperationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OperationEvent::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<UpdateLease, $this>
     */
    public function updateLeases(): HasMany
    {
        return $this->hasMany(UpdateLease::class);
    }

    /**
     * @return HasOne<OperationUpdatePlan, $this>
     */
    public function updatePlan(): HasOne
    {
        return $this->hasOne(OperationUpdatePlan::class);
    }
}
