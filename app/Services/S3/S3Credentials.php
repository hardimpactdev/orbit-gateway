<?php

declare(strict_types=1);

namespace App\Services\S3;

final readonly class S3Credentials
{
    public function __construct(
        public string $accessKeyId,
        public string $secretAccessKey,
    ) {}

    /**
     * @return array{access_key_id: string, secret_access_key: string}
     */
    public function toFields(): array
    {
        return [
            'access_key_id' => $this->accessKeyId,
            'secret_access_key' => $this->secretAccessKey,
        ];
    }
}
