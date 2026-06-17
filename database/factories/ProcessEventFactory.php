<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProcessEvent>
 */
class ProcessEventFactory extends Factory
{
    protected $model = ProcessEvent::class;

    public function definition(): array
    {
        return [
            'event' => ProcessEventType::Started,
            'event_id' => (string) Str::uuid(),
            'process_id' => Process::factory(),
            'app_id' => App::factory(),
            'workspace_id' => null,
            'node_id' => Node::factory(),
            'unit_name' => 'orbit_docs_main_vite',
            'exit_code' => null,
            'exit_status' => null,
            'exited_at' => null,
            'recorded_at' => now(),
        ];
    }
}
