<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property WorkspaceLifecyclePhase $phase
 * @property string $status
 * @property string|null $step_set_hash
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace|null $workspace
 * @property-read Collection<int, WorkspaceRunStep> $runSteps
 */
class WorkspaceRun extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'workspace_id',
        'phase',
        'status',
        'step_set_hash',
        'started_at',
        'completed_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'phase' => WorkspaceLifecyclePhase::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<WorkspaceRunStep, $this>
     */
    public function runSteps(): HasMany
    {
        return $this->hasMany(WorkspaceRunStep::class)->orderBy('id');
    }
}
