<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('orbit:internal:node-register
    {name : Registry name for the node}
    {--host= : SSH host or alias}
    {--user= : SSH user}
    {--orbit-path= : Path to the Orbit checkout on the node}
    {--status=active : Node status}')]
#[Description('Register or update a node in the gateway registry')]
class NodeRegisterCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $status = NodeStatus::tryFrom((string) $this->option('status'));

        if (! $status instanceof NodeStatus || ! in_array($status, [NodeStatus::Active, NodeStatus::Inactive], true)) {
            $this->error('Status must be one of: active, inactive.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        DB::transaction(function () use ($name, $status): void {
            Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'host' => (string) ($this->option('host') ?: $name),
                    'user' => (string) ($this->option('user') ?: get_current_user()),
                    'orbit_path' => (string) ($this->option('orbit-path') ?: repo_path()),
                    'status' => $status,
                ],
            );
        });

        $this->info("Registered node {$name}.");

        return self::SUCCESS;
    }
}
