<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Contracts\RemoteShell;
use App\Enums\DriftKind;
use App\Services\Security\UnattendedUpgradesInstaller;
use JsonException;
use Orbit\Core\Updates\UnattendedUpgradesAptConfig;
use Throwable;

final readonly class UnattendedUpgradesDriver implements UpdateDriver
{
    private UnattendedUpgradesAptConfig $config;

    private UnattendedUpgradesInstaller $installer;

    public function __construct(
        private RemoteShell $remoteShell,
        ?UnattendedUpgradesAptConfig $config = null,
        ?UnattendedUpgradesInstaller $installer = null,
    ) {
        $this->config = $config ?? new UnattendedUpgradesAptConfig;
        $this->installer = $installer ?? new UnattendedUpgradesInstaller;
    }

    public function key(): string
    {
        return 'unattended-upgrades';
    }

    public function supportedTargets(): array
    {
        return [
            new UpdateDriverTarget('node', 'ubuntu_24-04', 'managed-server-node'),
            new UpdateDriverTarget('node', 'ubuntu_26-04', 'managed-server-node'),
        ];
    }

    public function supports(UpdateTarget $target): bool
    {
        return $target->family === 'node'
            && in_array($target->platform, ['ubuntu_24-04', 'ubuntu_26-04'], true)
            && $target->scope === 'managed-server-node';
    }

    public function probe(UpdateTarget $target): UpdatePostureSnapshot
    {
        try {
            $result = $this->remoteShell->run($target->node, $this->probeScript(), [
                'timeout' => 120,
                'throw' => false,
            ]);
        } catch (Throwable $throwable) {
            return new UpdatePostureSnapshot($this->key(), [
                $this->unverifiableIssue([
                    'exception' => $throwable->getMessage(),
                ]),
            ]);
        }

        if (! $result->successful()) {
            return new UpdatePostureSnapshot($this->key(), [
                $this->unverifiableIssue([
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ]),
            ]);
        }

        try {
            $facts = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new UpdatePostureSnapshot($this->key(), [
                $this->unverifiableIssue([
                    'stdout' => trim($result->stdout),
                    'stderr' => trim($result->stderr),
                ]),
            ]);
        }

        if (! is_array($facts)) {
            return new UpdatePostureSnapshot($this->key(), [
                $this->unverifiableIssue(),
            ]);
        }

        return new UpdatePostureSnapshot($this->key(), $this->issuesFromFacts($facts));
    }

    public function apply(UpdateTarget $target): UpdateApplyResult
    {
        $installReport = $this->installer->installFor($target->node, $this->remoteShell);

        if (! $installReport->successful) {
            return new UpdateApplyResult(
                driver: $this->key(),
                status: 'failed',
                summary: $installReport->summary,
                detail: $installReport->details,
            );
        }

        $result = $this->remoteShell->run($target->node, 'sudo unattended-upgrade', [
            'timeout' => 900,
            'throw' => false,
        ]);

        if (! $result->successful()) {
            return new UpdateApplyResult(
                driver: $this->key(),
                status: 'failed',
                summary: 'Failed to run unattended-upgrades.',
                detail: [
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
            );
        }

        return new UpdateApplyResult(
            driver: $this->key(),
            status: 'completed',
            summary: 'Applied unattended security upgrades.',
            detail: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<UpdatePostureIssue>
     */
    private function issuesFromFacts(array $facts): array
    {
        $issues = [];
        $installed = ($facts['installed'] ?? false) === true;
        $autoExists = array_key_exists('auto_exists', $facts) ? $facts['auto_exists'] === true : true;
        $unattendedExists = array_key_exists('unattended_exists', $facts) ? $facts['unattended_exists'] === true : true;
        $autoHashOk = ($facts['auto_hash_ok'] ?? false) === true;
        $unattendedHashOk = ($facts['unattended_hash_ok'] ?? false) === true;

        if (! $installed || ! $autoExists || ! $unattendedExists) {
            $issues[] = $this->issue(
                code: 'node.updates_config_missing',
                kind: DriftKind::Missing,
                summary: 'This node is missing unattended-upgrades or Orbit apt auto-upgrade configuration.',
                restorable: true,
                detail: [
                    'installed' => $installed,
                    'auto_exists' => $autoExists,
                    'unattended_exists' => $unattendedExists,
                ],
            );

            return $issues;
        }

        if (! $autoHashOk || ! $unattendedHashOk) {
            $issues[] = $this->issue(
                code: 'node.updates_config_mismatch',
                kind: DriftKind::Divergent,
                summary: 'This node has unattended-upgrades configuration that differs from Orbit policy.',
                restorable: true,
                detail: [
                    'auto_hash_ok' => $autoHashOk,
                    'unattended_hash_ok' => $unattendedHashOk,
                ],
            );
        }

        $dryRunExit = is_numeric($facts['dry_run_exit'] ?? null) ? (int) $facts['dry_run_exit'] : null;

        if ($dryRunExit !== null && $dryRunExit !== 0) {
            $issues[] = $this->issue(
                code: 'node.updates_dry_run_failed',
                kind: DriftKind::Unverifiable,
                summary: 'This node cannot complete an unattended-upgrades dry run.',
                restorable: true,
                detail: [
                    'dry_run_exit' => $dryRunExit,
                ],
            );
        }

        $lastRunStatus = is_string($facts['last_run_status'] ?? null) ? $facts['last_run_status'] : 'unknown';

        if ($lastRunStatus === 'failed') {
            $issues[] = $this->issue(
                code: 'node.updates_last_run_failed',
                kind: DriftKind::Divergent,
                summary: 'This node has a recent failed unattended-upgrades run.',
                restorable: true,
                detail: [
                    'last_run_status' => $lastRunStatus,
                ],
            );
        }

        if (($facts['reboot_required'] ?? false) === true) {
            $issues[] = $this->issue(
                code: 'node.updates_reboot_required',
                kind: DriftKind::Divergent,
                summary: 'This node requires an explicit reboot to finish installed updates. Orbit will not reboot it automatically. Reboot this server as soon as possible.',
                restorable: false,
                detail: [
                    'reboot_required' => true,
                    'reboot_required_packages' => $this->stringList($facts['reboot_required_packages'] ?? []),
                ],
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function issue(
        string $code,
        DriftKind $kind,
        string $summary,
        bool $restorable,
        array $detail = [],
    ): UpdatePostureIssue {
        return new UpdatePostureIssue(
            code: $code,
            kind: $kind,
            summary: $summary,
            restorable: $restorable,
            detail: [
                'driver' => $this->key(),
                ...$detail,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function unverifiableIssue(array $detail = []): UpdatePostureIssue
    {
        return $this->issue(
            code: 'node.updates_unverifiable',
            kind: DriftKind::Unverifiable,
            summary: 'This node update posture could not be verified through unattended-upgrades.',
            restorable: true,
            detail: $detail,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function probeScript(): string
    {
        return strtr(<<<'SH_WRAP'
        set -euo pipefail
        php <<'PHP'
        <?php

        $autoPath = '/etc/apt/apt.conf.d/20auto-upgrades';
        $unattendedPath = '/etc/apt/apt.conf.d/50unattended-upgrades';
        $logPath = '/var/log/unattended-upgrades/unattended-upgrades.log';

        $installed = trim(shell_exec('command -v unattended-upgrade 2>/dev/null') ?? '') !== '';
        $autoExists = is_file($autoPath);
        $unattendedExists = is_file($unattendedPath);
        $autoHashOk = $autoExists && hash_file('sha256', $autoPath) === '__AUTO_SHA256__';
        $unattendedHashOk = $unattendedExists && hash_file('sha256', $unattendedPath) === '__UNATTENDED_SHA256__';
        $configReady = $autoExists && $unattendedExists && $autoHashOk && $unattendedHashOk;
        $dryRunExit = null;

        if ($installed && $configReady) {
            exec('sudo unattended-upgrade --dry-run >/tmp/orbit-unattended-upgrade-dry-run.log 2>&1', result_code: $dryRunExit);
        }

        $lastRunStatus = 'unknown';

        if (is_file($logPath)) {
            $logTail = shell_exec('tail -n 80 ' . escapeshellarg($logPath) . ' 2>/dev/null') ?? '';

            if (preg_match('/error|failed|traceback|exception/i', $logTail) === 1) {
                $lastRunStatus = 'failed';
            } elseif (trim($logTail) !== '') {
                $lastRunStatus = 'completed';
            }
        }

        $packages = [];
        $packagePath = '/var/run/reboot-required.pkgs';

        if (is_file($packagePath)) {
            $packages = array_values(array_filter(array_map('trim', file($packagePath) ?: [])));
        }

        echo json_encode([
            'installed' => $installed,
            'auto_exists' => $autoExists,
            'unattended_exists' => $unattendedExists,
            'auto_hash_ok' => $autoHashOk,
            'unattended_hash_ok' => $unattendedHashOk,
            'dry_run_exit' => $dryRunExit,
            'last_run_status' => $lastRunStatus,
            'reboot_required' => is_file('/var/run/reboot-required'),
            'reboot_required_packages' => $packages,
        ], JSON_THROW_ON_ERROR);
        PHP
        SH_WRAP, [
            '__AUTO_SHA256__' => $this->config->autoUpgradesSha256(),
            '__UNATTENDED_SHA256__' => $this->config->unattendedUpgradesSha256(),
        ]);
    }
}
