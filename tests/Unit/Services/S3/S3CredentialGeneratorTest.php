<?php

declare(strict_types=1);

namespace Tests\Unit\Services\S3;

use App\Services\S3\S3CredentialGenerator;
use App\Services\S3\S3Credentials;

beforeEach(function (): void {
    $this->generator = new S3CredentialGenerator;
});

describe('credential generation', function (): void {
    it('returns an S3Credentials value object', function (): void {
        $credentials = $this->generator->generate();

        expect($credentials)->toBeInstanceOf(S3Credentials::class);
    });

    it('generates a non-empty access key id and secret access key', function (): void {
        $credentials = $this->generator->generate();

        expect($credentials->accessKeyId)->toBeString()->not->toBeEmpty()
            ->and($credentials->secretAccessKey)->toBeString()->not->toBeEmpty();
    });

    it('generates an access key id of exactly 20 characters', function (): void {
        $credentials = $this->generator->generate();

        expect(strlen($credentials->accessKeyId))->toBe(20);
    });

    it('generates an access key id containing only uppercase letters and digits', function (): void {
        $credentials = $this->generator->generate();

        expect($credentials->accessKeyId)->toMatch('/^[A-Z0-9]{20}$/');
    });

    it('generates a secret access key of exactly 43 characters', function (): void {
        $credentials = $this->generator->generate();

        expect(strlen($credentials->secretAccessKey))->toBe(43);
    });

    it('generates a secret access key containing only base64url characters', function (): void {
        $credentials = $this->generator->generate();

        expect($credentials->secretAccessKey)->toMatch('/^[A-Za-z0-9\-_]{43}$/');
    });

    it('produces different credentials on each call', function (): void {
        $a = $this->generator->generate();
        $b = $this->generator->generate();

        expect($a->accessKeyId)->not->toBe($b->accessKeyId)
            ->and($a->secretAccessKey)->not->toBe($b->secretAccessKey);
    });
});

describe('value object shape', function (): void {
    it('exposes fields array with access_key_id and secret_access_key keys', function (): void {
        $credentials = $this->generator->generate();
        $fields = $credentials->toFields();

        expect($fields)->toHaveKeys(['access_key_id', 'secret_access_key'])
            ->and($fields['access_key_id'])->toBe($credentials->accessKeyId)
            ->and($fields['secret_access_key'])->toBe($credentials->secretAccessKey);
    });

    it('produces a fields array compatible with the seaweedfs tool row credentials shape', function (): void {
        $credentials = $this->generator->generate();
        $fields = $credentials->toFields();

        expect($fields)->toBeArray()
            ->and(array_keys($fields))->toBe(['access_key_id', 'secret_access_key']);
    });
});
