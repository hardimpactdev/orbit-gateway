<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Vpn\VpnFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('vpn:write', servingNode: ServingNode::Gateway)]
final class VpnClientCreateController extends VpnControllerSupport
{
    public function __invoke(Request $request): JsonResponse
    {
        $name = $request->string('name')->trim()->toString();
        $includeConfig = $request->boolean('config');
        $manager = $this->manager();

        if ($manager instanceof VpnFailure) {
            return $this->fail($manager);
        }

        $client = $manager->create($name, $includeConfig, $request->string('totp')->trim()->toString() ?: null);

        if ($client instanceof VpnFailure) {
            return $this->fail($client, 422);
        }

        return response()->json([
            'success' => [
                'data' => ['client' => $client->toArray(includeConfig: $includeConfig)],
                'meta' => ['config_included' => $includeConfig],
            ],
        ]);
    }
}
