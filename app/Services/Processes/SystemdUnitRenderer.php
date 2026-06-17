<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

final readonly class SystemdUnitRenderer
{
    public function unitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return $this->assertUnitName($process->name);
        }

        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        if ($workspace instanceof Workspace) {
            $this->assertIdentitySlug($workspace->name);

            return "orbit_{$app->name}_{$workspace->name}_{$process->name}";
        }

        return "orbit_{$app->name}_main_{$process->name}";
    }

    public function serviceName(string $runtimeUnit): string
    {
        $serviceName = str_ends_with($runtimeUnit, '.service') ? $runtimeUnit : "{$runtimeUnit}.service";

        return $this->assertServiceName($serviceName);
    }

    public function unitPath(string $runtimeUnit): string
    {
        return '/etc/systemd/system/'.$this->serviceName($runtimeUnit);
    }

    public function render(Node $node, App $app, Process $process, ?Workspace $workspace = null): string
    {
        $runtimeUnit = $this->unitName($app, $process, $workspace);
        $user = $node->user ?: 'orbit';
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return rtrim(implode(PHP_EOL, [
            '[Unit]',
            "Description=Orbit process {$runtimeUnit}",
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=simple',
            "User={$user}",
            'WorkingDirectory='.$this->workingDirectory($node, $app, $process, $workspace, $home),
            ...$this->environmentLines($app, $workspace, $home),
            'ExecStart=/bin/bash -lc '.escapeshellarg($process->command),
            'Restart='.$process->restart_policy->toSystemd(),
            'RestartSec=2',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
        ])).PHP_EOL;
    }

    public function installScript(Node $node, App $app, Process $process, ?Workspace $workspace = null): string
    {
        $runtimeUnit = $this->unitName($app, $process, $workspace);
        $serviceName = $this->serviceName($runtimeUnit);

        return sprintf(
            <<<'SH'
sudo mkdir -p /etc/systemd/system
sudo tee %1$s >/dev/null <<'EOF'
%2$sEOF
sudo systemctl daemon-reload
sudo systemctl enable %3$s >/dev/null
SH,
            escapeshellarg($this->unitPath($runtimeUnit)),
            $this->render($node, $app, $process, $workspace),
            escapeshellarg($serviceName),
        );
    }

    private function workingDirectory(Node $node, App $app, Process $process, ?Workspace $workspace, string $home): string
    {
        $ownerClass = Relation::getMorphedModel($process->owner_type) ?? $process->owner_type;

        if ($ownerClass === Node::class) {
            return $home;
        }

        if ($workspace instanceof Workspace) {
            return $workspace->path;
        }

        return $app->path;
    }

    /**
     * @return list<string>
     */
    private function environmentLines(App $app, ?Workspace $workspace, string $home): array
    {
        $url = $workspace instanceof Workspace ? $workspace->url() : $app->url();
        $host = $this->host($app, $workspace);
        $tlsBase = "{$home}/.config/orbit/certs/{$host}";
        $environment = [
            'PATH' => "{$home}/.local/bin:{$home}/.bun/bin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin",
            'HOME' => $home,
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_VALET_HOST' => $host,
            'VITE_DEV_SERVER_KEY' => "{$tlsBase}.key",
            'VITE_DEV_SERVER_CERT' => "{$tlsBase}.crt",
        ];

        return collect($environment)
            ->map(fn (string $value, string $key): string => 'Environment="'.$key.'='.$this->escapeEnvironmentValue($value).'"')
            ->values()
            ->all();
    }

    private function host(App $app, ?Workspace $workspace): string
    {
        $url = $workspace instanceof Workspace ? $workspace->url() : $app->url();
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return preg_replace('#^https?://#', '', $url) ?: $app->name;
    }

    private function escapeEnvironmentValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\"'], $value);
    }

    private function assertIdentitySlug(string $value): string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe runtime unit identity segment: {$value}");
        }

        return $value;
    }

    private function assertUnitName(string $value): string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe systemd unit name: {$value}");
        }

        return $value;
    }

    private function assertServiceName(string $value): string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?\.service$/', $value)) {
            throw new InvalidArgumentException("Unsafe systemd service name: {$value}");
        }

        return $value;
    }
}
