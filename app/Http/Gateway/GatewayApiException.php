<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use RuntimeException;
use Throwable;

final class GatewayApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errorMeta
     */
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
        private readonly array $errorMeta = [],
        ?Throwable $previous = null,
        private readonly array $errorData = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorMeta(): array
    {
        return $this->errorMeta;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorData(): array
    {
        return $this->errorData;
    }
}
