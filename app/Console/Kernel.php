<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as BaseKernel;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Kernel extends BaseKernel
{
    private const array GATEWAY_VISIBLE_COMMAND_NAMES = [
        'db',
        'docs',
        'migrate',
        'orbit-scheduler',
        'schedule:clear-cache',
        'schedule:finish',
        'schedule:interrupt',
        'schedule:pause',
        'schedule:resume',
        'schedule:test',
        'schedule:work',
        'tinker',
    ];

    private const array GATEWAY_VISIBLE_COMMAND_PREFIXES = [
        'cache:',
        'db:',
        'librarian:',
        'make:',
        'migrate:',
        'orbit:internal:',
        'queue:',
    ];

    #[\Override]
    public function bootstrap()
    {
        $this->addCommandPaths([
            $this->app->path('Console/Commands'),
        ]);

        parent::bootstrap();
    }

    #[\Override]
    protected function shouldDiscoverCommands()
    {
        return true;
    }

    #[\Override]
    protected function getArtisan()
    {
        if (is_null($this->artisan)) {
            $this->artisan = new Application($this->app, $this->events, $this->app->version())
                ->resolveCommands($this->commands)
                ->setContainerCommandLoader();

            if ($this->symfonyDispatcher instanceof EventDispatcher) {
                $this->artisan->setDispatcher($this->symfonyDispatcher);
                $this->artisan->setSignalsToDispatchEvent();
            }
        }

        $this->artisan->setName((string) config('app.name', 'Orbit'));
        $this->artisan->setVersion((string) config('app.version', '0.0.0'));

        foreach ($this->artisan->all() as $command) {
            $this->applyGatewayCommandVisibility($command);
        }

        return $this->artisan;
    }

    private function applyGatewayCommandVisibility(SymfonyCommand $command): void
    {
        $name = $command->getName();

        if (is_string($name) && $this->shouldExposeGatewayCommand($name)) {
            $command->setHidden(false);

            return;
        }

        $command->setHidden(true);
    }

    private function shouldExposeGatewayCommand(string $name): bool
    {
        if (in_array($name, self::GATEWAY_VISIBLE_COMMAND_NAMES, true)) {
            return true;
        }

        return array_any(
            self::GATEWAY_VISIBLE_COMMAND_PREFIXES,
            fn ($prefix): bool => str_starts_with($name, (string) $prefix),
        );
    }
}
