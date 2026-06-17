<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;

/**
 * @param  list<string>  $statements
 */
function e2eCreateSqliteDatabaseFixture(E2ETopologyHarness $topology, string $role, string $targetPath, array $statements): void
{
    $localPath = tempnam(sys_get_temp_dir(), 'orbit-e2e-sqlite-');

    if (! is_string($localPath)) {
        throw new RuntimeException('Could not create a temporary SQLite fixture file.');
    }

    try {
        $pdo = new PDO("sqlite:{$localPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        $pdo = null;

        if (! chmod($localPath, 0644)) {
            throw new RuntimeException("Could not make SQLite fixture readable: {$localPath}");
        }

        $topology->instance($role)->copyFileToInstance($localPath, $targetPath);
    } finally {
        @unlink($localPath);
    }
}
