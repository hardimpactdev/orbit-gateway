<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $name
 * @property string $command
 * @property ProcessRestartPolicy $restart_policy
 * @property ProcessCrashNotification $crash_notification
 * @property ProcessRuntime $runtime
 * @property string|null $tool
 * @property array<string, mixed> $runtime_config
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read App|null $app
 * @property-read Node|null $node
 * @property-read Collection<int, ProcessEvent> $events
 */
class Process extends Model
{
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (Process $process): void {
            if ($process->node_id !== null) {
                return;
            }

            $nodeId = $process->nodeIdForOwner();

            if ($nodeId !== null) {
                $process->node_id = $nodeId;
            }
        });
    }

    #[\Override]
    protected $fillable = [
        'node_id',
        'owner_type',
        'owner_id',
        'name',
        'command',
        'restart_policy',
        'crash_notification',
        'runtime',
        'tool',
        'runtime_config',
        'sort_order',
    ];

    #[\Override]
    protected $attributes = [
        'runtime' => 'systemd',
        'runtime_config' => '[]',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'restart_policy' => ProcessRestartPolicy::class,
            'crash_notification' => ProcessCrashNotification::class,
            'runtime' => ProcessRuntime::class,
            'runtime_config' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return Builder<$this>
     */
    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    public function ownerApp(): ?App
    {
        $this->loadMissing('owner');

        if ($this->owner instanceof App) {
            return $this->owner;
        }

        if ($this->owner instanceof Workspace) {
            $this->owner->loadMissing('app');

            return $this->owner->app;
        }

        return null;
    }

    public function getAppAttribute(): ?App
    {
        return $this->ownerApp();
    }

    private function nodeIdForOwner(): ?int
    {
        if ($this->owner_type === '' || $this->owner_id === null) {
            return null;
        }

        $ownerClass = Relation::getMorphedModel($this->owner_type) ?? $this->owner_type;

        if ($ownerClass === Node::class) {
            return (int) $this->owner_id;
        }

        if ($ownerClass === App::class) {
            $app = App::query()->find($this->owner_id);

            return $app instanceof App ? $app->node_id : null;
        }

        if ($ownerClass === Workspace::class) {
            $workspace = Workspace::query()->with('app')->find($this->owner_id);

            return $workspace?->app?->node_id;
        }

        if ($ownerClass === NodeRoleAssignment::class) {
            $role = NodeRoleAssignment::query()->find($this->owner_id);

            return $role instanceof NodeRoleAssignment ? $role->node_id : null;
        }

        return null;
    }

    /**
     * @return HasMany<ProcessEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProcessEvent::class)->latest('recorded_at');
    }
}
