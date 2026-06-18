<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeCreateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly array $roles,
        public readonly ?string $host,
        public readonly ?string $tld,
        public readonly ?string $user,
        public readonly ?string $hostKeyFingerprint = null,
        public readonly bool $operator = false,
        public readonly ?string $selfGrant = null,
        public readonly ?string $selfGrantPermissions = null,
        public readonly array $grantTo = [],
        public readonly ?string $grantToPreset = null,
        public readonly ?string $grantToPermissions = null,
        public readonly array $grantFrom = [],
        public readonly ?string $grantFromPreset = null,
        public readonly ?string $grantFromPermissions = null,
        public readonly array $agentTools = [],
        public readonly ?string $ingressNode = null,
        public readonly ?string $template = null,
        public readonly ?string $operatorName = null,
        public readonly ?string $redisNode = null,
        public readonly ?string $s3DataPath = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'roles' => $this->roles,
            'host' => $this->host,
            'tld' => $this->tld,
            'user' => $this->user,
        ];

        if ($this->operator) {
            $body['operator'] = true;
        }

        if ($this->template !== null) {
            $body['template'] = $this->template;
        }

        if ($this->hostKeyFingerprint !== null) {
            $body['host_key_fingerprint'] = $this->hostKeyFingerprint;
        }

        if ($this->selfGrant !== null) {
            $body['self_grant'] = $this->selfGrant;
        }

        if ($this->selfGrantPermissions !== null) {
            $body['self_grant_permissions'] = $this->selfGrantPermissions;
        }

        if ($this->grantTo !== []) {
            $body['grant_to'] = $this->grantTo;
        }

        if ($this->grantToPreset !== null) {
            $body['grant_to_preset'] = $this->grantToPreset;
        }

        if ($this->grantToPermissions !== null) {
            $body['grant_to_permissions'] = $this->grantToPermissions;
        }

        if ($this->grantFrom !== []) {
            $body['grant_from'] = $this->grantFrom;
        }

        if ($this->grantFromPreset !== null) {
            $body['grant_from_preset'] = $this->grantFromPreset;
        }

        if ($this->grantFromPermissions !== null) {
            $body['grant_from_permissions'] = $this->grantFromPermissions;
        }

        if ($this->agentTools !== []) {
            $body['agent_tools'] = $this->agentTools;
        }

        if ($this->ingressNode !== null) {
            $body['ingress_node'] = $this->ingressNode;
        }

        if ($this->operatorName !== null) {
            $body['operator_name'] = $this->operatorName;
        }

        if ($this->redisNode !== null) {
            $body['redis_node'] = $this->redisNode;
        }

        if ($this->s3DataPath !== null) {
            $body['s3_data_path'] = $this->s3DataPath;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): NodeCreateResponse
    {
        return new NodeCreateResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
