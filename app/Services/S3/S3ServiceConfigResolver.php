<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use RuntimeException;

/**
 * Resolves an S3ServiceConfig for a given S3-role node.
 *
 * Credential strategy:
 *  - Preserves existing credentials when the seaweedfs tool row already holds a
 *    complete credentials['fields'] array (access_key_id + secret_access_key).
 *  - Generates new credentials via S3CredentialGenerator only when credentials
 *    are missing or incomplete.
 */
final readonly class S3ServiceConfigResolver
{
    public function __construct(
        private S3CredentialGenerator $generator,
    ) {}

    /**
     * Resolve an S3ServiceConfig for an S3-role node.
     *
     * @param  Node  $node  Must have a non-empty wireguard_address and an active s3 role assignment.
     * @param  NodeRoleAssignment  $assignment  The active s3 role assignment carrying role settings.
     * @param  NodeTool|null  $seaweedfsTool  The seaweedfs tool row, if it exists.
     */
    public function resolve(Node $node, NodeRoleAssignment $assignment, ?NodeTool $seaweedfsTool = null): S3ServiceConfig
    {
        $wireguardAddress = $this->requireWireguardAddress($node);

        $settings = S3RoleSettings::fromArray(
            is_array($assignment->settings) ? $assignment->settings : [],
        );

        $credentials = $this->resolveCredentials($seaweedfsTool);

        $publicHosts = $this->readPublicHosts($seaweedfsTool);

        return new S3ServiceConfig(
            nodeName: $node->name,
            wireguardAddress: $wireguardAddress,
            dataPath: $settings->dataPath,
            accessKeyId: $credentials->accessKeyId,
            secretAccessKey: $credentials->secretAccessKey,
            publicHosts: $publicHosts,
        );
    }

    /**
     * Resolve the WireGuard address, throwing when it is missing or empty.
     */
    private function requireWireguardAddress(Node $node): string
    {
        $address = trim((string) $node->wireguard_address);

        if ($address === '') {
            throw new RuntimeException(
                'The s3 role requires a WireGuard address before the S3 service config can be resolved.',
            );
        }

        return $address;
    }

    /**
     * Preserve existing credentials when both fields are present and non-empty;
     * generate fresh credentials otherwise.
     */
    private function resolveCredentials(?NodeTool $seaweedfsTool): S3Credentials
    {
        if ($seaweedfsTool !== null) {
            $stored = $this->extractStoredCredentials($seaweedfsTool);

            if ($stored !== null) {
                return $stored;
            }
        }

        return $this->generator->generate();
    }

    /**
     * Extract credentials from the seaweedfs tool row when they are complete.
     *
     * The NodeTool::credentials column is an encrypted JSON array. The S3
     * credentials live at credentials['fields']['access_key_id'] and
     * credentials['fields']['secret_access_key'].
     *
     * Returns null when the tool row has no credentials or when either field
     * is missing or empty, so that callers can fall back to generation.
     */
    private function extractStoredCredentials(NodeTool $seaweedfsTool): ?S3Credentials
    {
        $raw = $seaweedfsTool->credentials;

        if (! is_array($raw)) {
            return null;
        }

        $fields = $raw['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        $accessKeyId = $fields['access_key_id'] ?? null;
        $secretAccessKey = $fields['secret_access_key'] ?? null;

        if (! is_string($accessKeyId) || $accessKeyId === '') {
            return null;
        }

        if (! is_string($secretAccessKey) || $secretAccessKey === '') {
            return null;
        }

        return new S3Credentials(
            accessKeyId: $accessKeyId,
            secretAccessKey: $secretAccessKey,
        );
    }

    /**
     * Read the public_hosts list from seaweedfs tool config, defaulting to an
     * empty array when the tool row is absent or config is unset.
     *
     * @return list<string>
     */
    private function readPublicHosts(?NodeTool $seaweedfsTool): array
    {
        if ($seaweedfsTool === null) {
            return [];
        }

        $config = $seaweedfsTool->config;

        if (! is_array($config)) {
            return [];
        }

        $hosts = $config['public_hosts'] ?? [];

        if (! is_array($hosts)) {
            return [];
        }

        /** @var list<string> */
        return array_values(array_filter($hosts, is_string(...)));
    }
}
