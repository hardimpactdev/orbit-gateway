<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\ActivityLogTargets;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

// @phpstan-ignore trait.unused
trait LogsCommandActivity
{
    private bool $activityLogOwnsCorrelation = false;

    private bool $activityLogWritten = false;

    abstract public function getName(): ?string;

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return (string) $this->getName();
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }

    protected function bootActivityLog(): void
    {
        $correlation = app(ActivityLogCorrelation::class);

        if ($correlation->current() === null) {
            $correlation->start();
            $this->activityLogOwnsCorrelation = true;
        }
    }

    /**
     * Write the activity log entry now. Long-running commands (e.g. a tail loop
     * that never returns to finally) should call this before entering their
     * loop so the invocation is recorded even if SIGINT kills the process.
     * Idempotent.
     */
    protected function writeActivityLog(): void
    {
        if ($this->activityLogWritten) {
            return;
        }
        $this->activityLogWritten = true;

        $causer = $this->resolveLocalNode();
        $target = app(ActivityLogTargets::class)->primary();
        $hostName = gethostname() ?: 'unknown';

        $extra = [
            'client' => 'cli',
            'actor_name' => $causer instanceof Node ? $causer->name : $hostName,
            'actor_wg_ip' => $causer?->wireguard_address,
            'served_by_name' => $causer instanceof Node ? $causer->name : $hostName,
            'served_by_wg_ip' => $causer?->wireguard_address,
        ];

        if ($target !== null) {
            $extra['target_node'] = $target->name;
            $extra['target_wg_ip'] = is_string($target->wireguard_address) ? $target->wireguard_address : null;
        }

        app(ActivityLogger::class)->log(
            $this,
            channel: 'cli',
            causer: $causer,
            extraProperties: $extra,
        );
    }

    protected function finalizeActivityLog(): void
    {
        $this->writeActivityLog();

        if ($this->activityLogOwnsCorrelation) {
            app(ActivityLogCorrelation::class)->end();
            $this->activityLogOwnsCorrelation = false;
        }

        app(ActivityLogTargets::class)->reset();
    }

    private function resolveLocalNode(): ?Node
    {
        if (! Schema::hasTable('nodes') || ! Schema::hasTable('node_role')) {
            return null;
        }

        $node = app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->orderBy('name')
            ->first();

        return $node instanceof Node ? $node : null;
    }
}
