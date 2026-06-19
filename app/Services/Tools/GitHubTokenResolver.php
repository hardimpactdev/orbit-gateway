<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class GitHubTokenResolver
{
    public function token(): ?string
    {
        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $key) {
            $value = getenv($key);

            if (! is_string($value)) {
                continue;
            }

            $token = trim($value);

            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }
}
