<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $deployment_run_id
 * @property int|null $deploy_step_id
 * @property string $title
 * @property string $command
 * @property string $status
 * @property string|null $stdout
 * @property string|null $stderr
 * @property int|null $exit_code
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 */
class DeploymentRunStep extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'deployment_run_id',
        'deploy_step_id',
        'title',
        'command',
        'status',
        'stdout',
        'stderr',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DeploymentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DeploymentRun::class, 'deployment_run_id');
    }

    /**
     * @return BelongsTo<DeployStep, $this>
     */
    public function deployStep(): BelongsTo
    {
        return $this->belongsTo(DeployStep::class);
    }
}
