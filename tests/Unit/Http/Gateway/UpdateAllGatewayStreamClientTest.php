<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\GatewayStreamTransport;
use App\Http\Gateway\Requests\Operations\UpdateAllStreamRequest;
use App\Http\Gateway\UpdateAllGatewayStreamClient;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('consumes gateway update events without waiting for large buffered reads', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://gateway.test',
    ])->save();

    $body = new OneByteOnlyUpdateAllStream(
        "event: tree\n"
        .'data: {"title":"Updating Orbit nodes","steps":[{"key":"gateway","label":"Pulling source - gateway"}]}'
        ."\n\n"
        ."event: complete\n"
        .'data: {"exit_code":0,"data":{"updates":[],"summary":{"total":0,"completed":0,"failed":0}}}'
        ."\n\n",
    );
    $mock = new MockClient([
        UpdateAllStreamRequest::class => new OneByteOnlyUpdateAllStreamResponse($body),
    ]);
    $connector = new GatewayConnector;
    $connector->withMockClient($mock);
    $events = [];

    $exitCode = (new UpdateAllGatewayStreamClient(new GatewayStreamTransport($connector)))->run(
        function (string $event, array $payload) use (&$events): void {
            $events[] = [$event, $payload];
        },
    );

    expect($exitCode)->toBe(0);
    expect(array_column($events, 0))->toBe(['tree', 'complete']);
    expect($body->largestReadLength)->toBe(1);
    $mock->assertSent(UpdateAllStreamRequest::class);
});

final class OneByteOnlyUpdateAllStreamResponse extends MockResponse
{
    public function __construct(
        private readonly StreamInterface $stream,
    ) {
        parent::__construct('', 200, ['Content-Type' => 'text/event-stream']);
    }

    public function createPsrResponse(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory): ResponseInterface
    {
        return $responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withBody($this->stream);
    }
}

final class OneByteOnlyUpdateAllStream implements StreamInterface
{
    public int $largestReadLength = 0;

    private int $offset = 0;

    public function __construct(
        private readonly string $contents,
    ) {}

    public function __toString(): string
    {
        return substr($this->contents, $this->offset);
    }

    public function close(): void
    {
        //
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->contents);
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Stream is not seekable.');
    }

    public function rewind(): void
    {
        $this->offset = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $this->largestReadLength = max($this->largestReadLength, $length);

        if ($length > 1) {
            throw new RuntimeException("Read length {$length} buffers stream events.");
        }

        if ($this->eof()) {
            return '';
        }

        return $this->contents[$this->offset++];
    }

    public function getContents(): string
    {
        $contents = substr($this->contents, $this->offset);
        $this->offset = strlen($this->contents);

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
