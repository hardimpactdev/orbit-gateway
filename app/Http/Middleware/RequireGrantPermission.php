<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Services\Authorization\ServingNodeResolver;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireGrantPermission
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private ServingNodeResolver $servingNodeResolver,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $attribute = $this->attributeForRequest($request);

        if (! $attribute instanceof RequiresPermission) {
            return $next($request);
        }

        $consumer = $request->user();

        if (! $consumer instanceof Node) {
            return $this->forbidden('Peer identity unknown.');
        }

        $serving = $this->servingNodeResolver->resolve($request, $attribute->servingNode);

        if (! $serving instanceof Node) {
            if ($attribute->servingNode !== ServingNode::Gateway) {
                return $next($request);
            }

            return $this->forbidden('Serving node could not be resolved for this route.', [
                'reason' => 'serving_node_unresolved',
                'missing_permission' => $attribute->permission,
            ]);
        }

        $result = $this->authorizer->authorize($consumer, $serving, $attribute->permission);

        if ($result->allowed) {
            return $next($request);
        }

        return $this->forbidden(
            "This node is not authorized for '{$attribute->permission}' on '{$serving->name}'.",
            $this->metadata($serving, $result),
        );
    }

    private function attributeForRequest(Request $request): ?RequiresPermission
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        $controller = $route->getController();

        if (is_object($controller)) {
            return $this->attributeForController(
                controllerClass: $controller::class,
                method: $route->getActionMethod(),
            );
        }

        $uses = $route->getAction('uses');

        if (is_array($uses) && is_string($uses[0] ?? null) && is_string($uses[1] ?? null)) {
            return $this->attributeForController($uses[0], $uses[1]);
        }

        if (is_string($uses)) {
            return $this->attributeForActionString($uses);
        }

        return $this->attributeForActionString($route->getActionName());
    }

    private function attributeForActionString(string $action): ?RequiresPermission
    {
        if (str_contains($action, '@')) {
            [$controllerClass, $method] = explode('@', $action, 2);

            return $this->attributeForController($controllerClass, $method);
        }

        if (class_exists($action)) {
            return $this->attributeForController($action, '__invoke');
        }

        return null;
    }

    private function attributeForController(string $controllerClass, string $method): ?RequiresPermission
    {
        if (! class_exists($controllerClass)) {
            return null;
        }

        if (method_exists($controllerClass, $method)) {
            $methodAttribute = $this->firstAttribute(new ReflectionMethod($controllerClass, $method));

            if ($methodAttribute instanceof RequiresPermission) {
                return $methodAttribute;
            }
        }

        return $this->firstAttribute(new ReflectionClass($controllerClass));
    }

    private function firstAttribute(ReflectionClass|ReflectionMethod $reflection): ?RequiresPermission
    {
        $attributes = $reflection->getAttributes(RequiresPermission::class);

        if ($attributes === []) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        return $attribute instanceof RequiresPermission ? $attribute : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function forbidden(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => $meta,
            ],
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Node $serving, AuthorizationResult $result): array
    {
        return [
            'reason' => $result->reason,
            'missing_permission' => $result->missingPermission,
            'serving_node' => $serving->name,
        ];
    }
}
