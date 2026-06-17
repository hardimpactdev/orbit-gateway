<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FirewallRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property string $direction
 * @property string $action
 * @property string $source
 * @property string|null $destination
 * @property int|string $port
 * @property string $protocol
 * @property string|null $reason
 * @property string $source_hash
 * @property string $address_family
 * @property string|null $interface
 * @property string $owner
 * @property bool $protected
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 */
class FirewallRule extends Model
{
    /** @use HasFactory<FirewallRuleFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'node_id',
        'name',
        'direction',
        'action',
        'source',
        'destination',
        'port',
        'protocol',
        'reason',
        'source_hash',
        'address_family',
        'interface',
        'owner',
        'protected',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'protected' => 'bool',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (FirewallRule $rule): void {
            $rule->owner = $rule->owner ?: 'user';
            $rule->protected = $rule->owner !== 'user';
        });
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
