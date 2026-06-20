<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Models\LocalGatewaySettings;
use Psr\Http\Message\StreamInterface;
use Saloon\Http\Response;
use Throwable;

final readonly class GatewayStreamTransport
{
    public function __construct(
        private GatewayConnector $connector,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function events(GatewayStreamRequest $request, callable $onEvent, string $unavailableMessage, int $defaultExitCode = 1): int|GatewayApiException
    {
        $response = $this->send($request, $unavailableMessage);

        if ($response instanceof GatewayApiException) {
            return $response;
        }

        return $this->consumeEvents($response->stream(), $onEvent, $defaultExitCode);
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    public function text(GatewayStreamRequest $request, callable $onOutput, string $unavailableMessage): int|GatewayApiException
    {
        $response = $this->send($request, $unavailableMessage);

        if ($response instanceof GatewayApiException) {
            return $response;
        }

        $body = $response->stream();

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                usleep(50_000);

                continue;
            }

            $onOutput($chunk);
        }

        return 0;
    }

    private function send(GatewayStreamRequest $request, string $unavailableMessage): Response|GatewayApiException
    {
        $gatewayUrl = LocalGatewaySettings::current()->gateway_url;

        if (! is_string($gatewayUrl) || trim($gatewayUrl) === '') {
            return $this->unavailable($unavailableMessage);
        }

        try {
            return $this->connector->send($request);
        } catch (GatewayApiException $exception) {
            return $exception;
        } catch (Throwable $e) {
            return $this->unavailable($unavailableMessage, trim(get_debug_type($e).' '.$e->getMessage()));
        }
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    private function consumeEvents(StreamInterface $body, callable $onEvent, int $defaultExitCode): int
    {
        $buffer = '';
        $exitCode = $defaultExitCode;

        while (! $body->eof()) {
            $chunk = $body->read(1);

            if ($chunk === '') {
                usleep(50_000);

                continue;
            }

            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($position = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 2);
                $event = $this->parseFrame($frame);

                if ($event === null) {
                    continue;
                }

                [$name, $payload] = $event;
                $onEvent($name, $payload);

                if (in_array($name, ['complete', 'error'], true)) {
                    return (int) ($payload['exit_code'] ?? $exitCode);
                }
            }
        }

        return $exitCode;
    }

    /**
     * @return array{string, array<string, mixed>}|null
     */
    private function parseFrame(string $frame): ?array
    {
        $event = 'message';
        $data = [];

        foreach (explode("\n", $frame) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }

        if ($data === []) {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(implode("\n", $data), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return [$event, $payload];
    }

    private function unavailable(string $message, ?string $reason = null): GatewayApiException
    {
        if (is_string($reason) && trim($reason) !== '') {
            $message .= ' '.trim($reason);
        }

        return new GatewayApiException(
            message: $message,
            errorCode: 'gateway_unavailable',
            errorMeta: [],
        );
    }
}
