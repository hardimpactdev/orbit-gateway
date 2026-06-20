<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Apps\PhpWorkerConfig;
use App\Enums\Apps\AppRuntimeKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * @property string $name
 * @property int $node_id
 * @property string $environment
 * @property string|null $domain
 * @property string $path
 * @property string $document_root
 * @property string|null $repository
 * @property string $php_version
 * @property AppRuntimeKind $runtime_kind
 * @property bool $worker_enabled
 * @property array<string, mixed>|null $worker_config
 * @property list<string>|null $deploy_warmup_paths
 * @property bool $adopted
 * @property array<string, mixed>|null $agent_ide_config
 * @property string|null $latest_deployment_status
 * @property int|null $latest_deployment_run_id
 * @property-read Node|null $node
 * @property-read Collection<int, AppInstance> $instances
 * @property-read Collection<int, DeployStep> $deploySteps
 * @property-read Collection<int, DeploymentRun> $deploymentRuns
 * @property-read Collection<int, DatabaseConnection> $databaseConnections
 * @property-read Collection<int, DatabaseConnectionTarget> $databaseConnectionTargets
 * @property-read Collection<int, Process> $processes
 * @property-read Collection<int, AppRuntimeMount> $runtimeMounts
 * @property-read Collection<int, Schedule> $schedules
 * @property-read AppAnalyticsBinding|null $analyticsBinding
 * @property-read AppWebSocketBinding|null $webSocketBinding
 * @property-read Collection<int, Workspace> $workspaces
 */
class App extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'name',
        'node_id',
        'environment',
        'domain',
        'path',
        'document_root',
        'repository',
        'php_version',
        'runtime_kind',
        'worker_enabled',
        'worker_config',
        'deploy_warmup_paths',
        'adopted',
        'agent_ide_config',
        'latest_deployment_status',
        'latest_deployment_run_id',
    ];

    #[\Override]
    protected $attributes = [
        'runtime_kind' => 'php',
        'worker_enabled' => false,
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'adopted' => 'boolean',
            'agent_ide_config' => 'array',
            'runtime_kind' => AppRuntimeKind::class,
            'worker_enabled' => 'boolean',
            'worker_config' => 'array',
            'deploy_warmup_paths' => 'array',
        ];
    }

    public function workerConfig(): PhpWorkerConfig
    {
        return PhpWorkerConfig::fromArray(is_array($this->worker_config) ? $this->worker_config : []);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return HasMany<AppInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(AppInstance::class)->orderBy('name');
    }

    /**
     * @return MorphMany<Process, $this>
     */
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'owner')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->orderBy('name');
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class)->orderBy('name');
    }

    /**
     * @return HasMany<DeployStep, $this>
     */
    public function deploySteps(): HasMany
    {
        return $this->hasMany(DeployStep::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<DeploymentRun, $this>
     */
    public function deploymentRuns(): HasMany
    {
        return $this->hasMany(DeploymentRun::class)->orderByDesc('started_at');
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
            foreignPivotKey: 'app_id',
            relatedPivotKey: 'database_connection_id',
        )->withPivot('env_prefix')->withTimestamps();
    }

    /**
     * @return HasOne<AppWebSocketBinding, $this>
     */
    public function webSocketBinding(): HasOne
    {
        return $this->hasOne(AppWebSocketBinding::class);
    }

    /**
     * @return HasOne<AppAnalyticsBinding, $this>
     */
    public function analyticsBinding(): HasOne
    {
        return $this->hasOne(AppAnalyticsBinding::class);
    }

    /**
     * @return HasMany<AppRuntimeMount, $this>
     */
    public function runtimeMounts(): HasMany
    {
        return $this->hasMany(AppRuntimeMount::class)->orderBy('target');
    }

    public function url(): string
    {
        if (is_string($this->domain) && $this->domain !== '') {
            return "https://{$this->domain}";
        }

        $this->loadMissing('node');

        $tld = is_string($this->node?->tld) ? trim($this->node->tld, '.') : '';

        if ($tld === '') {
            return "https://{$this->name}";
        }

        return "https://{$this->name}.{$tld}";
    }

    public function documentRootPath(): string
    {
        $root = trim((string) $this->document_root, '/');

        if ($root === '') {
            return rtrim((string) $this->path, '/');
        }

        return Str::finish(rtrim((string) $this->path, '/'), '/').$root;
    }
}
