<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceLifecycleStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property string $path
 * @property string|null $php_version
 * @property string|null $agent_ide
 * @property string|null $agent_ide_workspace_id
 * @property WorkspaceLifecycleStatus $lifecycle_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App|null $app
 * @property-read Collection<int, DatabaseConnection> $databaseConnections
 * @property-read Collection<int, DatabaseConnectionTarget> $databaseConnectionTargets
 * @property-read Collection<int, Process> $processes
 * @property-read Collection<int, WorkspaceRun> $runs
 */
class Workspace extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'app_id',
        'name',
        'path',
        'php_version',
        'agent_ide',
        'agent_ide_workspace_id',
        'lifecycle_status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'lifecycle_status' => WorkspaceLifecycleStatus::class,
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
     * @return HasMany<WorkspaceRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkspaceRun::class)->orderByDesc('created_at');
    }

    /**
     * @return HasMany<ProxyRoute, $this>
     */
    public function proxyRoutes(): HasMany
    {
        return $this->hasMany(ProxyRoute::class);
    }

    /**
     * @return MorphMany<Process, $this>
     */
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'owner')->orderBy('sort_order');
    }

    /**
     * @return HasMany<DatabaseConnectionTarget, $this>
     */
    public function databaseConnectionTargets(): HasMany
    {
        return $this->hasMany(DatabaseConnectionTarget::class);
    }

    /**
     * @return BelongsToMany<DatabaseConnection, $this>
     */
    public function databaseConnections(): BelongsToMany
    {
        return $this->belongsToMany(
            related: DatabaseConnection::class,
            table: 'database_connection_targets',
            foreignPivotKey: 'workspace_id',
            relatedPivotKey: 'database_connection_id',
        )->withPivot('env_prefix')->withTimestamps();
    }

    public function effectivePhpVersion(): ?string
    {
        if (is_string($this->php_version) && $this->php_version !== '') {
            return $this->php_version;
        }

        $this->loadMissing('app');

        return $this->app?->php_version;
    }

    public function url(): string
    {
        $this->loadMissing('app.node');

        $app = $this->app;

        if (! $app instanceof App) {
            return "https://{$this->name}";
        }

        return 'https://'.$this->name.'.'.parse_url($app->url(), PHP_URL_HOST);
    }
}
