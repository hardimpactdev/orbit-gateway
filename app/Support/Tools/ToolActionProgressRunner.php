<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Contracts\ProgressReporter;
use App\Http\Gateway\GatewayApiException;
use App\Services\Tools\ToolRegistryFailure;
use Throwable;

final class ToolActionProgressRunner
{
    /**
     * @return list<array{key: string, label: string, doneLabel: string}>
     */
    public function steps(): array
    {
        return [
            [
                'key' => 'resolve-target',
                'label' => 'Resolve target',
                'doneLabel' => 'Resolved target',
            ],
            [
                'key' => 'read-intent',
                'label' => 'Read gateway tool intent',
                'doneLabel' => 'Read gateway tool intent',
            ],
            [
                'key' => 'run-action',
                'label' => 'Run command action',
                'doneLabel' => 'Ran command action',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure|GatewayApiException
     */
    public function run(
        ProgressReporter $reporter,
        string $title,
        callable $operation,
        string $actionMessage = 'command action completed',
    ): array|ToolRegistryFailure|GatewayApiException {
        $reporter->tree($title, $this->steps());

        $reporter->stepStart('resolve-target');
        $reporter->stepDone('resolve-target', 'target resolved');

        $reporter->stepStart('read-intent');
        $reporter->stepDone('read-intent', 'intent read');

        $reporter->stepStart('run-action');

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $reporter->stepFail('run-action', $exception->getMessage());

            throw $exception;
        }

        if ($result instanceof ToolRegistryFailure) {
            $reporter->stepFail('run-action', $result->message);

            return $result;
        }

        if ($result instanceof GatewayApiException) {
            $reporter->stepFail('run-action', $result->getMessage());

            return $result;
        }

        if (! is_array($result)) {
            $reporter->stepFail('run-action', 'Tool action returned an invalid response.');

            return ToolRegistryFailure::validation(
                'tool',
                '',
                'Tool action returned an invalid response.',
            );
        }

        $reporter->stepDone('run-action', $actionMessage);

        return $result;
    }
}
