<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Node;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\RemoteShell\SshCommandBuilder;
use App\Services\Security\HomeDirectoryLockdownInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SysctlBaselineInstaller;
use App\Services\Security\UnattendedUpgradesInstaller;
use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class OrbitHostInstaller
{
    private const string PreferredTempDirectory = '/var/tmp';

    private ?Node $pinnedNode = null;

    public function usePinnedNode(?Node $node): void
    {
        $this->pinnedNode = $node;
    }

    public function install(string $host, string $sshUser, string $runtimeUser = 'orbit'): OrbitHostInstallResult
    {
        $remotePrefix = self::PreferredTempDirectory.'/orbit-install-'.Str::lower(Str::random(8));
        $localArchive = $this->buildSourceArchive();
        $localBinary = $this->forwardedBinaryPath();
        $remoteBinary = $localBinary !== null ? "{$remotePrefix}-orbit-binary" : null;
        $localEnvironment = $this->buildExecutorEnvironmentFile($this->pinnedNode, $remoteBinary);
        $localImageArchives = $this->buildForwardedImageArchives();
        $remoteArchive = "{$remotePrefix}.tar.gz";
        $remoteInstaller = "{$remotePrefix}.sh";
        $remoteEnvironment = "{$remotePrefix}.env";
        $remoteImageArchives = $this->remoteImageArchives($remotePrefix, $localImageArchives);

        try {
            $userCreated = $this->createRuntimeUser($host, $sshUser, $runtimeUser);

            if (! $userCreated->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $userCreated->output(),
                    errorOutput: $userCreated->errorOutput(),
                );
            }

            $executionUser = $this->pinnedNode instanceof Node ? $runtimeUser : $sshUser;

            $scriptUpload = $this->scp(repo_path('bin/install-orbit'), $executionUser, $host, $remoteInstaller);

            if (! $scriptUpload->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $scriptUpload->output(),
                    errorOutput: $scriptUpload->errorOutput(),
                );
            }

            $archiveUpload = $this->scp($localArchive, $executionUser, $host, $remoteArchive);

            if (! $archiveUpload->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $archiveUpload->output(),
                    errorOutput: $archiveUpload->errorOutput(),
                );
            }

            $environmentUpload = $this->scp($localEnvironment, $executionUser, $host, $remoteEnvironment);

            if (! $environmentUpload->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $environmentUpload->output(),
                    errorOutput: $environmentUpload->errorOutput(),
                );
            }

            if ($localBinary !== null && $remoteBinary !== null) {
                $binaryUpload = $this->scp($localBinary, $executionUser, $host, $remoteBinary);

                if (! $binaryUpload->successful()) {
                    return new OrbitHostInstallResult(
                        successful: false,
                        output: $binaryUpload->output(),
                        errorOutput: $binaryUpload->errorOutput(),
                    );
                }
            }

            foreach ($localImageArchives as $key => $localImageArchive) {
                $imageUpload = $this->scp($localImageArchive, $executionUser, $host, $remoteImageArchives[$key]);

                if (! $imageUpload->successful()) {
                    return new OrbitHostInstallResult(
                        successful: false,
                        output: $imageUpload->output(),
                        errorOutput: $imageUpload->errorOutput(),
                    );
                }
            }

            if (! $this->pinnedNode instanceof Node && $sshUser !== $runtimeUser) {
                $chownPaths = array_merge(
                    [$remoteInstaller, $remoteArchive, $remoteEnvironment],
                    $remoteBinary !== null ? [$remoteBinary] : [],
                    array_values($remoteImageArchives),
                );
                $chown = Process::timeout(30)->run($this->ssh(
                    user: $sshUser,
                    host: $host,
                    command: sprintf(
                        'sudo chown %s:%s %s',
                        escapeshellarg($runtimeUser),
                        escapeshellarg($runtimeUser),
                        implode(' ', array_map(escapeshellarg(...), $chownPaths)),
                    ),
                ));

                if (! $chown->successful()) {
                    return new OrbitHostInstallResult(
                        successful: false,
                        output: $chown->output(),
                        errorOutput: $chown->errorOutput(),
                    );
                }
            }

            $remoteHome = $runtimeUser === 'root' ? '/root' : "/home/{$runtimeUser}";
            $installerFlags = $this->imageArchiveInstallerFlags($remoteImageArchives);
            $cleanupPaths = array_merge(
                [$remoteInstaller, $remoteArchive, $remoteEnvironment],
                $remoteBinary !== null ? [$remoteBinary] : [],
                array_values($remoteImageArchives),
            );
            $installCommand = sprintf(
                "set -e; trap 'rm -f %s' EXIT; set -a; . %s; set +a; bash %s --path=%s --source-archive=%s%s",
                implode(' ', array_map(escapeshellarg(...), $cleanupPaths)),
                escapeshellarg($remoteEnvironment),
                escapeshellarg($remoteInstaller),
                escapeshellarg("{$remoteHome}/orbit"),
                escapeshellarg($remoteArchive),
                $installerFlags,
            );

            $command = $executionUser === $runtimeUser
                ? $installCommand
                : sprintf('sudo su - %s -c %s', escapeshellarg($runtimeUser), escapeshellarg($installCommand));

            $installation = Process::timeout(900)->run($this->ssh(
                user: $executionUser,
                host: $host,
                command: $command,
            ));

            if (! $installation->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $installation->output(),
                    errorOutput: $installation->errorOutput(),
                );
            }

            $securityBaseline = $this->installSecurityBaseline($host, $runtimeUser);

            if ($securityBaseline instanceof OrbitHostInstallResult && ! $securityBaseline->successful) {
                return $securityBaseline;
            }

            return new OrbitHostInstallResult(
                successful: true,
                output: $installation->output(),
                errorOutput: $installation->errorOutput(),
            );
        } finally {
            $this->pinnedNode = null;
            @unlink($localArchive);
            @unlink($localEnvironment);

            foreach ($localImageArchives as $localImageArchive) {
                @unlink($localImageArchive);
            }
        }
    }

    /**
     * @return array{gateway?: string, caddy?: string, dnsmasq?: string, frankenphp?: string, wg_easy?: string}
     */
    private function buildForwardedImageArchives(): array
    {
        if (! config('orbit.forward_install_image_archives')) {
            return [];
        }

        $phpRuntimeCatalog = new PhpRuntimeCatalog;
        $archives = [];

        foreach ([
            'gateway' => ['image' => 'orbit-gateway:current', 'name' => 'orbit-gateway-current'],
            'caddy' => ['image' => 'caddy:2-alpine', 'name' => 'caddy-2-alpine'],
            'dnsmasq' => ['image' => '4km3/dnsmasq:latest', 'name' => 'dnsmasq-latest'],
            'frankenphp' => ['image' => $phpRuntimeCatalog->imageFor(PhpRuntimeCatalog::DEFAULT), 'name' => 'frankenphp-1-php8.5-bookworm'],
            'wg_easy' => ['image' => WgEasyServiceInstaller::Image, 'name' => 'wg-easy-15'],
        ] as $key => $image) {
            $inspect = Process::timeout(30)->run(sprintf(
                'docker image inspect %s >/dev/null 2>&1',
                escapeshellarg($image['image']),
            ));

            if (! $inspect->successful()) {
                continue;
            }

            $archive = $this->localTempPath($image['name'].'-'.Str::lower(Str::random(8)).'.tar');
            $save = Process::timeout(600)->run(sprintf(
                'docker save %s -o %s',
                escapeshellarg($image['image']),
                escapeshellarg($archive),
            ));

            if (! $save->successful()) {
                @unlink($archive);

                throw new \RuntimeException('Failed to export Docker image '.$image['image'].': '.trim($save->errorOutput()));
            }

            $archives[$key] = $archive;
        }

        return $archives;
    }

    /**
     * @param  array{gateway?: string, caddy?: string, dnsmasq?: string, frankenphp?: string, wg_easy?: string}  $localImageArchives
     * @return array{gateway?: string, caddy?: string, dnsmasq?: string, frankenphp?: string, wg_easy?: string}
     */
    private function remoteImageArchives(string $remotePrefix, array $localImageArchives): array
    {
        $remoteImageArchives = [];

        foreach (array_keys($localImageArchives) as $key) {
            $remoteImageArchives[$key] = match ($key) {
                'gateway' => "{$remotePrefix}-orbit-gateway-current.tar",
                'caddy' => "{$remotePrefix}-caddy-2-alpine.tar",
                'dnsmasq' => "{$remotePrefix}-dnsmasq-latest.tar",
                'frankenphp' => "{$remotePrefix}-frankenphp-1-php8.5-bookworm.tar",
                'wg_easy' => "{$remotePrefix}-wg-easy-15.tar",
            };
        }

        return $remoteImageArchives;
    }

    /**
     * @param  array{gateway?: string, caddy?: string, dnsmasq?: string, frankenphp?: string, wg_easy?: string}  $remoteImageArchives
     */
    private function imageArchiveInstallerFlags(array $remoteImageArchives): string
    {
        $flags = '';

        if (isset($remoteImageArchives['gateway'])) {
            $flags .= ' --gateway-image=orbit-gateway:current --gateway-image-archive='.escapeshellarg($remoteImageArchives['gateway']);
        }

        if (isset($remoteImageArchives['caddy'])) {
            $flags .= ' --caddy-image-archive='.escapeshellarg($remoteImageArchives['caddy']);
        }

        if (isset($remoteImageArchives['dnsmasq'])) {
            $flags .= ' --dnsmasq-image-archive='.escapeshellarg($remoteImageArchives['dnsmasq']);
        }

        if (isset($remoteImageArchives['frankenphp'])) {
            $flags .= ' --frankenphp-image-archive='.escapeshellarg($remoteImageArchives['frankenphp']);
        }

        if (isset($remoteImageArchives['wg_easy'])) {
            $flags .= ' --wg-easy-image-archive='.escapeshellarg($remoteImageArchives['wg_easy']);
        }

        return $flags;
    }

    private function createRuntimeUser(string $host, string $sshUser, string $runtimeUser): ProcessResult
    {
        $script = sprintf(
            <<<'SCRIPT'
set -e
USER=%s
if ! id -u "$USER" >/dev/null 2>&1; then
    sudo useradd -m -s /bin/bash "$USER"
fi
sudo usermod -s /bin/bash "$USER" 2>/dev/null || true
sudo usermod -p '*' "$USER" 2>/dev/null || true
sudo usermod -aG sudo "$USER" 2>/dev/null || true
if [ ! -d "/home/$USER" ]; then
    sudo mkdir -p "/home/$USER"
    sudo chown "$USER:$USER" "/home/$USER"
fi
sudo install -d -m 700 -o "$USER" -g "$USER" "/home/$USER/.ssh"
TARGET_KEYS="/home/$USER/.ssh/authorized_keys"
BOOTSTRAP_KEYS="${HOME:-}/.ssh/authorized_keys"
if [ "$(id -u)" -eq 0 ]; then
    BOOTSTRAP_KEYS="/root/.ssh/authorized_keys"
fi
if [ -s "$BOOTSTRAP_KEYS" ]; then
    sudo touch "$TARGET_KEYS"
    sudo chown "$USER:$USER" "$TARGET_KEYS"
    sudo chmod 600 "$TARGET_KEYS"
    while IFS= read -r key; do
        if [ -n "$key" ] && ! sudo grep -qxF "$key" "$TARGET_KEYS"; then
            printf '%%s\n' "$key" | sudo tee -a "$TARGET_KEYS" > /dev/null
        fi
    done < "$BOOTSTRAP_KEYS"
fi
sudo chown -R "$USER:$USER" "/home/$USER/.ssh"
sudo chmod 700 "/home/$USER/.ssh"
if [ -f "$TARGET_KEYS" ]; then
    sudo chmod 600 "$TARGET_KEYS"
fi
printf '%%s ALL=(ALL:ALL) NOPASSWD:ALL\n' "$USER" | sudo tee /etc/sudoers.d/99-orbit > /dev/null
sudo chmod 440 /etc/sudoers.d/99-orbit
SCRIPT,
            escapeshellarg($runtimeUser),
        );

        return Process::timeout(60)->run($this->ssh(
            user: $sshUser,
            host: $host,
            command: $script,
        ));
    }

    private function buildSourceArchive(): string
    {
        $archive = $this->localTempPath('orbit-source-'.Str::lower(Str::random(8)).'.tar.gz');

        $result = Process::timeout(120)->run(sprintf(
            "tar --exclude='./.git' --exclude='./.env' --exclude='./.env.e2e' --exclude='./.env.local' --exclude='./.env.backup' --exclude='./.env.production' --exclude='./.env.testing' --exclude='./node_modules' --exclude='./vendor' --exclude='./apps/gateway/.env' --exclude='./apps/gateway/.env.e2e' --exclude='./apps/gateway/.env.local' --exclude='./apps/gateway/.env.backup' --exclude='./apps/gateway/.env.production' --exclude='./apps/gateway/node_modules' --exclude='./apps/gateway/public/build' --exclude='./apps/gateway/public/hot' --exclude='./apps/gateway/public/storage' --exclude='./apps/gateway/storage/logs/*' --exclude='./apps/gateway/storage/framework/cache/*' --exclude='./apps/gateway/storage/framework/e2e/*' --exclude='./apps/gateway/storage/framework/sessions/*' --exclude='./apps/gateway/storage/framework/testing/*' --exclude='./apps/gateway/storage/framework/views/*' --exclude='./apps/gateway/storage/pail' --exclude='./apps/gateway/database/*.sqlite*' -czf %s -C %s .",
            escapeshellarg($archive),
            escapeshellarg(repo_path()),
        ));

        if (! $result->successful()) {
            @unlink($archive);

            throw new \RuntimeException('Failed to build Orbit source archive: '.trim($result->errorOutput()));
        }

        return $archive;
    }

    private function buildExecutorEnvironmentFile(?Node $node, ?string $remoteBinary): string
    {
        $path = $this->localTempPath('orbit-install-env-'.Str::lower(Str::random(8)).'.env');

        $contents = '';

        if ($remoteBinary !== null) {
            $contents .= 'ORBIT_BINARY_URL='.escapeshellarg("file://{$remoteBinary}")."\n";
        }

        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }

    private function forwardedBinaryPath(): ?string
    {
        $path = config('orbit.forward_install_binary');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (! is_file($path)) {
            return null;
        }

        return $path;
    }

    private function localTempPath(string $fileName): string
    {
        $directory = self::PreferredTempDirectory;

        if (! is_dir($directory) || ! is_writable($directory)) {
            $directory = sys_get_temp_dir();
        }

        return rtrim($directory, '/').'/'.$fileName;
    }

    private function installSecurityBaseline(string $host, string $runtimeUser): ?OrbitHostInstallResult
    {
        if (! $this->pinnedNode instanceof Node) {
            return null;
        }

        foreach ($this->securityBaselineScripts($this->pinnedNode) as $name => $script) {
            $result = Process::timeout(900)->run($this->ssh(
                user: $runtimeUser,
                host: $host,
                command: $script,
            ));

            if ($result->successful()) {
                continue;
            }

            return new OrbitHostInstallResult(
                successful: false,
                output: $result->output(),
                errorOutput: trim("Security baseline [{$name}] failed.\n".$result->errorOutput()),
            );
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function securityBaselineScripts(Node $node): array
    {
        return [
            'home' => app(HomeDirectoryLockdownInstaller::class)->script(),
            'sysctl' => app(SysctlBaselineInstaller::class)->script(),
            'sshd' => app(SshdHardenedInstaller::class)->script($node),
            'unattended_upgrades' => app(UnattendedUpgradesInstaller::class)->script(),
        ];
    }

    private function scp(string $source, string $sshUser, string $host, string $destination): ProcessResult
    {
        if ($this->pinnedNode instanceof Node) {
            return Process::timeout(600)->run(app(SshCommandBuilder::class)->scpToNode(
                node: $this->pinnedNode,
                source: $source,
                destination: $destination,
                loginUser: $sshUser,
                options: [
                    'batch_mode' => true,
                    'strict_host_key_checking' => 'yes',
                    'prefer_public_host' => true,
                ],
            ));
        }

        return Process::timeout(600)->run(app(SshCommandBuilder::class)->scpTo(
            source: $source,
            user: $sshUser,
            host: $host,
            destination: $destination,
            options: ['batch_mode' => true],
        ));
    }

    private function ssh(string $user, string $host, string $command): string
    {
        if ($this->pinnedNode instanceof Node) {
            return app(SshCommandBuilder::class)->enforceForNode(
                node: $this->pinnedNode,
                remoteCommand: $command,
                loginUser: $user,
                options: [
                    'batch_mode' => true,
                    'prefer_public_host' => true,
                ],
            );
        }

        return app(SshCommandBuilder::class)->ssh(
            user: $user,
            host: $host,
            remoteCommand: $command,
            options: ['batch_mode' => true],
        );
    }
}
