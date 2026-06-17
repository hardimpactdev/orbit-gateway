<?php

declare(strict_types=1);

namespace App\Services\Cloudflare;

final readonly class CloudflareClientFactory
{
    public function make(): CloudflareClient
    {
        $token = config('orbit.cloudflare.api_token');
        $email = config('orbit.cloudflare.api_email');

        return new CloudflareClient(
            apiToken: is_string($token) ? $token : null,
            apiEmail: is_string($email) ? $email : null,
        );
    }
}
