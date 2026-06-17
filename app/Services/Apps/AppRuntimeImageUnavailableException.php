<?php

declare(strict_types=1);

namespace App\Services\Apps;

use RuntimeException;
use Throwable;

/**
 * Thrown when the FrankenPHP runtime image selected for an app is not
 * available on the owning node (image absent from the local Docker cache).
 *
 * Distinct from {@see AppRuntimeContainerApplyException}: this signals that
 * the configured PHP version is supported by Orbit but the matching image is
 * not yet on the node, which maps to the documented warning code
 * `app.php_version_unavailable` rather than runtime-artifact drift
 * (`app.runtime_container_missing` / `app.runtime_container_mismatch`).
 */
final class AppRuntimeImageUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $image,
        public readonly string $phpVersion,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
