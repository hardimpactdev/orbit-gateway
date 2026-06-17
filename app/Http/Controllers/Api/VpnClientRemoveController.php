<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Vpn\VpnFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('vpn:write', servingNode: ServingNode::Gateway)]
final class VpnClientRemoveController extends VpnControllerSupport
{
    public function __invoke(Request $request, string $name): JsonResponse
    {
        $manager = $this->manager();

        if ($manager instanceof VpnFailure) {
            return $this->fail($manager);
        }

        if (! $request->boolean('force')) {
            return $this->fail(new VpnFailure('validation_failed', 'Use --force to remove this VPN client.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]), 422);
        }

        $result = $manager->remove($name, $request->string('totp')->trim()->toString() ?: null);

        if ($result instanceof VpnFailure) {
            return $this->fail($result, 422);
        }

        return response()->json([
            'success' => [
                'data' => ['client' => $result],
                'meta' => (object) [],
            ],
        ]);
    }
}
