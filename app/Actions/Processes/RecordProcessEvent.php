<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use Illuminate\Support\Str;

final readonly class RecordProcessEvent
{
    public function handle(
        ProcessEventType $type,
        ?App $app,
        ?Workspace $workspace,
        Process $process,
        Node $node,
        string $unitName,
        ?int $exitCode = null,
        ?string $exitStatus = null,
        mixed $exitedAt = null,
    ): ProcessEvent {
        return ProcessEvent::query()->create([
            'event' => $type,
            'event_id' => (string) Str::uuid(),
            'process_id' => $process->id,
            'app_id' => $app?->id,
            'workspace_id' => $workspace?->id,
            'node_id' => $node->id,
            'unit_name' => $unitName,
            'exit_code' => $exitCode,
            'exit_status' => $exitStatus,
            'exited_at' => $exitedAt,
            'recorded_at' => now(),
        ]);
    }
}
