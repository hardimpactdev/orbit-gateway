<?php

declare(strict_types=1);

use App\Services\Vpn\WgEasyAddressReservationProbe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('reserves addresses from wg-easy database files and runtime peers', function (): void {
    $root = sys_get_temp_dir().'/orbit-wg-easy-reservations-'.bin2hex(random_bytes(4));
    $statePath = "{$root}/wg-easy";

    File::ensureDirectoryExists($statePath);

    config()->set('orbit.paths.config_root', $root);
    config()->set('services.wg_easy.database_path', "{$statePath}/wg-easy.db");

    $database = new PDO("sqlite:{$statePath}/wg-easy.db");
    $database->exec('create table clients_table (ipv4_address text not null, server_allowed_ips text not null)');
    $database->exec("insert into clients_table (ipv4_address, server_allowed_ips) values ('10.6.0.4', '[\"10.6.0.4/32\"]')");

    File::put("{$statePath}/wg0.conf", implode("\n", [
        '[Peer]',
        'PublicKey = phone-public-key',
        'AllowedIPs = 10.6.0.5/32',
    ]));

    File::put("{$statePath}/wg0.json", json_encode([
        'clients' => [
            [
                'name' => 'tablet',
                'address' => '10.6.0.6/32',
            ],
            [
                'name' => 'laptop',
                'server_allowed_ips' => ['10.6.0.7/32'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'com.docker.swarm.service.name=orbit_orbit-vpn')) {
            return Process::result(output: "vpn-container-id\n");
        }

        if ($command === "docker exec 'vpn-container-id' wg show wg0 allowed-ips") {
            return Process::result(output: "runtime-public-key\t10.6.0.8/32 10.6.0.9/32\n");
        }

        return Process::result(exitCode: 1);
    });
    Process::preventStrayProcesses();

    try {
        expect((new WgEasyAddressReservationProbe)->addresses())->toBe([
            '10.6.0.4',
            '10.6.0.5',
            '10.6.0.6',
            '10.6.0.7',
            '10.6.0.8',
            '10.6.0.9',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});
