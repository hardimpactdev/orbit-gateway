<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use Illuminate\Support\Facades\File;

class DevelopmentDnsMappingProbe
{
    public function __construct(private readonly ?DevelopmentDnsMappingEnactor $enactor = null) {}

    /**
     * @return array{
     *     exists: bool,
     *     public_exposure: bool,
     *     domain?: string,
     *     expected_target?: string,
     *     actual_target?: string|null,
     *     expected_owner?: string,
     *     actual_owner?: string|null,
     *     path?: string,
     * }
     */
    public function inspect(Node $node): array
    {
        $enactor = $this->enactor();
        $mapping = $enactor->mappingFor($node);

        if ($mapping === null) {
            return [
                'exists' => false,
                'public_exposure' => false,
            ];
        }

        $path = $enactor->configDir()."/{$mapping['tld']}.conf";

        if (! File::exists($path)) {
            return [
                'exists' => false,
                'public_exposure' => false,
                'domain' => $mapping['domain'],
                'expected_target' => $mapping['target'],
                'expected_owner' => $mapping['node'],
                'path' => $path,
            ];
        }

        $content = File::get($path);

        return [
            'exists' => true,
            'public_exposure' => ! str_contains($content, '# bind-scope=orbit_network'),
            'domain' => $mapping['domain'],
            'expected_target' => $mapping['target'],
            'actual_target' => $this->targetFrom($content, $mapping['tld']),
            'expected_owner' => $mapping['node'],
            'actual_owner' => $this->ownerFrom($content),
            'path' => $path,
        ];
    }

    /**
     * @return array{
     *     exists: bool,
     *     public_exposure: bool,
     *     domain?: string,
     *     expected_target?: string,
     *     actual_target?: string|null,
     *     expected_owner?: string,
     *     actual_owner?: string|null,
     *     path?: string,
     * }
     */
    public function inspectForTld(Node $node, string $tld): array
    {
        $enactor = $this->enactor();
        $mapping = $enactor->mappingForDevelopmentRole($node, $tld);

        if ($mapping === null) {
            return [
                'exists' => false,
                'public_exposure' => false,
            ];
        }

        $path = $enactor->configDir()."/{$mapping['tld']}.conf";

        if (! File::exists($path)) {
            return [
                'exists' => false,
                'public_exposure' => false,
                'domain' => $mapping['domain'],
                'expected_target' => $mapping['target'],
                'expected_owner' => $mapping['node'],
                'path' => $path,
            ];
        }

        $content = File::get($path);

        return [
            'exists' => true,
            'public_exposure' => ! str_contains($content, '# bind-scope=orbit_network'),
            'domain' => $mapping['domain'],
            'expected_target' => $mapping['target'],
            'actual_target' => $this->targetFrom($content, $mapping['tld']),
            'expected_owner' => $mapping['node'],
            'actual_owner' => $this->ownerFrom($content),
            'path' => $path,
        ];
    }

    private function enactor(): DevelopmentDnsMappingEnactor
    {
        return $this->enactor ?? app(DevelopmentDnsMappingEnactor::class);
    }

    private function targetFrom(string $content, string $tld): ?string
    {
        if (preg_match('/^address=\/(?:\\.)?'.preg_quote($tld, '/').'\/(.+)$/m', $content, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function ownerFrom(string $content): ?string
    {
        if (preg_match('/^# node=(.+)$/m', $content, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
