<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Nodes\NodeRoleStatus;
use Database\Factories\NodeRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $role
 * @property NodeRoleStatus $status
 * @property array<string, mixed>|null $settings
 * @property string|null $last_error
 * @property Carbon|null $converged_at
 * @property-read Node|null $node
 * @property-read Collection<int, Process> $processes
 */
class NodeRoleAssignment extends Model
{
    /** @use HasFactory<NodeRoleAssignmentFactory> */
    use HasFactory;

    #[\Override]
    protected $table = 'node_role';

    #[\Override]
    protected $fillable = [
        'node_id',
        'role',
        'status',
        'settings',
        'last_error',
        'converged_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'converged_at' => 'datetime',
            'status' => NodeRoleStatus::class,
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
     * @return MorphMany<Process, $this>
     */
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'owner')->orderBy('sort_order');
    }
}
