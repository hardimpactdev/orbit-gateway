<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\AgentRoleSettings;
use App\Data\Nodes\RoleSettings\AppDevelopmentRoleSettings;
use App\Data\Nodes\RoleSettings\AppProductionRoleSettings;
use App\Data\Nodes\RoleSettings\DatabaseRoleSettings;
use App\Data\Nodes\RoleSettings\EmptyRoleSettings;
use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Data\Nodes\RoleSettings\VpnRoleSettings;
use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Services\Nodes\Roles\NodeRoleRegistry;

describe('node role registry', function (): void {
    it('defines the initial role compatibility matrix', function (): void {
        $registry = new NodeRoleRegistry;

        expect($registry->definition('gateway')->conflictsWith)->toBe([
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
        ]);

        expect($registry->definition('vpn')->conflictsWith)->toBe([
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
        ]);

        expect($registry->definition('router')->conflictsWith)->toBe([
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
        ]);

        expect($registry->definition('app-dev')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'router',
            'app-prod',
            'agent',
            'ingress',
        ]);

        expect($registry->definition('app-prod')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'database',
            'agent',
            'websocket',
            's3',
        ]);

        expect($registry->definition('database')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'router',
            'app-prod',
            'agent',
            'ingress',
        ]);

        expect($registry->definition('agent')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'ingress',
            'websocket',
            's3',
            'metrics',
        ]);

        expect($registry->definition('ingress')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'database',
            'agent',
            'websocket',
            's3',
        ]);

        expect($registry->definition('websocket')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'router',
            'app-prod',
            'agent',
            'ingress',
        ]);

        expect($registry->definition('websocket')->conflictsWith)
            ->not->toContain('app-dev')
            ->not->toContain('database')
            ->not->toContain('s3');

        expect($registry->definition('s3')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'router',
            'app-prod',
            'agent',
            'ingress',
        ]);

        expect($registry->definition('s3')->conflictsWith)
            ->not->toContain('app-dev')
            ->not->toContain('database')
            ->not->toContain('websocket');

        expect($registry->definition('metrics')->conflictsWith)->toBe([
            'agent',
        ]);
    });

    it('defines supported platforms and assignability for the initial roles', function (): void {
        $registry = new NodeRoleRegistry;

        expect($registry->definition('gateway')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('gateway')->assignableByRoleCommand)->toBeFalse()
            ->and($registry->definition('gateway')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('router')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('router')->assignableByRoleCommand)->toBeFalse()
            ->and($registry->definition('router')->assignableByNodeNew)->toBeFalse()
            ->and($registry->definition('app-dev')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('app-dev')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('app-dev')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('app-prod')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('app-prod')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('app-prod')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('database')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('database')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('database')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('agent')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('agent')->assignableByRoleCommand)->toBeFalse()
            ->and($registry->definition('agent')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('ingress')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('ingress')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('ingress')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('websocket')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('websocket')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('websocket')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('vpn')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('vpn')->assignableByRoleCommand)->toBeFalse()
            ->and($registry->definition('vpn')->assignableByNodeNew)->toBeFalse()
            ->and($registry->definition('s3')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('s3')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('s3')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('metrics')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('metrics')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('metrics')->assignableByNodeNew)->toBeTrue();
    });

    it('hydrates role-specific settings dtos', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('app-prod')
            ->settingsFromArray(['ingress_node_id' => 12]);

        expect($settings)
            ->toBeInstanceOf(AppProductionRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['ingress_node_id' => 12]);
    });

    it('hydrates app development settings dtos', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('app-dev')
            ->settingsFromArray(['tld' => 'test']);

        expect($settings)
            ->toBeInstanceOf(AppDevelopmentRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['tld' => 'test']);
    });

    it('hydrates s3 settings dtos with default data path', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('s3')
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf(S3RoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['data_path' => '/srv/orbit/s3/data']);
    });

    it('hydrates agent settings dtos with default tld', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf(AgentRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['tld' => 'agent']);
    });

    it('hydrates agent settings dtos with explicit tld', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => 'custom']);

        expect($settings)
            ->toBeInstanceOf(AgentRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['tld' => 'custom']);
    });

    it('hydrates vpn settings with defaults', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('vpn')
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf(VpnRoleSettings::class)
            ->and($settings->toArray())
            ->toBe([
                'public_endpoint' => null,
                'wireguard_cidr' => '10.6.0.0/24',
                'wireguard_port' => 51820,
                'dns_ip' => '10.6.0.1',
            ]);
    });

    it('hydrates explicit vpn settings', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('vpn')
            ->settingsFromArray([
                'public_endpoint' => ' vpn.example.com ',
                'wireguard_cidr' => ' 10.44.0.0/24 ',
                'wireguard_port' => 51821,
                'dns_ip' => ' 10.44.0.1 ',
            ]);

        expect($settings)
            ->toBeInstanceOf(VpnRoleSettings::class)
            ->and($settings->toArray())
            ->toBe([
                'public_endpoint' => 'vpn.example.com',
                'wireguard_cidr' => '10.44.0.0/24',
                'wireguard_port' => 51821,
                'dns_ip' => '10.44.0.1',
            ]);
    });

    it('hydrates websocket settings dtos', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('websocket')
            ->settingsFromArray(['redis_node_id' => 12]);

        expect($settings)
            ->toBeInstanceOf(WebSocketRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['redis_node_id' => 12]);
    });

    it('hydrates empty settings dtos for roles without settings', function (string $role, string $class): void {
        $settings = (new NodeRoleRegistry)
            ->definition($role)
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf($class)
            ->and($settings->toArray())
            ->toBe([]);
    })->with([
        ['gateway', EmptyRoleSettings::class],
        ['router', EmptyRoleSettings::class],
        ['database', DatabaseRoleSettings::class],
        ['ingress', EmptyRoleSettings::class],
        ['metrics', EmptyRoleSettings::class],
    ]);

    it('rejects invalid app development settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('app-dev')
            ->settingsFromArray(['tld' => '']))
            ->toThrow(InvalidArgumentException::class, 'The app-dev role requires a valid tld setting.');
    });

    it('rejects path-like app development tld settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('app-dev')
            ->settingsFromArray(['tld' => '../../orbit']))
            ->toThrow(InvalidArgumentException::class, 'The app-dev role requires a valid tld setting.');
    });

    it('rejects unknown app development settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('app-dev')
            ->settingsFromArray(['tld' => 'test', 'unexpected' => 'value']))
            ->toThrow(InvalidArgumentException::class, 'The app-dev role does not accept unknown settings.');
    });

    it('rejects invalid agent settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => '']))
            ->toThrow(InvalidArgumentException::class, 'The agent role requires a valid tld setting.');
    });

    it('rejects path-like agent tld settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => '../../orbit']))
            ->toThrow(InvalidArgumentException::class, 'The agent role requires a valid tld setting.');
    });

    it('rejects unknown agent settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => 'test', 'unexpected' => 'value']))
            ->toThrow(InvalidArgumentException::class, 'The agent role does not accept unknown settings.');
    });

    it('rejects invalid vpn settings', function (array $settings, string $message): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('vpn')
            ->settingsFromArray($settings))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'unknown key' => [['unexpected' => 'value'], 'The vpn role does not accept unknown settings.'],
        'bad endpoint' => [['public_endpoint' => 'not-a-dotted-host'], 'The vpn role requires a valid public endpoint setting.'],
        'bad cidr' => [['wireguard_cidr' => '10.6.0.0'], 'The vpn role requires a valid IPv4 CIDR setting.'],
        'bad port' => [['wireguard_port' => 70000], 'The vpn role requires a valid WireGuard port.'],
        'bad dns' => [['dns_ip' => 'not-an-ip'], 'The vpn role requires a valid DNS IP setting.'],
    ]);

    it('rejects invalid vpn constructor values', function (?string $publicEndpoint, string $wireguardCidr, int $wireguardPort, string $dnsIp, string $message): void {
        expect(fn () => new VpnRoleSettings(
            publicEndpoint: $publicEndpoint,
            wireguardCidr: $wireguardCidr,
            wireguardPort: $wireguardPort,
            dnsIp: $dnsIp,
        ))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'bad endpoint' => ['not-a-dotted-host', '10.6.0.0/24', 51820, '10.6.0.1', 'The vpn role requires a valid public endpoint setting.'],
        'bad cidr' => ['203.0.113.10', '10.6.0.0/024', 51820, '10.6.0.1', 'The vpn role requires a valid IPv4 CIDR setting.'],
        'bad port' => ['203.0.113.10', '10.6.0.0/24', 70000, '10.6.0.1', 'The vpn role requires a valid WireGuard port.'],
        'bad dns' => ['203.0.113.10', '10.6.0.0/24', 51820, 'not-an-ip', 'The vpn role requires a valid DNS IP setting.'],
    ]);

    it('rejects settings for roles without settings', function (string $role): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition($role)
            ->settingsFromArray(['unexpected' => 'value']))
            ->toThrow(InvalidArgumentException::class, 'This role does not accept settings.');
    })->with(['gateway', 'router', 'database', 'ingress', 'metrics']);

    it('accepts empty app production settings for compatibility with existing rows', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('app-prod')
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf(AppProductionRoleSettings::class)
            ->and($settings->toArray())
            ->toBe([]);
    });

    it('rejects invalid app production settings', function (array $settings, string $message): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('app-prod')
            ->settingsFromArray($settings))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'unknown key' => [['ingress_node_id' => 12, 'unexpected' => true], 'The app-prod role does not accept unknown settings.'],
        'not integer' => [['ingress_node_id' => '12'], 'The app-prod role requires a positive ingress_node_id setting when provided.'],
        'not positive' => [['ingress_node_id' => 0], 'The app-prod role requires a positive ingress_node_id setting when provided.'],
    ]);

    it('rejects unknown roles', function (): void {
        expect(fn () => (new NodeRoleRegistry)->definition('queue'))
            ->toThrow(InvalidArgumentException::class, 'Unknown node role [queue].');
    });

    it('defines the node role name enum values', function (): void {
        expect(array_map(
            static fn (NodeRoleName $role): string => $role->value,
            NodeRoleName::cases(),
        ))->toBe([
            'gateway',
            'vpn',
            'router',
            'app-dev',
            'app-prod',
            'database',
            'agent',
            'ingress',
            'websocket',
            's3',
            'metrics',
        ]);
    });

    it('defines the node role status enum values', function (): void {
        expect(array_map(
            static fn (NodeRoleStatus $status): string => $status->value,
            NodeRoleStatus::cases(),
        ))->toBe([
            'pending',
            'active',
            'error',
            'removing',
        ]);
    });
});
