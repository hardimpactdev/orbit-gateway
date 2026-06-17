<?php

declare(strict_types=1);

namespace App\Data\Operations;

use App\Services\Gateway\GatewayImageReference;
use RuntimeException;

final readonly class OperationUpdatePlanSnapshot
{
    /**
     * @param  array<string, mixed>  $manifestSnapshot
     * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
     * @param  array<string, string>  $roleImages
     */
    public function __construct(
        public string $targetVersion,
        public string $gatewayImage,
        public string $manifestSource,
        public string $manifestVersion,
        public array $manifestSnapshot,
        public array $cliArtifacts,
        public array $roleImages,
    ) {
        $this->assertNonEmptyString($this->targetVersion, 'target version');
        $this->assertDigestPinnedGatewayImage($this->gatewayImage);
        $this->assertNonEmptyString($this->manifestSource, 'manifest source');
        $this->assertNonEmptyString($this->manifestVersion, 'manifest version');

        if ($this->manifestSnapshot === []) {
            throw new RuntimeException('Update plan manifest snapshot cannot be empty.');
        }

        $this->assertCliArtifacts($this->cliArtifacts);
        $this->assertRoleImages($this->roleImages);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            targetVersion: self::stringValue($data, 'target_version'),
            gatewayImage: self::stringValue($data, 'gateway_image'),
            manifestSource: self::stringValue($data, 'manifest_source'),
            manifestVersion: self::stringValue($data, 'manifest_version'),
            manifestSnapshot: self::arrayValue($data, 'manifest_snapshot'),
            cliArtifacts: self::arrayValue($data, 'cli_artifacts'),
            roleImages: self::arrayValue($data, 'role_images'),
        );
    }

    /**
     * @return array{
     *     target_version: string,
     *     gateway_image: string,
     *     manifest_source: string,
     *     manifest_version: string,
     *     manifest_snapshot: array<string, mixed>,
     *     cli_artifacts: array<string, array{url: string, sha256: string}>,
     *     role_images: array<string, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'target_version' => $this->targetVersion,
            'gateway_image' => $this->gatewayImage,
            'manifest_source' => $this->manifestSource,
            'manifest_version' => $this->manifestVersion,
            'manifest_snapshot' => $this->manifestSnapshot,
            'cli_artifacts' => $this->cliArtifacts,
            'role_images' => $this->roleImages,
        ];
    }

    private function assertNonEmptyString(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new RuntimeException("Update plan {$label} cannot be empty.");
        }
    }

    private function assertDigestPinnedGatewayImage(string $image): void
    {
        $reference = GatewayImageReference::fromString($image);

        if (! $reference->isDigestPinned()) {
            throw new RuntimeException('Update plan gateway image must be digest-pinned.');
        }
    }

    /**
     * @param  array<string, mixed>  $artifacts
     */
    private function assertCliArtifacts(array $artifacts): void
    {
        if ($artifacts === []) {
            throw new RuntimeException('Update plan CLI artifacts cannot be empty.');
        }

        foreach ($artifacts as $platform => $artifact) {
            if (! is_string($platform) || trim($platform) === '' || ! is_array($artifact)) {
                throw new RuntimeException('Update plan CLI artifacts must be keyed by platform.');
            }

            $url = $artifact['url'] ?? null;
            $sha256 = $artifact['sha256'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                throw new RuntimeException("Update plan CLI artifact [{$platform}] must include a URL.");
            }

            if (! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new RuntimeException("Update plan CLI artifact [{$platform}] must include a sha256 hash.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $images
     */
    private function assertRoleImages(array $images): void
    {
        if ($images === []) {
            throw new RuntimeException('Update plan role images cannot be empty.');
        }

        foreach ($images as $role => $image) {
            if (! is_string($role) || trim($role) === '' || ! is_string($image) || trim($image) === '') {
                throw new RuntimeException('Update plan role images must be keyed by role with non-empty image references.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Update plan field [{$key}] must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function arrayValue(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException("Update plan field [{$key}] must be an array.");
        }

        return $value;
    }
}
