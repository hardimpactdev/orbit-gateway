<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

it('routes database connection endpoints to invokable controllers without mutable controller state', function (): void {
    $routes = [
        ['GET', 'api/database-connections'],
        ['POST', 'api/database-connections'],
        ['POST', 'api/database-connections/query'],
        ['GET', 'api/database-connections/tables'],
        ['GET', 'api/database-connections/schema'],
        ['GET', 'api/database-connections/describe'],
        ['GET', 'api/database-connections/{connection}'],
        ['PATCH', 'api/database-connections/{connection}'],
        ['DELETE', 'api/database-connections/{connection}'],
        ['POST', 'api/database-connections/{connection}/targets'],
        ['DELETE', 'api/database-connections/{connection}/targets'],
    ];

    foreach ($routes as [$method, $uri]) {
        $route = databaseConnectionRoute($method, $uri);

        expect($route)->not->toBeNull();

        $action = $route->getAction('controller');

        expect($action)->toBeString()
            ->and($action)->not->toContain('@');

        $controller = new ReflectionClass($action);

        expect($controller->isFinal())->toBeTrue()
            ->and($controller->hasMethod('__invoke'))->toBeTrue()
            ->and($controller->getMethod('__invoke')->isPublic())->toBeTrue()
            ->and(databaseConnectionMutableProperties($controller))->toBe([]);
    }
});

function databaseConnectionRoute(string $method, string $uri): ?RoutingRoute
{
    foreach (Route::getRoutes() as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            return $route;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function databaseConnectionMutableProperties(ReflectionClass $controller): array
{
    $mutable = [];
    $current = $controller;

    do {
        foreach ($current->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $current->getName()) {
                continue;
            }

            if ($property->isStatic() || $property->isReadOnly()) {
                continue;
            }

            $mutable[] = "{$property->getDeclaringClass()->getShortName()}::{$property->getName()}";
        }

        $current = $current->getParentClass();
    } while ($current instanceof ReflectionClass);

    return $mutable;
}
