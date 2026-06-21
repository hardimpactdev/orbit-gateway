<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\UpdateRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:update-runner
    {--operation-run-id= : Operation run UUID to execute}')]
#[Description('Run a durable gateway update operation from its persisted update plan')]
class UpdateRunnerCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(UpdateRunner $runner): int
    {
        $operationRunId = $this->operationRunId();

        if ($operationRunId === null) {
            $this->error('The --operation-run-id option is required.');

            return self::FAILURE;
        }

        try {
            $runner->run($operationRunId);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Update runner started for operation run {$operationRunId}.");

        return self::SUCCESS;
    }

    private function operationRunId(): ?string
    {
        $value = $this->option('operation-run-id');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
