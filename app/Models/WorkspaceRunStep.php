<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_run_id
 * @property int|null $workspace_step_id
 * @property string $command
 * @property int|null $exit_code
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceRun|null $run
 * @property-read WorkspaceStep|null $step
 */
class WorkspaceRunStep extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'workspace_run_id',
        'workspace_step_id',
        'command',
        'exit_code',
        'output',
        'started_at',
        'completed_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkspaceRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkspaceRun::class, 'workspace_run_id');
    }

    /**
     * @return BelongsTo<WorkspaceStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkspaceStep::class, 'workspace_step_id');
    }
}
