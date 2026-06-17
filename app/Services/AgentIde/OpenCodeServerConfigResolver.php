<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Data\AgentIde\OpenCodeServerConfig;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;

final class OpenCodeServerConfigResolver
{
    public function resolve(App $app): OpenCodeServerConfig
    {
        $app->loadMissing('node');

        $node = $app->node;
        $tool = $node instanceof Node
            ? NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'opencode-server')
                ->first()
            : null;

        $toolConfig = is_array($tool?->config) ? $tool->config : [];
        $credentials = is_array($tool?->credentials) ? $tool->credentials : [];
        $fields = is_array($credentials['fields'] ?? null) ? $credentials['fields'] : [];
        $nodeConfig = is_array($node?->agent_ide_config) ? $node->agent_ide_config : [];
        $openCodeConfig = is_array($nodeConfig['opencode'] ?? null) ? $nodeConfig['opencode'] : [];

        return new OpenCodeServerConfig(
            url: $this->normalizeBaseUrl(
                $this->stringValue($fields['url'] ?? null)
                    ?? $this->stringValue($fields['Url'] ?? null)
                    ?? $this->urlFromCredentialFields($fields, $node)
                    ?? $this->endpointFromTool($tool, $node)
                    ?? $this->urlFromToolConfig($toolConfig, $node)
                    ?? $this->stringValue($openCodeConfig['url'] ?? null)
                    ?? $this->urlFromNode($node),
            ),
            username: $this->authValue(
                $fields['username'] ?? null,
                $fields['Username'] ?? null,
                $credentials['username'] ?? null,
                $toolConfig['username'] ?? null,
                $openCodeConfig['username'] ?? null,
            ),
            password: $this->authValue(
                $fields['password'] ?? null,
                $fields['Password'] ?? null,
                $credentials['password'] ?? null,
                $toolConfig['password'] ?? null,
                $openCodeConfig['password'] ?? null,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function urlFromCredentialFields(array $fields, ?Node $node): ?string
    {
        $host = $this->stringValue($fields['host'] ?? null)
            ?? $this->stringValue($fields['Host'] ?? null);
        $port = $fields['port'] ?? $fields['Port'] ?? null;

        return $this->urlFromHostPort($host, $port, $node);
    }

    private function endpointFromTool(?NodeTool $tool, ?Node $node): ?string
    {
        $config = is_array($tool?->config) ? $tool->config : [];
        $endpoints = is_array($config['endpoints'] ?? null) ? $config['endpoints'] : [];

        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $url = $this->stringValue($endpoint['url'] ?? null);

            if ($url !== null) {
                return $this->reachableUrl($url, $node);
            }

            $host = $this->stringValue($endpoint['host'] ?? null);
            $port = $endpoint['port'] ?? null;
            $hostPortUrl = $this->urlFromHostPort($host, $port, $node, $this->stringValue($endpoint['scheme'] ?? null) ?? 'http');

            if ($hostPortUrl !== null) {
                return $hostPortUrl;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function urlFromToolConfig(array $config, ?Node $node): ?string
    {
        $hostname = $this->stringValue($config['hostname'] ?? null);
        $port = $config['port'] ?? null;

        if ($hostname === null || (! is_int($port) && ! is_string($port))) {
            return null;
        }

        return $this->urlFromHostPort($hostname, $port, $node);
    }

    private function urlFromHostPort(?string $host, mixed $port, ?Node $node, string $scheme = 'http'): ?string
    {
        if ($host === null || (! is_int($port) && ! is_string($port))) {
            return null;
        }

        $host = $this->reachableHost($host, $node);

        return "{$scheme}://{$host}:{$port}";
    }

    private function urlFromNode(?Node $node): string
    {
        $host = $this->stringValue($node?->wireguard_address)
            ?? $this->stringValue($node?->host)
            ?? '127.0.0.1';

        return "http://{$host}:4096";
    }

    private function reachableUrl(string $url, ?Node $node): string
    {
        $normalized = $this->normalizeBaseUrl($url);
        $parts = parse_url($normalized);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return $url;
        }

        $host = $this->reachableHost($parts['host'], $node);
        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'http';
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function reachableHost(string $host, ?Node $node): string
    {
        if (in_array($host, ['0.0.0.0', '127.0.0.1', '::1', 'localhost'], true) && $node instanceof Node && ! $node->hasActiveRole('gateway')) {
            return $this->stringValue($node->wireguard_address)
                ?? $this->stringValue($node->host)
                ?? $host;
        }

        return $host;
    }

    private function normalizeBaseUrl(string $url): string
    {
        return rtrim(str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : "http://{$url}", '/');
    }

    private function authValue(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);

            if ($string === null || $string === '(no auth)') {
                continue;
            }

            return $string;
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
