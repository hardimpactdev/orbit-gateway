<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolRegistryFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public string $code,
        public string $message,
        public array $meta,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function validation(string $field, string $value, string $message, array $meta = []): self
    {
        return new self(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => $field,
                'value' => $value,
                ...$meta,
            ],
        );
    }

    public static function notFound(string $tool, string $node): self
    {
        return new self(
            code: 'tool.not_found',
            message: "Tool '{$tool}' not found on node '{$node}'.",
            meta: [
                'tool' => $tool,
                'node' => $node,
            ],
        );
    }

    public static function unsupportedAction(string $tool, string $action): self
    {
        return new self(
            code: 'tool.unsupported_action',
            message: "Tool '{$tool}' does not support {$action}.",
            meta: [
                'tool' => $tool,
                'action' => $action,
            ],
        );
    }

    public static function nodeRoleRequired(string $tool, string $node, string $requiredRole): self
    {
        return new self(
            code: 'node.role_required',
            message: "Tool '{$tool}' requires node '{$node}' to have active role '{$requiredRole}'.",
            meta: [
                'node' => $node,
                'required_role' => $requiredRole,
                'tool' => $tool,
            ],
        );
    }

    public static function remoteActionFailed(string $tool, string $node, string $action, int $exitCode, string $stderr): self
    {
        return new self(
            code: 'tool.remote_action_failed',
            message: "Tool '{$tool}' {$action} failed on node '{$node}'.",
            meta: [
                'tool' => $tool,
                'node' => $node,
                'action' => $action,
                'exit_code' => $exitCode,
                'stderr' => $stderr,
            ],
        );
    }

    public static function authorization(string $message): self
    {
        return new self(
            code: 'authorization_failed',
            message: $message,
            meta: [],
        );
    }

    public static function nodeTargetRequired(): self
    {
        return new self(
            code: 'node_target_required',
            message: 'A node target is required. Provide --node.',
            meta: ['field' => 'node'],
        );
    }
}
