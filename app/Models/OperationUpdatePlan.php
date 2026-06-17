<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Operations\OperationUpdatePlanSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property string $operation_run_id
 * @property string $target_version
 * @property string $gateway_image
 * @property string $manifest_source
 * @property string $manifest_version
 * @property array<string, mixed> $manifest_snapshot
 * @property array<string, mixed> $cli_artifacts
 * @property array<string, mixed> $role_images
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OperationRun $operationRun
 */
class OperationUpdatePlan extends Model
{
    #[\Override]
    protected $fillable = [
        'operation_run_id',
        'target_version',
        'gateway_image',
        'manifest_source',
        'manifest_version',
        'manifest_snapshot',
        'cli_artifacts',
        'role_images',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'manifest_snapshot' => 'array',
            'cli_artifacts' => 'array',
            'role_images' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Operation update plans are immutable once created.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Operation update plans are immutable once created.');
        });
    }

    /**
     * @return BelongsTo<OperationRun, $this>
     */
    public function operationRun(): BelongsTo
    {
        return $this->belongsTo(OperationRun::class);
    }

    public function toSnapshot(): OperationUpdatePlanSnapshot
    {
        return new OperationUpdatePlanSnapshot(
            targetVersion: $this->target_version,
            gatewayImage: $this->gateway_image,
            manifestSource: $this->manifest_source,
            manifestVersion: $this->manifest_version,
            manifestSnapshot: $this->manifest_snapshot,
            cliArtifacts: $this->cli_artifacts,
            roleImages: $this->role_images,
        );
    }
}
