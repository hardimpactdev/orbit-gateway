<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\AdoptAction;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;

final readonly class ProxyRouteAdopter
{
    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ProbeSnapshot $snapshot): array
    {
        $results = [];

        foreach ($snapshot->keys() as $domain) {
            $entry = $snapshot->get($domain) ?? [];
            $body = (string) ($entry['body'] ?? '');
            $hash = (string) ($entry['hash'] ?? '');

            if ($body === '' || $hash === '') {
                $results[] = new AdoptResult(
                    family: 'proxy',
                    key: $domain,
                    action: AdoptAction::Skipped,
                    summary: "{$domain}: skipped (unreadable)",
                );

                continue;
            }

            $existing = ProxyRoute::query()
                ->where('domain', $domain)
                ->first();

            if ($existing instanceof ProxyRoute) {
                $results[] = new AdoptResult(
                    family: 'proxy',
                    key: $domain,
                    action: AdoptAction::Skipped,
                    summary: "{$domain}: skipped (already in registry)",
                );

                continue;
            }

            $app = App::query()->where('domain', $domain)->first();

            if ($app instanceof App) {
                $results[] = new AdoptResult(
                    family: 'proxy',
                    key: $domain,
                    action: AdoptAction::Skipped,
                    summary: "{$domain}: skipped (conflicts with app {$app->name})",
                );

                continue;
            }

            if ($this->isWorkspaceDomain($domain, $node)) {
                $results[] = new AdoptResult(
                    family: 'proxy',
                    key: $domain,
                    action: AdoptAction::Skipped,
                    summary: "{$domain}: skipped (matches workspace pattern)",
                );

                continue;
            }

            $kind = $this->classifyKind($body);

            if ($kind === null) {
                $results[] = new AdoptResult(
                    family: 'proxy',
                    key: $domain,
                    action: AdoptAction::Skipped,
                    summary: "{$domain}: skipped (not a custom proxy/redirect)",
                );

                continue;
            }

            $config = $this->extractConfig($body, $kind);

            if ($config === null) {
                $results[] = new AdoptResult(
                    family: 'proxy',
                    key: $domain,
                    action: AdoptAction::Skipped,
                    summary: "{$domain}: skipped (unparseable target)",
                );

                continue;
            }

            ProxyRoute::query()->create([
                'node_id' => $node->id,
                'domain' => $domain,
                'owner_type' => 'custom',
                'kind' => $kind,
                'source_hash' => $hash,
                'config' => $config,
            ]);

            $results[] = new AdoptResult(
                family: 'proxy',
                key: $domain,
                action: AdoptAction::Created,
                summary: "Created custom proxy route '{$domain}' ({$kind})",
            );
        }

        return $results;
    }

    private function isWorkspaceDomain(string $domain, Node $node): bool
    {
        $apps = App::query()
            ->where('node_id', $node->id)
            ->get();

        $tld = (string) ($node->tld ?? '');
        $tldSuffix = $tld !== '' ? '.'.$tld : '';

        foreach ($apps as $app) {
            $suffix = ".{$app->name}".$tldSuffix;

            if (str_ends_with($domain, $suffix) && $domain !== $app->name.$tldSuffix) {
                $workspaceName = substr($domain, 0, -strlen($suffix));
                $workspace = Workspace::query()
                    ->where('app_id', $app->id)
                    ->where('name', $workspaceName)
                    ->first();

                if ($workspace instanceof Workspace) {
                    return true;
                }
            }
        }

        return false;
    }

    private function classifyKind(string $body): ?string
    {
        if (str_contains($body, 'root *')) {
            return null;
        }

        if (preg_match('/^\s*https:\/\/(?:(?:\d{1,3}\.){3}\d{1,3})\b/m', $body) === 1) {
            return null;
        }

        if (str_contains($body, 'redir')) {
            return 'redirect';
        }

        if (str_contains($body, 'reverse_proxy')) {
            return 'proxy';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractConfig(string $body, string $kind): ?array
    {
        if ($kind === 'redirect') {
            if (preg_match('/redir\s+(\S+)(?:\s+(\d+))?/', $body, $m) === 1) {
                $target = str_replace('{uri}', '', $m[1]);
                $code = isset($m[2]) ? (int) $m[2] : 302;

                return [
                    'target' => ['type' => 'redirect', 'value' => $target],
                    'code' => $code,
                ];
            }

            return null;
        }

        if ($kind === 'proxy') {
            if (preg_match('/reverse_proxy\s+(\S+)/', $body, $m) === 1) {
                return [
                    'target' => ['type' => 'upstream', 'value' => $m[1]],
                    'upstream' => $m[1],
                ];
            }

            return null;
        }

        return null;
    }
}
