<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('orbit:internal:pin-node-host-keys
    {--json : Output JSON}')]
#[Description('Refresh hosted node SSH host-key pins for internal topology checkout setup')]
class PinNodeHostKeysCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $pinned = [];
        $failed = [];
        $pinner = app(SshHostKeyPinner::class);

        foreach ($this->hostedNodes() as $node) {
            $host = trim($node->host);

            try {
                $hostKey = $pinner->pin($host);
                $pinner->persist($node, $hostKey);

                $pinned[] = [
                    'node' => $node->name,
                    'host' => $host,
                    'fingerprint' => $hostKey->fingerprint,
                ];
            } catch (Throwable $exception) {
                $failed[] = [
                    'node' => $node->name,
                    'host' => $host,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($failed !== []) {
            return $this->failCommand($pinned, $failed);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'pinned' => $pinned,
                        'failed' => [],
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Pinned '.count($pinned).' hosted node host key(s).');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Node>
     */
    private function hostedNodes(): Collection
    {
        return Node::query()
            ->whereNotNull('host')
            ->where('host', '<>', '')
            ->whereHas('roleAssignments', function ($query): void {
                $query->whereIn('role', [
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Agent->value,
                ]);
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  list<array{node: string, host: string, fingerprint: string}>  $pinned
     * @param  list<array{node: string, host: string, message: string}>  $failed
     */
    private function failCommand(array $pinned, array $failed): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'node.host_key_pin_failed',
                    'message' => 'Failed to pin one or more hosted node SSH host keys.',
                    'data' => [
                        'pinned' => $pinned,
                        'failed' => $failed,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        foreach ($failed as $failure) {
            $this->error("{$failure['node']} ({$failure['host']}): {$failure['message']}");
        }

        return self::FAILURE;
    }
}
