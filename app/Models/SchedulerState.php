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
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $registry_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node|null $node
 */
class SchedulerState extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'node_id',
        'heartbeat_at',
        'registry_synced_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'heartbeat_at' => 'datetime',
            'registry_synced_at' => 'datetime',
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
