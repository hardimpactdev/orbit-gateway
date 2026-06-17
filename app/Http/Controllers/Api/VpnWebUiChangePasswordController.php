<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Vpn\VpnFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('vpn:write', servingNode: ServingNode::Gateway)]
final class VpnWebUiChangePasswordController extends VpnControllerSupport
{
    public function __invoke(Request $request): JsonResponse
    {
        $manager = $this->manager();

        if ($manager instanceof VpnFailure) {
            return $this->fail($manager);
        }

        $password = $request->string('password')->toString();

        if ($password === '') {
            return $this->fail(new VpnFailure('validation_failed', 'VPN web UI password is required.', ['field' => 'password']), 422);
        }

        if (! $request->boolean('force')) {
            return $this->fail(new VpnFailure('validation_failed', 'Use --force to rotate the VPN web UI password.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]), 422);
        }

        $result = $manager->changeWebUiPassword($password, $request->string('totp')->trim()->toString() ?: null);

        if ($result instanceof VpnFailure) {
            return $this->fail($result, 422);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'vpn' => [
                        'password_changed' => $result->passwordChanged,
                        'sessions_invalidated' => $result->sessionsInvalidated,
                    ],
                ],
                'meta' => (object) [],
            ],
        ]);
    }
}
