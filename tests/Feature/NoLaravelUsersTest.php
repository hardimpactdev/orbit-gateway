<?php

declare(strict_types=1);

it('does not keep Laravel user scaffolding', function (): void {
    expect(repo_path('apps/gateway/app/Models/User.php'))->not->toBeFile()
        ->and(base_path('database/factories/UserFactory.php'))->not->toBeFile()
        ->and(config_path('auth.php'))->not->toBeFile()
        ->and(base_path('database/migrations/0001_01_01_000000_create_users_table.php'))->not->toBeFile()
        ->and(file_get_contents(base_path('database/migrations/0001_01_01_000000_create_sessions_table.php')))
        ->toContain("foreignId('user_id')->nullable()->index()")
        ->not->toContain('password_reset_tokens');
});
