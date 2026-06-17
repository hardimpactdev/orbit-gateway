<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\Concerns\RespondsWithVpnClientMutation;
use App\Services\Vpn\VpnFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('vpn:write', servingNode: ServingNode::Gateway)]
final class VpnClientEnableController extends VpnControllerSupport
{
    use RespondsWithVpnClientMutation;

    public function __invoke(Request $request, string $name): JsonResponse
    {
        $manager = $this->manager();

        if ($manager instanceof VpnFailure) {
            return $this->fail($manager);
        }

        return $this->respondWithVpnClientMutation($manager->enable($name, $request->string('totp')->trim()->toString() ?: null));
    }
}
