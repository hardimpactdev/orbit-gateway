<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $node_id
 * @property string $slug
 * @property string $driver
 * @property string|null $host
 * @property int|null $port
 * @property string|null $database
 * @property string|null $path
 * @property string|null $username
 * @property array<string, mixed>|null $credentials
 * @property-read Node|null $node
 * @property-read Collection<int, DatabaseConnectionTarget> $targets
 */
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'node_id',
        'slug',
        'driver',
        'host',
        'port',
        'database',
        'path',
        'username',
        'credentials',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
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

    /**
     * @return HasMany<DatabaseConnectionTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(DatabaseConnectionTarget::class);
    }
}
