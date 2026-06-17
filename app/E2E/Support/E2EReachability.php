<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

/**
 * Verifies runtime reachability from inside an E2E topology — DNS resolution
 * and HTTP responses over WireGuard, as a real human or agent would experience.
 *
 * Always runs *from the operator node* via SSH, not from the test host. The
 * operator container reaches the gateway over WG, asks gateway-side DNS to
 * resolve a TLD, and exercises the resulting URL. Verifying from the host
 * bypasses WG and proves nothing about the real path.
 */
final readonly class E2EReachability
{
    public static function assertDnsResolvesOverWg(
        E2EInstance $operator,
        string $operatorUser,
        SshKeyPair $key,
        string $hostname,
        string $expectedIp,
        string $dnsServer = '10.6.0.1',
        int $timeoutSeconds = 15,
    ): void {
        $command = sprintf(
            <<<'SH'
%s
dig +time=%d +short %s @%s
SH,
            self::ensureDigCommand(),
            $timeoutSeconds,
            escapeshellarg($hostname),
            escapeshellarg($dnsServer),
        );

        $result = E2ECommand::ssh($operator, $operatorUser, $key, $command, max(120, $timeoutSeconds + 5));
        $answer = trim($result->output());

        if ($answer === '') {
            throw new RuntimeException(sprintf(
                'DNS for %s did not resolve via %s (empty answer).',
                $hostname,
                $dnsServer,
            ));
        }

        $answers = array_filter(array_map(trim(...), explode("\n", $answer)));

        if (! in_array($expectedIp, $answers, true)) {
            throw new RuntimeException(sprintf(
                'DNS for %s via %s resolved to [%s], expected %s.',
                $hostname,
                $dnsServer,
                implode(', ', $answers),
                $expectedIp,
            ));
        }
    }

    public static function assertHttpReachable(
        E2EInstance $operator,
        string $operatorUser,
        SshKeyPair $key,
        string $url,
        int $expectedStatus = 200,
        int $timeoutSeconds = 15,
    ): void {
        $command = self::curlCommand($url, '-s -o /dev/null -w "%{http_code}"', $timeoutSeconds);

        $result = E2ECommand::ssh($operator, $operatorUser, $key, $command, max(120, $timeoutSeconds + 5));
        $observed = trim($result->output());

        if ($observed !== (string) $expectedStatus) {
            throw new RuntimeException(sprintf(
                'HTTP %s returned status %s, expected %d.',
                $url,
                $observed === '' ? '<empty>' : $observed,
                $expectedStatus,
            ));
        }
    }

    public static function assertHttpResponseContains(
        E2EInstance $operator,
        string $operatorUser,
        SshKeyPair $key,
        string $url,
        string $marker,
        int $timeoutSeconds = 15,
    ): void {
        $command = self::curlCommand($url, '-s', $timeoutSeconds);

        $result = E2ECommand::ssh($operator, $operatorUser, $key, $command, max(120, $timeoutSeconds + 5));
        $body = $result->output();

        if (! str_contains($body, $marker)) {
            throw new RuntimeException(sprintf(
                'HTTP %s body did not contain marker %s. Body: %s',
                $url,
                $marker,
                trim($body),
            ));
        }
    }

    public static function assertHttpNotServing(
        E2EInstance $operator,
        string $operatorUser,
        SshKeyPair $key,
        string $url,
        int $forbiddenStatus = 200,
        int $timeoutSeconds = 15,
    ): void {
        $command = self::curlCommand($url, '-s -o /dev/null -w "%{http_code}"', $timeoutSeconds).' || true';

        $result = E2ECommand::ssh($operator, $operatorUser, $key, $command, max(120, $timeoutSeconds + 5));
        $observed = trim($result->output());

        if ($observed === (string) $forbiddenStatus) {
            throw new RuntimeException(sprintf(
                'HTTP %s still returned forbidden status %d.',
                $url,
                $forbiddenStatus,
            ));
        }
    }

    private static function curlCommand(string $url, string $options, int $timeoutSeconds): string
    {
        [$resolvePrefix, $resolveOption] = self::curlResolveParts($url, $timeoutSeconds);

        return trim(sprintf(
            <<<'SH'
%s
curl -k %s --max-time %d%s %s
SH,
            $resolvePrefix,
            $options,
            $timeoutSeconds,
            $resolveOption,
            escapeshellarg($url),
        ));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function curlResolveParts(string $url, int $timeoutSeconds, string $dnsServer = '10.6.0.1'): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new RuntimeException("HTTP reachability URL must include a host: {$url}");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ['', ''];
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_int($port)) {
            $port = $scheme === 'http' ? 80 : 443;
        }

        $prefix = sprintf(
            <<<'SH'
%s
resolved_ip="$(dig +time=%d +short %s @%s | awk 'NF { print; exit }')"
if [ -z "$resolved_ip" ]; then
    printf 'Could not resolve %%s via %%s\n' %s %s >&2
    exit 6
fi
SH,
            self::ensureDigCommand(),
            $timeoutSeconds,
            escapeshellarg($host),
            escapeshellarg($dnsServer),
            escapeshellarg($host),
            escapeshellarg($dnsServer),
        );

        return [$prefix, ' --resolve '.escapeshellarg("{$host}:{$port}:").'"$resolved_ip"'];
    }

    private static function ensureDigCommand(): string
    {
        return <<<'SH'
command -v dig >/dev/null 2>&1 || { echo 'dig is missing from the prepared Incus artifact. Rebuild the base image and prepared topology.' >&2; exit 1; }
SH;
    }
}
