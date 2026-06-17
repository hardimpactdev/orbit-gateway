<?php

declare(strict_types=1);

/**
 * @param  list<string>  $additionalKeys
 * @param  array<string, string>  $values
 */
function withE2EEnvironment(array $additionalKeys, array $values, Closure $callback): void
{
    $keys = array_values(array_unique(array_merge([
        'ORBIT_E2E_PROVIDER',
        'ORBIT_E2E_PROVIDERS',
        'ORBIT_E2E_TOPOLOGY_PROVIDER',
        'ORBIT_E2E_TOPOLOGY_PROVIDERS',
        'ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE',
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE',
        'ORBIT_E2E_TOPOLOGY_CACHE_LIMIT',
        'ORBIT_E2E_INSTANCE_PREFIX',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS',
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS',
        'ORBIT_E2E_PARALLEL_PROCESSES',
        'ORBIT_E2E_INCUS_HOSTS',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS',
        'ORBIT_E2E_INCUS_IMAGE_BUILD_HOST',
        'ORBIT_E2E_INCUS_HOST_SLOTS',
        'ORBIT_E2E_INCUS_WARM_SNAPSHOTS',
        'ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS',
        'ORBIT_E2E_LEASE_DIRECTORY',
        'ORBIT_E2E_SLOT_WAIT_SECONDS',
        'ORBIT_E2E_SLOT_STALE_SECONDS',
        'ORBIT_E2E_EXCLUSIVE_HOSTS',
    ], $additionalKeys, array_keys($values))));

    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
    }

    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
    }

    try {
        $callback();
    } finally {
        foreach ($previous as $key => $value) {
            if (is_string($value)) {
                putenv("{$key}={$value}");

                continue;
            }

            putenv($key);
        }
    }
}

/**
 * @param  array<string, string>  $values
 */
function withE2EConfigEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([
        'ORBIT_E2E_CPUS',
        'ORBIT_E2E_MEMORY',
        'ORBIT_E2E_TOPOLOGY_CPUS',
        'ORBIT_E2E_TOPOLOGY_MEMORY',
    ], $values, $callback);
}

/**
 * @param  array<string, string>  $values
 */
function withE2EProviderEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([], $values, $callback);
}

/**
 * @param  array<string, string>  $values
 */
function withE2ETopologyEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
        'ORBIT_E2E_HOST',
    ], $values, $callback);
}
