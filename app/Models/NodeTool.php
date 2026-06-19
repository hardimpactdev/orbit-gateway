<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NodeToolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property string $expected_state
 * @property string|null $expected_version
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $credentials
 * @property-read Node|null $node
 */
class NodeTool extends Model
{
    /** @use HasFactory<NodeToolFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'node_id',
        'name',
        'expected_state',
        'expected_version',
        'config',
        'credentials',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'credentials' => 'encrypted:array',
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
