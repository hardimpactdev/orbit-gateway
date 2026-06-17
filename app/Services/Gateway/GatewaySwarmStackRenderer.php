<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Enums\Gateway\GatewayExposureMode;
use InvalidArgumentException;

final readonly class GatewaySwarmStackRenderer
{
    public const string Network = 'orbit-network';

    public const string GatewayService = 'orbit-gateway';

    public const string SchedulerService = 'orbit-scheduler';

    public function render(
        GatewayImageReference $image,
        GatewayExposureMode $exposureMode,
        string $configRoot = '/home/orbit/.config/orbit',
        string $installRoot = '/home/orbit/orbit',
    ): string {
        $configRoot = $this->normalizeConfigRoot($configRoot);
        $installRoot = $this->normalizeInstallRoot($installRoot);
        $configRootExpression = '${ORBIT_CONFIG_ROOT:-'.$configRoot.'}';
        $installRootExpression = '${ORBIT_INSTALL_ROOT:-'.$installRoot.'}';

        return implode("\n", [
            'version: "3.8"',
            'services:',
            ...$this->gatewayService($image, $exposureMode, $configRoot, $configRootExpression, $installRootExpression),
            ...$this->schedulerService($image, $configRoot, $configRootExpression, $installRootExpression),
            'networks:',
            '  '.self::Network.':',
            '    external: true',
            '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function gatewayService(
        GatewayImageReference $image,
        GatewayExposureMode $exposureMode,
        string $configRoot,
        string $configRootExpression,
        string $installRootExpression,
    ): array {
        $lines = [
            '  '.self::GatewayService.':',
            '    image: '.$this->quoted($image->canonical()),
            '    networks:',
            '      '.self::Network.':',
            '        aliases:',
            '          - '.self::GatewayService,
            '    environment:',
            '      APP_ENV: production',
            '      APP_DEBUG: "false"',
            '      DB_BUSY_TIMEOUT: "5000"',
            '      DB_JOURNAL_MODE: wal',
            '      DB_SYNCHRONOUS: NORMAL',
            '      ORBIT_CONFIG_ROOT: '.$configRoot,
            '      ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli',
            '      ORBIT_GATEWAY_EXPOSURE_MODE: '.$exposureMode->value,
            '      ORBIT_GATEWAY_HEALTH_PORT: "8080"',
            '      ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli',
        ];

        if ($exposureMode->isRouterColocated()) {
            $lines[] = '      ORBIT_TRUST_WIREGUARD_PROXY_HEADER: "1"';
        }

        if ($exposureMode->isGatewayDirect()) {
            array_push(
                $lines,
                '      ORBIT_GATEWAY_TLS_CERT: /etc/orbit/certs/gateway.crt',
                '      ORBIT_GATEWAY_TLS_KEY: /etc/orbit/certs/gateway.key',
                '    ports:',
                '      - target: 443',
                '        published: 443',
                '        protocol: tcp',
                '        mode: ingress',
            );
        }

        array_push(
            $lines,
            '    volumes:',
            '      - '.$configRootExpression.':'.$configRoot,
            '      - '.$installRootExpression.'/bin/orbit-binary:/usr/local/bin/orbit-cli:ro',
        );

        if ($exposureMode->isGatewayDirect()) {
            $lines[] = '      - '.$configRootExpression.'/certs:/etc/orbit/certs:ro';
        }

        array_push(
            $lines,
            '      - /var/run/docker.sock:/var/run/docker.sock',
            '      - /home/orbit/.ssh:/root/.ssh:ro',
            '    healthcheck:',
            '      test: ["CMD", "orbit-gateway-healthcheck"]',
            '      interval: 5s',
            '      timeout: 3s',
            '      retries: 12',
            '      start_period: 10s',
            '    deploy:',
            '      replicas: 1',
            '      labels:',
            '        orbit.managed: "true"',
            '        orbit.service: '.self::GatewayService,
            '      placement:',
            '        constraints:',
            '          - node.labels.orbit.role.gateway == true',
            '      update_config:',
            '        parallelism: 1',
            '        order: start-first',
            '        failure_action: rollback',
            '        monitor: 60s',
            '      rollback_config:',
            '        parallelism: 1',
            '        order: start-first',
            '        monitor: 60s',
        );

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function schedulerService(
        GatewayImageReference $image,
        string $configRoot,
        string $configRootExpression,
        string $installRootExpression,
    ): array {
        return [
            '  '.self::SchedulerService.':',
            '    image: '.$this->quoted($image->canonical()),
            '    command: ["php", "artisan", "orbit-scheduler"]',
            '    networks: ['.self::Network.']',
            '    environment:',
            '      APP_ENV: production',
            '      APP_DEBUG: "false"',
            '      DB_BUSY_TIMEOUT: "5000"',
            '      DB_JOURNAL_MODE: wal',
            '      DB_SYNCHRONOUS: NORMAL',
            '      ORBIT_CONFIG_ROOT: '.$configRoot,
            '      ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli',
            '      ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli',
            '    volumes:',
            '      - '.$configRootExpression.':'.$configRoot,
            '      - '.$installRootExpression.'/bin/orbit-binary:/usr/local/bin/orbit-cli:ro',
            '      - /var/run/docker.sock:/var/run/docker.sock',
            '      - /home/orbit/.ssh:/root/.ssh:ro',
            '    healthcheck:',
            '      disable: true',
            '    deploy:',
            '      replicas: 1',
            '      labels:',
            '        orbit.managed: "true"',
            '        orbit.service: '.self::SchedulerService,
            '      placement:',
            '        constraints:',
            '          - node.labels.orbit.role.gateway == true',
            '      update_config:',
            '        parallelism: 1',
            '        order: stop-first',
            '        failure_action: rollback',
        ];
    }

    private function normalizeConfigRoot(string $configRoot): string
    {
        $configRoot = trim($configRoot);

        if ($configRoot === '') {
            throw new InvalidArgumentException('Gateway Swarm stack config root cannot be empty.');
        }

        if ($configRoot === '/') {
            return $configRoot;
        }

        return rtrim($configRoot, '/');
    }

    private function normalizeInstallRoot(string $installRoot): string
    {
        $installRoot = trim($installRoot);

        if ($installRoot === '') {
            throw new InvalidArgumentException('Gateway Swarm stack install root cannot be empty.');
        }

        if ($installRoot === '/') {
            return $installRoot;
        }

        return rtrim($installRoot, '/');
    }

    private function quoted(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
