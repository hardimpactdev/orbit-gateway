<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class AgentRoleSettings implements NodeRoleSettings
{
    public string $tld;

    public function __construct(?string $tld = null)
    {
        $tld = trim($tld ?? 'agent');

        if (! $this->isValidTld($tld)) {
            throw new InvalidArgumentException('The agent role requires a valid tld setting.');
        }

        $this->tld = $tld;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['tld']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The agent role does not accept unknown settings.');
        }

        $tld = $settings['tld'] ?? null;

        if ($tld === null) {
            return new self;
        }

        if (! is_string($tld)) {
            throw new InvalidArgumentException('The agent role requires a valid tld setting.');
        }

        return new self($tld);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['tld' => $this->tld];
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
    }
}
