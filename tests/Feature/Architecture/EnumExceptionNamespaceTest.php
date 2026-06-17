<?php

declare(strict_types=1);

it('keeps gateway runtime enums under App\\Enums and exceptions under App\\Exceptions', function (): void {
    $expectedEnums = [
        'App\\Enums\\Apps\\AppRuntimeArtifactRemovalOutcome',
        'App\\Enums\\Apps\\AppRuntimeContainerApplyOutcome',
        'App\\Enums\\Apps\\NodeRuntimeConfigsProbeStatus',
        'App\\Enums\\Apps\\NodeRuntimeContainersProbeStatus',
        'App\\Enums\\Processes\\ProcessDockerContainerApplyOutcome',
        'App\\Enums\\Trust\\TrustStoreInstallReason',
        'App\\Enums\\Workspaces\\WorkspaceRuntimeArtifactRemovalOutcome',
        'App\\Enums\\Workspaces\\WorkspaceRuntimeContainerApplyOutcome',
    ];

    $expectedExceptions = [
        'App\\Exceptions\\ProcessDockerContainerApplyException',
        'App\\Exceptions\\UpdateLeaseConflict',
    ];

    foreach ($expectedEnums as $class) {
        expect(class_exists($class))->toBeTrue("Expected {$class} to exist.");
    }

    foreach ($expectedExceptions as $class) {
        expect(class_exists($class))->toBeTrue("Expected {$class} to exist.");

        if (class_exists($class)) {
            expect((new ReflectionClass($class))->isFinal())->toBeTrue("Expected {$class} to be final.");
        }
    }

    foreach (gatewayLegacyServiceNamespaceClasses() as $class) {
        expect(class_exists($class))->toBeFalse("Legacy service-namespace class {$class} should not exist.");
    }
});

/**
 * @return list<string>
 */
function gatewayLegacyServiceNamespaceClasses(): array
{
    return [
        'App\\Services\\Apps\\AppRuntimeArtifactRemovalOutcome',
        'App\\Services\\Apps\\AppRuntimeContainerApplyOutcome',
        'App\\Services\\Apps\\NodeRuntimeConfigsProbeStatus',
        'App\\Services\\Apps\\NodeRuntimeContainersProbeStatus',
        'App\\Services\\Operations\\UpdateLeaseConflict',
        'App\\Services\\Processes\\ProcessDockerContainerApplyException',
        'App\\Services\\Processes\\ProcessDockerContainerApplyOutcome',
        'App\\Services\\Trust\\TrustStoreInstallReason',
        'App\\Services\\Workspaces\\WorkspaceRuntimeArtifactRemovalOutcome',
        'App\\Services\\Workspaces\\WorkspaceRuntimeContainerApplyOutcome',
    ];
}
