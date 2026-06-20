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
 * @property string $public_key
 * @property string $private_key
 * @property string|null $pre_shared_key
 * @property string|null $allowed_ips
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 */
final class WireGuardPeer extends Model
{
    use HasFactory;

    #[\Override]
    protected $table = 'wireguard_peers';

    #[\Override]
    protected $fillable = [
        'node_id',
        'public_key',
        'private_key',
        'pre_shared_key',
        'allowed_ips',
    ];

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
