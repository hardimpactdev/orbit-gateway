<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $operation_run_id
 * @property int $sequence
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OperationRun $operationRun
 */
class OperationEvent extends Model
{
    #[\Override]
    protected $fillable = [
        'operation_run_id',
        'sequence',
        'event_type',
        'payload',
        'metadata',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'metadata' => 'array',
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
