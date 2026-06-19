<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;

it('does not catalog database server processes as installable tools', function (string $name): void {
    $catalog = app(ToolCatalog::class);

    expect($catalog->supports($name))->toBeFalse()
        ->and($catalog->installScript($name))->toBeNull();
})->with([
    'mysql',
    'postgres',
    'redis',
]);
