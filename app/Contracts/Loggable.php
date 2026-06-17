<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\ActivityLogType;
use Illuminate\Database\Eloquent\Model;

interface Loggable
{
    /**
     * Whether this action mutates state. Every implementation MUST declare.
     */
    public function effect(): ActivityLogType;

    /**
     * Human-stable action name. Defaults (see traits) usually derive from
     * command name or "METHOD /path", but implementations may override.
     */
    public function type(): string;

    /**
     * The domain entity this action targets, if any (e.g. the Node granted,
     * the App deployed). Null means the action has no single target.
     */
    public function subject(): ?Model;

    /**
     * Structured, audit-relevant properties. Implementations MUST NOT include
     * secrets. Callers must not log raw request/command args — only declared
     * fields.
     *
     * @return array<string, mixed>
     */
    public function properties(): array;

    /**
     * Optional human-readable summary for the log description column.
     */
    public function description(): ?string;
}
