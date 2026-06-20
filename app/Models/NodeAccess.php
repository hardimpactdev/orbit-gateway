<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $consumer_node_id
 * @property int $serving_node_id
 * @property list<string> $permissions
 * @property list<string> $custom_permissions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $consumer
 * @property-read Node $serving
 */
class NodeAccess extends Model
{
    #[\Override]
    protected $table = 'node_access';

    #[\Override]
    protected $attributes = [
        'permissions' => '["*"]',
        'custom_permissions' => '[]',
    ];

    #[\Override]
    protected $fillable = [
        'consumer_node_id',
        'serving_node_id',
        'permissions',
        'custom_permissions',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'custom_permissions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'consumer_node_id');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function serving(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'serving_node_id');
    }
}
