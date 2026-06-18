<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Apps\AppInstanceDriverConfigData;
use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Enums\Apps\AppInstanceDriver;
use Database\Factories\AppInstanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property AppInstanceDriver $driver
 * @property AppInstanceDriverConfigData|null $driver_config
 * @property AppInstanceRuntimeRequirementsData|null $runtime_requirements
 * @property string|null $latest_deployment_status
 * @property int|null $latest_deployment_run_id
 * @property-read App $app
 */
class AppInstance extends Model
{
    /** @use HasFactory<AppInstanceFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'app_id',
        'name',
        'driver',
        'driver_config',
        'runtime_requirements',
        'latest_deployment_status',
        'latest_deployment_run_id',
    ];

    #[\Override]
    protected $attributes = [
        'driver' => 'orbit',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'driver' => AppInstanceDriver::class,
            'driver_config' => AppInstanceDriverConfigData::class,
            'runtime_requirements' => AppInstanceRuntimeRequirementsData::class,
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
     * @return HasMany<AppInstanceEnvVariable, $this>
     */
    public function envVariables(): HasMany
    {
        return $this->hasMany(AppInstanceEnvVariable::class)->orderBy('key');
    }

    /**
     * @return HasMany<AppInstanceDatabaseConnectionTarget, $this>
     */
    public function databaseConnectionTargets(): HasMany
    {
        return $this->hasMany(AppInstanceDatabaseConnectionTarget::class);
    }

    public function runtimeRequirements(): AppInstanceRuntimeRequirementsData
    {
        return $this->runtime_requirements instanceof AppInstanceRuntimeRequirementsData
            ? $this->runtime_requirements
            : new AppInstanceRuntimeRequirementsData;
    }
}
