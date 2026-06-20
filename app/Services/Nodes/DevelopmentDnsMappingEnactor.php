<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Facades\File;

class DevelopmentDnsMappingEnactor
{
    public function __construct(private readonly ?string $configDir = null) {}

    /**
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     * }
     */
    public function converge(Node $node): array
    {
        return $this->convergeMapping($this->mappingFor($node));
    }

    /**
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     * }
     */
    public function convergeDevelopmentRole(Node $node, string $tld): array
    {
        return $this->convergeMapping($this->mappingForDevelopmentRole($node, $tld));
    }

    /**
     * @param  array{
     *      node: string,
     *      tld: string,
     *      domain: string,
     *      target: string,
     *  }|null  $mapping
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     * }
     */
    private function convergeMapping(?array $mapping): array
    {
        if ($mapping === null) {
            return [
                'status' => 'not_applicable',
                'changed' => false,
            ];
        }

        File::ensureDirectoryExists($this->configDir());

        $path = $this->configPath($mapping['tld']);
        $content = $this->content($mapping);

        if (File::exists($path) && File::get($path) === $content) {
            return [
                'status' => 'already_configured',
                'changed' => false,
                'domain' => $mapping['domain'],
                'target' => $mapping['target'],
                'path' => $path,
            ];
        }

        File::put($path, $content);

        return [
            'status' => 'configured',
            'changed' => true,
            'domain' => $mapping['domain'],
            'target' => $mapping['target'],
            'path' => $path,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     *     reason?: string,
     * }
     */
    public function remove(Node $node): array
    {
        return $this->removeMapping($this->mappingFor($node));
    }

    /**
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     *     reason?: string,
     * }
     */
    public function removeDevelopmentRole(Node $node, string $tld): array
    {
        return $this->removeMapping($this->mappingForDevelopmentRole($node, $tld));
    }

    /**
     * @param  array{
     *      node: string,
     *      tld: string,
     *      domain: string,
     *      target: string,
     *  }|null  $mapping
     * @return array{
     *     status: string,
     *     changed: bool,
     *     domain?: string,
     *     target?: string,
     *     path?: string,
     *     reason?: string,
     * }
     */
    private function removeMapping(?array $mapping): array
    {
        if ($mapping === null) {
            return [
                'status' => 'not_applicable',
                'changed' => false,
            ];
        }

        $path = $this->configPath($mapping['tld']);

        if (! File::exists($path)) {
            return [
                'status' => 'already_absent',
                'changed' => false,
                'domain' => $mapping['domain'],
                'target' => $mapping['target'],
                'path' => $path,
            ];
        }

        try {
            $removed = File::delete($path);
        } catch (\Throwable $exception) {
            return [
                'status' => 'failed',
                'changed' => false,
                'domain' => $mapping['domain'],
                'target' => $mapping['target'],
                'path' => $path,
                'reason' => $exception->getMessage(),
            ];
        }

        if (! $removed) {
            return [
                'status' => 'failed',
                'changed' => false,
                'domain' => $mapping['domain'],
                'target' => $mapping['target'],
                'path' => $path,
                'reason' => 'file delete returned false',
            ];
        }

        return [
            'status' => 'removed',
            'changed' => true,
            'domain' => $mapping['domain'],
            'target' => $mapping['target'],
            'path' => $path,
        ];
    }

    /**
     * @return array{
     *     node: string,
     *     tld: string,
     *     domain: string,
     *     target: string,
     * }|null
     */
    public function mappingFor(Node $node): ?array
    {
        $assignment = app(NodeRoleAssignments::class)->activeAssignment($node, NodeRoleName::AppDevelopment->value);

        if (! $assignment instanceof NodeRoleAssignment) {
            return null;
        }

        $settings = $assignment->settings ?? [];
        $tld = is_array($settings) ? ($settings['tld'] ?? null) : null;

        if (! is_string($tld)) {
            return null;
        }

        return $this->mappingForDevelopmentRole($node, $tld);
    }

    /**
     * @return array{
     *     node: string,
     *     tld: string,
     *     domain: string,
     *     target: string,
     * }|null
     */
    public function mappingForDevelopmentRole(Node $node, string $tld): ?array
    {
        if (! $node->isActive() && ! $node->isProvisioning()) {
            return null;
        }

        $tld = trim($tld);

        if ($tld === '') {
            return null;
        }

        if (! $this->isValidTld($tld)) {
            return null;
        }

        if (! is_string($node->wireguard_address) || trim($node->wireguard_address) === '') {
            return null;
        }

        return [
            'node' => (string) $node->name,
            'tld' => $tld,
            'domain' => '*.'.$tld,
            'target' => trim($node->wireguard_address),
        ];
    }

    public function configDir(): string
    {
        return $this->configDir ?? rtrim((string) config('orbit.paths.config_root', storage_path('app/orbit')), '/').'/node-development-dns.d';
    }

    /**
     * @param  array{node: string, tld: string, domain: string, target: string}  $mapping
     */
    private function content(array $mapping): string
    {
        return implode("\n", [
            '# orbit-managed=node-development-dns',
            "# node={$mapping['node']}",
            '# bind-scope=orbit_network',
            "address=/{$mapping['tld']}/{$mapping['target']}",
            '',
        ]);
    }

    private function configPath(string $tld): string
    {
        return $this->configDir()."/{$tld}.conf";
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
    }
}
