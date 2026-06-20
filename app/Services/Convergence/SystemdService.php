<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use App\Contracts\RemoteShell;
use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\SystemdServicePlan;
use App\Data\Convergence\SystemdServiceProbe;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use InvalidArgumentException;
use JsonException;

final readonly class SystemdService
{
    public function __construct(
        public string $unitName,
        public string $content,
        public bool $enabled = true,
    ) {
        $this->serviceName();
    }

    public function probe(Node $node, RemoteShell $remoteShell): SystemdServiceProbe
    {
        $result = $remoteShell->run($node, $this->probeScript(), ['throw' => false]);

        if (! $result->successful()) {
            return new SystemdServiceProbe(
                reachable: false,
                exists: false,
                enabled: false,
                error: trim($result->stderr) !== '' ? trim($result->stderr) : "Probe exited with code {$result->exitCode}.",
            );
        }

        try {
            $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new SystemdServiceProbe(
                reachable: false,
                exists: false,
                enabled: false,
                error: "Probe returned invalid JSON: {$exception->getMessage()}",
            );
        }

        if (! is_array($payload)) {
            return new SystemdServiceProbe(
                reachable: false,
                exists: false,
                enabled: false,
                error: 'Probe returned an invalid payload.',
            );
        }

        return new SystemdServiceProbe(
            reachable: true,
            exists: ($payload['exists'] ?? null) === true,
            enabled: ($payload['enabled'] ?? null) === true,
            hash: is_string($payload['hash'] ?? null) ? $payload['hash'] : null,
        );
    }

    public function plan(SystemdServiceProbe $probe): SystemdServicePlan
    {
        if (! $probe->reachable) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Unreachable,
                summary: "Could not inspect systemd service {$this->serviceName()}.",
                details: $this->details(['error' => $probe->error]),
            );
        }

        if (! $probe->exists) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Install systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => null,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        if (! hash_equals($this->hash(), $probe->hash ?? '')) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Update systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => $probe->hash,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        if ($this->enabled && ! $probe->enabled) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Enable systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => $probe->hash,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        if (! $this->enabled && $probe->enabled) {
            return new SystemdServicePlan(
                status: ConvergenceStatus::Changed,
                summary: "Disable systemd service {$this->serviceName()}.",
                details: $this->details([
                    'observed_hash' => $probe->hash,
                    'observed_enabled' => $probe->enabled,
                ]),
            );
        }

        return new SystemdServicePlan(
            status: ConvergenceStatus::Ok,
            summary: "Systemd service {$this->serviceName()} already matches gateway intent.",
            details: $this->details([
                'observed_hash' => $probe->hash,
                'observed_enabled' => $probe->enabled,
            ]),
        );
    }

    public function apply(Node $node, RemoteShell $remoteShell, SystemdServicePlan $plan): ConvergenceApplyResult
    {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        $result = $remoteShell->run($node, $this->writeScript(), ['throw' => false]);

        if (! $result->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply systemd service {$this->serviceName()}.",
                details: $this->details([
                    'exit_code' => $result->exitCode,
                    'error' => trim($result->stderr) !== '' ? trim($result->stderr) : null,
                ]),
            );
        }

        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Changed,
            summary: "Applied systemd service {$this->serviceName()}.",
            details: $this->details(),
        );
    }

    public function writeScript(): string
    {
        return sprintf(
            <<<'SH'
set -euo pipefail
sudo install -d -m 0755 /etc/systemd/system
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0644 %s
sudo systemctl daemon-reload
%s
SH,
            escapeshellarg(base64_encode($this->content)),
            escapeshellarg($this->unitPath()),
            escapeshellarg($this->unitPath()),
            $this->enabledScript(),
        );
    }

    public function serviceName(): string
    {
        $serviceName = str_ends_with($this->unitName, '.service') ? $this->unitName : "{$this->unitName}.service";

        if (preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?\.service$/', $serviceName) === 1) {
            return $serviceName;
        }

        throw new InvalidArgumentException("Unsafe systemd service name: {$serviceName}");
    }

    public function unitPath(): string
    {
        return '/etc/systemd/system/'.$this->serviceName();
    }

    public function hash(): string
    {
        return hash('sha256', $this->content);
    }

    private function probeScript(): string
    {
        return sprintf(
            <<<'SH'
service=%s
path=%s

enabled_status="$(sudo systemctl is-enabled "$service" 2>/dev/null || true)"
enabled=false

if [ "$enabled_status" = "enabled" ]; then
    enabled=true
fi

if ! sudo test -f "$path"; then
    printf '{"exists":false,"hash":null,"enabled":%%s}\n' "$enabled"
    exit 0
fi

hash=""

if command -v sha256sum >/dev/null 2>&1; then
    hash="$(sudo sha256sum "$path" | awk '{print $1}')"
elif command -v shasum >/dev/null 2>&1; then
    hash="$(sudo shasum -a 256 "$path" | awk '{print $1}')"
fi

printf '{"exists":true,"hash":"%%s","enabled":%%s}\n' "$hash" "$enabled"
SH,
            escapeshellarg($this->serviceName()),
            escapeshellarg($this->unitPath()),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function details(array $extra = []): array
    {
        return [
            'service' => $this->serviceName(),
            'path' => $this->unitPath(),
            'enabled' => $this->enabled,
            'expected_hash' => $this->hash(),
            ...$extra,
        ];
    }

    private function enabledScript(): string
    {
        if ($this->enabled) {
            return 'sudo systemctl enable '.escapeshellarg($this->serviceName()).' >/dev/null';
        }

        return 'sudo systemctl disable '.escapeshellarg($this->serviceName()).' >/dev/null 2>&1 || true';
    }
}
