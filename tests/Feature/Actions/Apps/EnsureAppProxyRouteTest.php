<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProxyRoute;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class EnsureAppProxyRouteTestShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class EnsureAppProxyRouteTestCertificateInstaller implements SiteCertificateInstaller
{
    /** @var list<string> */
    public array $hosts = [];

    /**
     * @return array{cert: string, key: string}
     */
    public function ensureFor(Node $node, string $domain): array
    {
        $this->hosts[] = $domain;

        return $this->expectedPathsFor($node, $domain);
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function expectedPathsFor(Node $node, string $domain): array
    {
        return [
            'cert' => "/home/orbit/.config/orbit/certs/{$domain}.crt",
            'key' => "/home/orbit/.config/orbit/certs/{$domain}.key",
        ];
    }
}

it('creates a PHP app proxy route targeting the FrankenPHP runtime container', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'document_root' => 'public',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(EnsureAppProxyRoute::class)->handle($app);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $siteScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/docs.test.caddy'));
    $caddySite = base64_decode((string) str((string) $siteScript)->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($route->domain)->toBe('docs.test')
        ->and($route->config['runtime_upstream'])->toBe('http://orbit-app-docs:8080')
        ->and($route->config['php_socket'])->toBeNull()
        ->and($caddySite)->toContain('reverse_proxy http://orbit-app-docs:8080')
        ->and($caddySite)->not->toContain('php_fastcgi')
        ->and($caddySite)->not->toContain('file_server');
});

it('creates a static app proxy route with file_server', function (): void {
    $node = Node::factory()->appDev()->create(['tld' => 'test']);
    $app = App::factory()->for($node, 'node')->static()->create([
        'name' => 'marketing',
        'document_root' => 'public',
    ]);

    $shell = new EnsureAppProxyRouteTestShell;
    $certificates = new EnsureAppProxyRouteTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(EnsureAppProxyRoute::class)->handle($app);

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();
    $siteScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/marketing.test.caddy'));
    $caddySite = base64_decode((string) str((string) $siteScript)->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($route->domain)->toBe('marketing.test')
        ->and($route->config['runtime_upstream'])->toBeNull()
        ->and($route->config['php_socket'])->toBeNull()
        ->and($caddySite)->toContain('file_server')
        ->and($caddySite)->toContain("root * {$app->path}/public")
        ->and($caddySite)->not->toContain('php_fastcgi')
        ->and($caddySite)->not->toContain('reverse_proxy');
});
