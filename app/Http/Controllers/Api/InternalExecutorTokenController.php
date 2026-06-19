<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Services\Operations\OperationTokenIntrospector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class InternalExecutorTokenController
{
    public function __invoke(Request $request, OperationTokenIntrospector $introspector): JsonResponse
    {
        $validated = $request->validate([
            'operation_token' => ['required', 'string'],
            'command' => ['required', 'string', 'starts_with:internal:'],
        ]);

        /** @var Node $node */
        $node = $request->user();

        return response()->json([
            'success' => [
                'data' => $introspector->introspect(
                    compactToken: $validated['operation_token'],
                    expectedNode: $node->name,
                    expectedCommand: $validated['command'],
                ),
            ],
        ]);
    }
}
