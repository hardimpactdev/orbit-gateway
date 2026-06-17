<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $schedule_key
 * @property string $status
 * @property int|null $exit_code
 * @property string|null $stdout
 * @property string|null $stderr
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node|null $node
 */
class ScheduleRun extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'node_id',
        'schedule_key',
        'status',
        'exit_code',
        'stdout',
        'stderr',
        'started_at',
        'finished_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
