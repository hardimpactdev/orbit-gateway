<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $schedule_key
 * @property string $name
 * @property string $scope
 * @property int|null $app_id
 * @property int|null $node_id
 * @property string $target_name
 * @property string $interval
 * @property string $timezone
 * @property string $execution_type
 * @property string $execution_value
 * @property bool $enabled
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App|null $app
 * @property-read Node|null $node
 * @property-read ScheduleRun|null $latestRun
 * @property-read Collection<int, ScheduleRun> $runs
 */
class Schedule extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'schedule_key',
        'name',
        'scope',
        'app_id',
        'node_id',
        'target_name',
        'interval',
        'timezone',
        'execution_type',
        'execution_value',
        'enabled',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return HasMany<ScheduleRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduleRun::class, 'schedule_key', 'schedule_key')
            ->latest('started_at');
    }

    /**
     * @return HasOne<ScheduleRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ScheduleRun::class, 'schedule_key', 'schedule_key')
            ->latestOfMany('started_at');
    }
}
