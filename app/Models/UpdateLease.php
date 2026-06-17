<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $resource_type
 * @property string $resource_key
 * @property string|null $active_resource_key
 * @property string $operation_run_id
 * @property string $owner_token
 * @property Carbon $expires_at
 * @property Carbon|null $released_at
 * @property-read OperationRun $operationRun
 */
class UpdateLease extends Model
{
    #[\Override]
    protected $fillable = [
        'resource_type',
        'resource_key',
        'active_resource_key',
        'operation_run_id',
        'owner_token',
        'expires_at',
        'released_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OperationRun, $this>
     */
    public function operationRun(): BelongsTo
    {
        return $this->belongsTo(OperationRun::class);
    }
}
