<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\Operations\ReleaseManifest;
use App\Models\OperationRun;
use App\Services\Gateway\GatewayImageReference;
use Illuminate\Http\Request;
use RuntimeException;

class UpdatePlanBuilder
{
    public function __construct(
        private readonly ReleaseManifestResolver $releaseManifestResolver,
    ) {}

    public function fromStoredStartRequest(OperationRun $operationRun): OperationUpdatePlanSnapshot
    {
        $result = $operationRun->result;
        $startRequest = is_array($result) ? ($result['update_start_request'] ?? null) : null;

        if (! is_array($startRequest)) {
            throw new RuntimeException('Deferred update start request payload was not found on the operation run.');
        }

        return $this->fromRequest(
            $operationRun,
            Request::create('/api/update/all/start', 'POST', $startRequest),
        );
    }

    public function fromRequest(OperationRun $operationRun, Request $request): OperationUpdatePlanSnapshot
    {
        $manifest = $this->releaseManifest($request);

        return new OperationUpdatePlanSnapshot(
            targetVersion: $this->stringInput($request, 'target_version')
                ?? $manifest->version,
            gatewayImage: $this->gatewayImage($request, $manifest),
            manifestSource: $this->stringInput($request, 'manifest_source')
                ?? $manifest->source,
            manifestVersion: $this->stringInput($request, 'manifest_version')
                ?? $manifest->version,
            manifestSnapshot: $manifest->snapshot(),
            cliArtifacts: $this->arrayInput($request, 'cli_artifacts')
                ?? $manifest->cliArtifacts,
            roleImages: $this->arrayInput($request, 'role_images')
                ?? $manifest->roleImages,
        );
    }

    private function releaseManifest(Request $request): ReleaseManifest
    {
        $requestManifest = $this->arrayInput($request, 'manifest');

        if ($requestManifest !== null) {
            return ReleaseManifest::fromArray($requestManifest);
        }

        return $this->releaseManifestResolver->resolve();
    }

    private function gatewayImage(Request $request, ReleaseManifest $manifest): string
    {
        $requestImage = $this->stringInput($request, 'gateway_image');

        if ($requestImage !== null) {
            if (! (bool) config('orbit.updates.allow_request_image_override', false)) {
                throw new RuntimeException('Update plan gateway image override is disabled.');
            }

            return $this->digestPinnedGatewayImage($requestImage);
        }

        return $this->digestPinnedGatewayImage($manifest->gatewayImage);
    }

    private function digestPinnedGatewayImage(string $image): string
    {
        $reference = GatewayImageReference::fromString($image);

        if (! $reference->isDigestPinned()) {
            throw new RuntimeException('Update plan gateway image must be digest-pinned.');
        }

        return $reference->canonical();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayInput(Request $request, string $key): ?array
    {
        return $this->arrayFrom($request->input($key));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayFrom(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        return $this->stringFrom($request->input($key));
    }

    private function stringFrom(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
