<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EWgEasyGateway;
use App\E2E\Support\E2EWireGuardIdentitySet;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    m::close();

    if (isset($this->wgEasyGatewayTemp) && is_string($this->wgEasyGatewayTemp) && is_dir($this->wgEasyGatewayTemp)) {
        File::deleteDirectory($this->wgEasyGatewayTemp);
    }
});

it('uses fixed E2E WireGuard identities for prepared topology roles', function (): void {
    expect(E2EWireGuardIdentitySet::forRole('gateway'))
        ->toBe(E2EWireGuardIdentitySet::forRole('gateway'))
        ->and(E2EWireGuardIdentitySet::forRole('gateway')['public_key'])->toBe('FGvPNoz2W40e67fcPssa5XgmqJJWOY6PGReQZ1eQ2T0=')
        ->and(E2EWireGuardIdentitySet::forRole('operator')['public_key'])->toBe('8Kk1eHvFjl9KapqdZ6U2epl3KkMLhscWFhABalzBplk=')
        ->and(E2EWireGuardIdentitySet::version())->toBe('2026-06-05.1');
});

function e2eWgEasyGatewayResult(bool $successful = true, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

it('starts wg-easy as the only WireGuard server on host UDP 51820', function (): void {
    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('gateway');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWgEasyGatewayResult();
        });

    (new E2EWgEasyGateway)->start($instance, '10.231.0.11');

    expect($commands[0])->toContain('docker run -d')
        ->and($commands[0])->toContain('--name wg-easy')
        ->and($commands[0])->toContain('command -v docker')
        ->and($commands[0])->not->toContain('apt-get')
        ->and($commands[0])->toContain('-p 51820:51820/udp')
        ->and($commands[0])->not->toContain('51822')
        ->and($commands[0])->not->toContain('wg-quick down wg-orbit')
        ->and($commands[0])->not->toContain('sqlite3')
        ->and($commands[0])->toContain('-p 127.0.0.1:51821:51821/tcp')
        ->and($commands[0])->toContain('--cap-add NET_ADMIN')
        ->and($commands[0])->toContain('--cap-add SYS_MODULE')
        ->and($commands[0])->toContain('-v /lib/modules:/lib/modules:ro')
        ->and($commands[0])->toContain('ghcr.io/wg-easy/wg-easy:15')
        ->and($commands[0])->toContain('docker exec wg-easy wg show wg0 public-key')
        ->and($commands[0])->toContain('test -n "${wg_easy_public_key:-}"')
        ->and($commands[0])->toContain('docker exec wg-easy ip addr replace 10.6.0.1/24 dev wg0')
        ->and($commands[0])->toContain('docker exec wg-easy ip route replace 10.6.0.0/24 dev wg0')
        ->and($commands[0])->toContain('sudo -u orbit env')
        ->and($commands[0])->toContain('ORBIT_WG_EASY_ADVERTISED_HOST=')
        ->and($commands[0])->toContain('PDO::SQLITE_OPEN_READWRITE')
        ->and($commands[0])->toContain('UPDATE interfaces_table SET ipv4_cidr = :ipv4_cidr WHERE name = :name')
        ->and($commands[0])->toContain('UPDATE user_configs_table')
        ->and($commands[0])->toContain('UPDATE general_table SET setup_step = :setup_step')
        ->and($commands[0])->toContain('INIT_HOST=10.231.0.11')
        ->and($commands[0])->toContain('INIT_PASSWORD=orbit-e2e-bootstrap-password')
        ->and($commands[0])->toContain('INSECURE=true');
});

it('persists and activates topology peers on wg-easy wg0', function (): void {
    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('gateway');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWgEasyGatewayResult();
        });

    (new E2EWgEasyGateway)->configurePeers($instance, [
        ['name' => 'gateway', 'private_key' => 'gateway-host-private', 'public_key' => 'gateway-host-public', 'pre_shared_key' => 'gateway-psk', 'address' => '10.6.0.2'],
        ['name' => 'operator', 'private_key' => 'operator-private', 'public_key' => 'operator-public', 'pre_shared_key' => 'operator-psk', 'address' => '10.6.0.3'],
        ['name' => 'dev', 'private_key' => 'dev-private', 'public_key' => 'dev-public', 'pre_shared_key' => 'dev-psk', 'address' => '10.6.0.4'],
    ]);

    expect($commands[0])->not->toContain('sqlite3')
        ->and($commands[0])->toContain('sudo -u orbit env')
        ->and($commands[0])->toContain('ORBIT_WG_EASY_PEERS=')
        ->and($commands[0])->toContain('PDO::SQLITE_OPEN_READWRITE')
        ->and($commands[0])->toContain('clients_table')
        ->and($commands[0])->not->toContain("'',")
        ->and($commands[0])->toContain('10.6.0.2/32')
        ->and($commands[0])->toContain('wg set wg0 peer')
        ->and($commands[0])->toContain('gateway-host-public')
        ->and($commands[0])->toContain('preshared-key')
        ->and($commands[0])->toContain('10.6.0.2/32')
        ->and($commands[0])->toContain('operator-public')
        ->and($commands[0])->toContain('10.6.0.3/32')
        ->and($commands[0])->toContain('dev-public')
        ->and($commands[0])->toContain('10.6.0.4/32')
        ->and($commands[0])->not->toContain('ListenPort = 51820');
});

it('updates a fixture wg-easy database through the generated start PDO script', function (): void {
    $this->wgEasyGatewayTemp = sys_get_temp_dir().'/orbit-e2e-wg-easy-gateway-'.bin2hex(random_bytes(4));
    $databasePath = "{$this->wgEasyGatewayTemp}/wg-easy.db";
    createE2EWgEasyGatewayFixtureDatabase($databasePath);

    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('gateway');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWgEasyGatewayResult();
        });

    $host = "vpn.example.test', default_dns = 'mutated";

    (new E2EWgEasyGateway($databasePath))->start($instance, $host);

    runE2EWgEasyGatewayPhpBlock($commands[0], 'ORBIT_WG_EASY_START_PHP', [
        'ORBIT_WG_EASY_ADVERTISED_HOST' => $host,
        'ORBIT_WG_EASY_DB_PATH' => $databasePath,
    ]);

    expect(e2eWgEasyGatewayRow($databasePath, 'interfaces_table', "name = 'wg0'")['ipv4_cidr'])->toBe('10.6.0.0/24')
        ->and(e2eWgEasyGatewayRow($databasePath, 'user_configs_table')['host'])->toBe($host)
        ->and(e2eWgEasyGatewayRow($databasePath, 'user_configs_table')['default_dns'])->toBe('["10.6.0.1"]')
        ->and(e2eWgEasyGatewayRow($databasePath, 'user_configs_table')['default_persistent_keepalive'])->toBe(25)
        ->and(e2eWgEasyGatewayRow($databasePath, 'general_table')['setup_step'])->toBe(0);
});

it('persists fixture peers through the generated PDO script', function (): void {
    $this->wgEasyGatewayTemp = sys_get_temp_dir().'/orbit-e2e-wg-easy-gateway-'.bin2hex(random_bytes(4));
    $databasePath = "{$this->wgEasyGatewayTemp}/wg-easy.db";
    createE2EWgEasyGatewayFixtureDatabase($databasePath);

    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('gateway');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWgEasyGatewayResult();
        });

    $peers = [
        ['name' => "gateway', enabled = 0 --", 'private_key' => 'gateway-host-private', 'public_key' => 'gateway-host-public', 'pre_shared_key' => 'gateway-psk', 'address' => '10.6.0.2'],
        ['name' => 'operator', 'private_key' => 'operator-private', 'public_key' => 'operator-public', 'address' => '10.6.0.3'],
    ];

    seedE2EWgEasyGatewayClient($databasePath, name: 'stale', publicKey: 'gateway-host-public', address: '10.6.0.99');

    (new E2EWgEasyGateway($databasePath))->configurePeers($instance, $peers);

    runE2EWgEasyGatewayPhpBlock($commands[0], 'ORBIT_WG_EASY_PEERS_PHP', [
        'ORBIT_WG_EASY_DB_PATH' => $databasePath,
        'ORBIT_WG_EASY_PEERS' => e2eWgEasyGatewayEncodedPeers($peers),
    ]);

    $gateway = e2eWgEasyGatewayRow($databasePath, 'clients_table', "public_key = 'gateway-host-public'");
    $operator = e2eWgEasyGatewayRow($databasePath, 'clients_table', "public_key = 'operator-public'");

    expect(e2eWgEasyGatewayCount($databasePath, 'clients_table'))->toBe(2)
        ->and($gateway['name'])->toBe("gateway', enabled = 0 --")
        ->and($gateway['ipv4_address'])->toBe('10.6.0.2')
        ->and($gateway['pre_shared_key'])->toBe('gateway-psk')
        ->and($gateway['server_allowed_ips'])->toBe('["10.6.0.2/32"]')
        ->and($gateway['enabled'])->toBe(1)
        ->and($operator['pre_shared_key'])->toBe(base64_encode(hash('sha256', 'orbit-e2e-operator-public', binary: true)))
        ->and($operator['ipv6_address'])->toBe('fdcc:ad94:bacf:61a4::cafe:3');
});

/**
 * @param  array<string, string>  $environment
 */
function runE2EWgEasyGatewayPhpBlock(string $command, string $marker, array $environment): void
{
    $php = e2eWgEasyGatewayPhpBlock($command, $marker);
    $result = Process::env($environment)->input($php)->run(PHP_BINARY);

    if ($result->successful()) {
        return;
    }

    test()->fail(trim($result->output().$result->errorOutput()));
}

function e2eWgEasyGatewayPhpBlock(string $command, string $marker): string
{
    $quotedMarker = preg_quote($marker, '/');
    $matched = preg_match("/<<'{$quotedMarker}'\n(?<php>.*)\n{$quotedMarker}/s", $command, $matches);

    expect($matched)->toBe(1);

    return $matches['php'];
}

/**
 * @param  list<array{name: string, private_key: string, public_key: string, address: string, pre_shared_key?: string}>  $peers
 */
function e2eWgEasyGatewayEncodedPeers(array $peers): string
{
    $json = json_encode($peers, JSON_THROW_ON_ERROR);

    if (! is_string($json)) {
        throw new JsonException('Could not encode wg-easy peers.');
    }

    return base64_encode($json);
}

function createE2EWgEasyGatewayFixtureDatabase(string $path): PDO
{
    File::ensureDirectoryExists(dirname($path));

    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('create table interfaces_table (name text primary key, ipv4_cidr text not null)');
    $pdo->exec('create table user_configs_table (host text not null, default_dns text not null, default_persistent_keepalive integer not null)');
    $pdo->exec('create table general_table (setup_step integer not null)');
    $pdo->exec(<<<'SQL'
        create table clients_table (
            user_id integer not null,
            interface_id text not null,
            name text not null,
            ipv4_address text not null,
            ipv6_address text not null,
            private_key text not null,
            public_key text not null,
            pre_shared_key text not null,
            allowed_ips text not null,
            server_allowed_ips text not null,
            persistent_keepalive integer not null,
            mtu integer not null,
            dns text not null,
            enabled integer not null
        )
        SQL);
    $pdo->exec("insert into interfaces_table (name, ipv4_cidr) values ('wg0', '10.0.0.0/24')");
    $pdo->exec("insert into user_configs_table (host, default_dns, default_persistent_keepalive) values ('old.example.test', '[\"8.8.8.8\"]', 0)");
    $pdo->exec('insert into general_table (setup_step) values (1)');

    return $pdo;
}

function seedE2EWgEasyGatewayClient(string $path, string $name, string $publicKey, string $address): void
{
    $statement = e2eWgEasyGatewayPdo($path)->prepare(<<<'SQL'
        insert into clients_table (
            user_id,
            interface_id,
            name,
            ipv4_address,
            ipv6_address,
            private_key,
            public_key,
            pre_shared_key,
            allowed_ips,
            server_allowed_ips,
            persistent_keepalive,
            mtu,
            dns,
            enabled
        ) values (
            1,
            'wg0',
            :name,
            :ipv4_address,
            'fdcc:ad94:bacf:61a4::cafe:99',
            'old-private',
            :public_key,
            'old-psk',
            '["0.0.0.0/0", "::/0"]',
            '["10.6.0.99/32"]',
            25,
            1420,
            '["10.6.0.1"]',
            1
        )
        SQL);

    $statement->execute([
        'name' => $name,
        'ipv4_address' => $address,
        'public_key' => $publicKey,
    ]);
}

/**
 * @return array<string, mixed>
 */
function e2eWgEasyGatewayRow(string $path, string $table, string $where = '1 = 1'): array
{
    $row = e2eWgEasyGatewayPdo($path)->query("select * from {$table} where {$where} limit 1")->fetch(PDO::FETCH_ASSOC);

    expect($row)->toBeArray();

    return $row;
}

function e2eWgEasyGatewayCount(string $path, string $table): int
{
    return (int) e2eWgEasyGatewayPdo($path)->query("select count(*) from {$table}")->fetchColumn();
}

function e2eWgEasyGatewayPdo(string $path): PDO
{
    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
