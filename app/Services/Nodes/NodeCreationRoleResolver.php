<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Enums\Nodes\NodeRoleName;

final class NodeCreationRoleResolver
{
    private const array TEMPLATES = [
        'operator',
        'app-development',
        'app-production',
        'gateway',
        'ingress',
        'database',
        's3',
        'metrics',
        'websocket',
        'agent',
    ];

    private const array EXPLICIT_ROLES = [
        NodeRoleName::AppDevelopment->value,
        NodeRoleName::AppProduction->value,
        NodeRoleName::Database->value,
        NodeRoleName::Agent->value,
        NodeRoleName::Ingress->value,
        NodeRoleName::Metrics->value,
        NodeRoleName::WebSocket->value,
        's3',
    ];

    private const array IMPLEMENTATION_PENDING_ROLES = [
        NodeRoleName::WebSocket->value,
        's3',
    ];

    public function resolve(?string $template, bool $operator, ?string $roles): NodeCreationRoleSelection
    {
        $template = $this->normalizeTemplate($template);
        $requestedRoles = $this->parseRoles($roles);

        if ($template !== null && $requestedRoles !== []) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: '--template and --roles cannot be used together.',
                meta: ['fields' => ['template', 'roles']],
            );
        }

        if ($operator && $requestedRoles !== []) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: '--operator and --roles cannot be used together.',
                meta: ['fields' => ['operator', 'roles']],
            );
        }

        if ($operator && $template !== null && $template !== 'operator') {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: '--operator can only be combined with --template=operator.',
                meta: ['fields' => ['operator', 'template']],
            );
        }

        if ($template !== null) {
            return $this->fromTemplate($template);
        }

        if ($operator) {
            return new NodeCreationRoleSelection(
                gateway: false,
                operator: true,
                clientIdentity: false,
                hosted: [],
                template: null,
                requestedRoleMeta: 'operator',
            );
        }

        if ($requestedRoles === []) {
            return new NodeCreationRoleSelection(
                gateway: false,
                operator: false,
                clientIdentity: true,
                hosted: [],
                template: null,
                requestedRoleMeta: 'client',
            );
        }

        return $this->fromExplicitRoles($requestedRoles);
    }

    /**
     * @return 'agent'|'app-development'|'app-production'|'database'|'gateway'|'ingress'|'metrics'|'operator'|'s3'|'websocket'|null
     */
    private function normalizeTemplate(?string $template): ?string
    {
        if ($template === null) {
            return null;
        }

        $template = trim($template);

        if ($template === '') {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: 'Node template is required when --template is supplied.',
                meta: ['field' => 'template'],
            );
        }

        if (! in_array($template, self::TEMPLATES, true)) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: 'Node template must be one of operator, app-development, app-production, gateway, ingress, database, s3, metrics, websocket, or agent.',
                meta: ['field' => 'template'],
            );
        }

        return $template;
    }

    /**
     * @return list<string>
     */
    private function parseRoles(?string $roles): array
    {
        if ($roles === null) {
            return [];
        }

        $parsed = array_values(array_filter(
            array_map(trim(...), explode(',', $roles)),
            static fn (string $role): bool => $role !== '',
        ));

        if ($parsed === []) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: 'At least one role is required when --roles is supplied.',
                meta: ['field' => 'roles'],
            );
        }

        return array_values(array_unique($parsed));
    }

    /**
     * @param  'agent'|'app-development'|'app-production'|'database'|'gateway'|'ingress'|'metrics'|'operator'|'s3'|'websocket'  $template
     */
    private function fromTemplate(string $template): NodeCreationRoleSelection
    {
        if (in_array($template, self::IMPLEMENTATION_PENDING_ROLES, true)) {
            throw new NodeCreationRoleInputException(
                errorCode: 'template_not_implemented',
                message: "Node template '{$template}' is not implemented yet.",
                meta: ['field' => 'template', 'template' => $template],
            );
        }

        return match ($template) {
            'operator' => new NodeCreationRoleSelection(false, true, false, [], $template, 'operator'),
            'gateway' => new NodeCreationRoleSelection(true, false, false, [], $template, 'gateway'),
            'app-development' => new NodeCreationRoleSelection(false, false, false, [
                NodeRoleName::AppDevelopment->value,
                NodeRoleName::Database->value,
            ], $template, NodeRoleName::AppDevelopment->value),
            'app-production' => new NodeCreationRoleSelection(false, false, false, [
                NodeRoleName::AppProduction->value,
            ], $template, NodeRoleName::AppProduction->value),
            'ingress' => new NodeCreationRoleSelection(false, false, false, [
                NodeRoleName::Ingress->value,
            ], $template, NodeRoleName::Ingress->value),
            'database' => new NodeCreationRoleSelection(false, false, false, [
                NodeRoleName::Database->value,
            ], $template, NodeRoleName::Database->value),
            'metrics' => new NodeCreationRoleSelection(false, false, false, [
                NodeRoleName::Metrics->value,
            ], $template, NodeRoleName::Metrics->value),
            'agent' => new NodeCreationRoleSelection(false, false, false, [
                NodeRoleName::Agent->value,
            ], $template, NodeRoleName::Agent->value),
        };
    }

    /**
     * @param  list<string>  $roles
     */
    private function fromExplicitRoles(array $roles): NodeCreationRoleSelection
    {
        foreach ($roles as $role) {
            if (! in_array($role, self::EXPLICIT_ROLES, true)) {
                throw new NodeCreationRoleInputException(
                    errorCode: 'validation_failed',
                    message: 'Node roles must be one or more of app-dev, app-prod, database, agent, ingress, metrics, websocket, or s3.',
                    meta: ['field' => 'roles'],
                );
            }
        }

        foreach (self::IMPLEMENTATION_PENDING_ROLES as $pendingRole) {
            if (in_array($pendingRole, $roles, true)) {
                throw new NodeCreationRoleInputException(
                    errorCode: 'role_not_implemented',
                    message: "Node role '{$pendingRole}' is not implemented yet.",
                    meta: ['field' => 'roles', 'role' => $pendingRole],
                );
            }
        }

        $this->guardRoleConflicts($roles);

        return new NodeCreationRoleSelection(
            gateway: false,
            operator: false,
            clientIdentity: false,
            hosted: $roles,
            template: null,
            requestedRoleMeta: $roles[0] ?? null,
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function guardRoleConflicts(array $roles): void
    {
        $this->guardPairConflict($roles, NodeRoleName::AppDevelopment->value, NodeRoleName::AppProduction->value);
        $this->guardPairConflict($roles, NodeRoleName::AppProduction->value, NodeRoleName::Database->value);
        $this->guardPairConflict($roles, NodeRoleName::Ingress->value, NodeRoleName::Database->value);

        if (in_array(NodeRoleName::Agent->value, $roles, true) && count($roles) > 1) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: 'The agent role cannot be combined with other hosted roles.',
                meta: [
                    'field' => 'roles',
                    'role' => NodeRoleName::Agent->value,
                ],
            );
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function guardPairConflict(array $roles, string $firstRole, string $secondRole): void
    {
        if (! in_array($firstRole, $roles, true) || ! in_array($secondRole, $roles, true)) {
            return;
        }

        throw new NodeCreationRoleInputException(
            errorCode: 'validation_failed',
            message: "Hosted roles {$firstRole} and {$secondRole} cannot be combined.",
            meta: [
                'field' => 'roles',
                'conflicts' => [$firstRole, $secondRole],
            ],
        );
    }
}
