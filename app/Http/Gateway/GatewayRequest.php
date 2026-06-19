<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use Saloon\Http\Request as SaloonRequest;
use Saloon\Http\Response;
use Throwable;

abstract class GatewayRequest extends SaloonRequest
{
    /**
     * Mark the request as failed if Saloon's HTTP failure detection trips,
     * OR the JSON envelope contains an "error" key (which can occur on 200).
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        if ($response->serverError() || $response->clientError()) {
            return true;
        }

        $body = $this->decodeBodyOrNull($response);

        return is_array($body) && array_key_exists('error', $body);
    }

    /**
     * Translate any failed response into a typed GatewayApiException.
     */
    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        $body = $this->decodeBodyOrNull($response);

        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $error = $body['error'];
            $message = is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : "Gateway request failed with HTTP status {$response->status()}";
            $code = is_string($error['code'] ?? null) ? $error['code'] : null;
            $meta = is_array($error['meta'] ?? null) ? $error['meta'] : [];
            $data = is_array($error['data'] ?? null) ? $error['data'] : [];

            return new GatewayApiException($message, $code, $meta, $senderException, $data);
        }

        return new GatewayApiException(
            "Gateway request failed with HTTP status {$response->status()}",
            null,
            [],
            $senderException,
        );
    }

    /**
     * Strip the gateway success envelope and return the raw data array.
     *
     * @return array<string, mixed>
     */
    protected function unwrapData(Response $response): array
    {
        $body = $this->decodeBodyOrNull($response);

        if (! is_array($body)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        if (array_key_exists('doctor', $body)) {
            return $body;
        }

        if (array_key_exists('success', $body)) {
            $success = $body['success'];

            if (is_array($success)) {
                $data = $success['data'] ?? [];

                return is_array($data) ? $data : [];
            }

            if ($success === true) {
                $data = $body['data'] ?? [];

                return is_array($data) ? $data : [];
            }
        }

        return $body;
    }

    /**
     * Strip the gateway success envelope and return the raw meta array.
     *
     * @return array<string, mixed>
     */
    protected function unwrapMeta(Response $response): array
    {
        $body = $this->decodeBodyOrNull($response);

        if (! is_array($body) || ! array_key_exists('success', $body)) {
            return [];
        }

        $success = $body['success'];

        if (! is_array($success)) {
            return [];
        }

        $meta = $success['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBodyOrNull(Response $response): ?array
    {
        $raw = $response->body();

        if ($raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException) {
            return null;
        }
    }
}
