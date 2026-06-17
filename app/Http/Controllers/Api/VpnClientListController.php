<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Vpn\VpnFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('vpn:read', servingNode: ServingNode::Gateway)]
final class VpnClientListController extends VpnControllerSupport
{
    public function __invoke(Request $request): JsonResponse
    {
        $manager = $this->manager();

        if ($manager instanceof VpnFailure) {
            return $this->fail($manager);
        }

        $clients = $manager->list($this->totp($request));

        return response()->json([
            'success' => [
                'data' => [
                    'clients' => array_map(fn ($client): array => $client->toArray(), $clients),
                ],
                'meta' => ['count' => count($clients)],
            ],
        ]);
    }
}
