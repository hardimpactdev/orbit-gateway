<?php

declare(strict_types=1);

namespace App\Services\Ca;

use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

readonly class OrbitCaService
{
    private const int ROOT_VALIDITY_DAYS = 3650;

    private const int LEAF_VALIDITY_DAYS = 90;

    private const int RENEW_IF_WITHIN_SECONDS = 30 * 86400;

    public function ensureRootCa(): void
    {
        if (! $this->isLocalNodeGateway()) {
            throw new RuntimeException('Root CA can only be generated on a gateway node.');
        }

        $caDir = $this->caDir();
        File::ensureDirectoryExists($caDir);

        $rootKey = "{$caDir}/root.key";
        $rootCrt = "{$caDir}/root.crt";

        if (File::exists($rootKey) && File::exists($rootCrt)) {
            return;
        }

        if (File::exists($rootKey) || File::exists($rootCrt)) {
            throw new RuntimeException("Partial Orbit root CA state found in {$caDir}; restore the missing file before continuing.");
        }

        Process::run(sprintf('openssl genrsa -out %s 4096', escapeshellarg($rootKey)))->throw();
        chmod($rootKey, 0600);

        Process::run(implode(' ', [
            'openssl req -x509 -new -nodes',
            '-key '.escapeshellarg($rootKey),
            '-sha256 -days '.self::ROOT_VALIDITY_DAYS,
            '-out '.escapeshellarg($rootCrt),
            '-subj '.escapeshellarg('/CN=Orbit Root CA/O=Orbit'),
        ]))->throw();
    }

    /** @return array{cert: string, key: string} */
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        if (! $this->isLocalNodeGateway()) {
            throw new RuntimeException('Leaf certificates can only be issued on a gateway node.');
        }

        $this->assertRootExists();

        $certsDir = $this->certsDir();
        File::ensureDirectoryExists($certsDir);

        $filename = $this->filenameFor($host);
        $certPath = "{$certsDir}/{$filename}.crt";
        $keyPath = "{$certsDir}/{$filename}.key";

        $sans = array_values(array_unique([$host, ...$additionalSans]));

        if (File::exists($certPath) && File::exists($keyPath) && $this->isLeafFresh($certPath) && $this->leafCoversSans($certPath, $sans)) {
            return ['cert' => $certPath, 'key' => $keyPath];
        }

        $this->signLeaf($host, $sans, $certPath, $keyPath);

        return ['cert' => $certPath, 'key' => $keyPath];
    }

    public function rootCert(): string
    {
        if (! $this->isLocalNodeGateway()) {
            throw new RuntimeException('Root CA can only be read on a gateway node.');
        }

        $this->assertRootExists();

        return File::get($this->caDir().'/root.crt');
    }

    /**
     * @param  list<string>  $sans
     */
    private function signLeaf(string $host, array $sans, string $certPath, string $keyPath): void
    {
        $caDir = $this->caDir();
        $rootCrt = "{$caDir}/root.crt";
        $rootKey = "{$caDir}/root.key";

        $tmp = tempnam(sys_get_temp_dir(), 'orbit-leaf-');
        $csrPath = "{$tmp}.csr";
        $extPath = "{$tmp}.ext";

        try {
            if (! File::exists($keyPath)) {
                Process::run(sprintf('openssl genrsa -out %s 2048', escapeshellarg($keyPath)))->throw();
                chmod($keyPath, 0600);
            }

            Process::run(sprintf(
                'openssl req -new -key %s -out %s -subj %s',
                escapeshellarg($keyPath),
                escapeshellarg($csrPath),
                escapeshellarg("/CN={$host}"),
            ))->throw();

            $sanLine = 'subjectAltName='.implode(',', array_map($this->sanFor(...), $sans));

            File::put($extPath, implode("\n", [
                $sanLine,
                'keyUsage=digitalSignature,keyEncipherment',
                'extendedKeyUsage=serverAuth',
            ]));

            $serial = '0x'.bin2hex(random_bytes(16));

            Process::run(implode(' ', [
                'openssl x509 -req',
                '-in '.escapeshellarg($csrPath),
                '-CA '.escapeshellarg($rootCrt),
                '-CAkey '.escapeshellarg($rootKey),
                '-set_serial '.escapeshellarg($serial),
                '-out '.escapeshellarg($certPath),
                '-days '.self::LEAF_VALIDITY_DAYS,
                '-sha256',
                '-extfile '.escapeshellarg($extPath),
            ]))->throw();
        } finally {
            File::delete([$csrPath, $extPath, $tmp]);
        }
    }

    private function isLeafFresh(string $certPath): bool
    {
        return Process::run(sprintf(
            'openssl x509 -checkend %d -noout -in %s',
            self::RENEW_IF_WITHIN_SECONDS,
            escapeshellarg($certPath),
        ))->successful();
    }

    /**
     * @param  list<string>  $sans
     */
    private function leafCoversSans(string $certPath, array $sans): bool
    {
        $result = Process::run(sprintf(
            'openssl x509 -in %s -noout -ext subjectAltName',
            escapeshellarg($certPath),
        ));

        if (! $result->successful()) {
            return false;
        }

        $actualSans = $this->subjectAltNamesFrom($result->output());

        return array_all($sans, fn ($san) => in_array($this->sanTextFor($san), $actualSans, true));
    }

    /**
     * @return list<string>
     */
    private function subjectAltNamesFrom(string $output): array
    {
        preg_match_all('/(?:DNS:[^,\s]+|IP Address:[^,\s]+)/', $output, $matches);

        return array_values(array_unique($matches[0]));
    }

    private function sanFor(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false
            ? "IP:{$value}"
            : "DNS:{$value}";
    }

    private function sanTextFor(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false
            ? "IP Address:{$value}"
            : "DNS:{$value}";
    }

    private function assertRootExists(): void
    {
        $caDir = $this->caDir();

        if (! File::exists("{$caDir}/root.crt") || ! File::exists("{$caDir}/root.key")) {
            throw new RuntimeException('Orbit root CA is not bootstrapped; run `orbit node:new --template=gateway`.');
        }
    }

    private function isLocalNodeGateway(): bool
    {
        return app(NodeRoleAssignments::class)->activeGatewayNodeQuery()->exists();
    }

    private function caDir(): string
    {
        return $this->configRoot().'/ca';
    }

    private function certsDir(): string
    {
        return $this->configRoot().'/certs';
    }

    private function configRoot(): string
    {
        $configRoot = config('orbit.paths.config_root');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            throw new RuntimeException('Orbit config root is not configured.');
        }

        return rtrim($configRoot, '/');
    }

    private function filenameFor(string $host): string
    {
        if (preg_match('#[/\\\\\s]#', $host)) {
            throw new RuntimeException("Invalid host for cert filename: {$host}");
        }

        return $host;
    }
}
