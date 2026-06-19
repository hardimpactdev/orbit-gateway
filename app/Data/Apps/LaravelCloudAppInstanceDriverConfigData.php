<?php

declare(strict_types=1);

namespace App\Data\Apps;

final class LaravelCloudAppInstanceDriverConfigData extends AppInstanceDriverConfigData
{
    public function __construct(
        public ?string $organization_id = null,
        public ?string $organization_name = null,
        public ?string $application_id = null,
        public ?string $application_name = null,
        public ?string $environment_id = null,
        public ?string $environment_name = null,
        public bool $environment_reused = false,
        public bool $environment_created = false,
        public ?string $application = null,
        public ?string $environment = null,
        public ?string $domain = null,
    ) {}
}
