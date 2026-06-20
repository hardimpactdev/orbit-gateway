<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends BaseApplication
{
    #[\Override]
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        if ($input->hasParameterOption(['--version', '-V'], true) === true) {
            $name = $this->getCommandName($input);

            if (! is_string($name) || $name === '') {
                $output->writeln($this->getLongVersion());

                return 0;
            }
        }

        try {
            $input->bind($this->getDefinition());
        } catch (ExceptionInterface) {
            // Full binding and validation happen later when the command is known.
        }

        $name = $this->getCommandName($input);

        if ($input->hasParameterOption(['--help', '-h'], true) === true) {
            if (! $name) {
                $name = 'help';
                $input = new ArrayInput(['command_name' => 'list']);
            } else {
                $ref = new \ReflectionProperty(\Symfony\Component\Console\Application::class, 'wantHelps');
                $ref->setValue($this, true);
            }
        }

        if (! $name) {
            $name = 'list';
            $definition = $this->getDefinition();
            $definition->setArguments(array_merge(
                $definition->getArguments(),
                [
                    'command' => new InputArgument('command', InputArgument::OPTIONAL, $definition->getArgument('command')->getDescription(), $name),
                ],
            ));
        }

        try {
            $command = $this->find($name);
        } catch (\Throwable) {
            return parent::doRun($input, $output);
        }

        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        return $this->doRunCommand($command, $input, $output);
    }
}
