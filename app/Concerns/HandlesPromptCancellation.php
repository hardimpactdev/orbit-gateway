<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Exceptions\PromptAborted;
use Closure;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\datatable;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait HandlesPromptCancellation
{
    /**
     * @throws PromptAborted
     */
    protected function promptText(string $label, bool|string $required = false, mixed $validate = null): string
    {
        return $this->withPromptCancellation(fn (): string => text(label: $label, required: $required, validate: $validate));
    }

    /**
     * @param  Closure(string): array<int|string, string>  $options
     *
     * @throws PromptAborted
     */
    protected function promptSearch(string $label, Closure $options, string $placeholder = ''): string
    {
        return $this->withPromptCancellation(fn (): string => search(label: $label, options: $options, placeholder: $placeholder, required: true));
    }

    /**
     * @param  array<int|string, string>  $options
     *
     * @throws PromptAborted
     */
    protected function promptSelect(string $label, array $options, string|int|null $default = null): string|int
    {
        return $this->withPromptCancellation(fn (): string|int => select(label: $label, options: $options, default: $default));
    }

    /**
     * @param  array<int, string|array<int, string>>  $headers
     * @param  array<int|string, array<int, string>>  $rows
     *
     * @throws PromptAborted
     */
    protected function promptDataTable(string $label, array $headers, array $rows, string $hint = 'Press / to search'): string|int
    {
        return $this->withPromptCancellation(function () use ($headers, $rows, $label, $hint): string|int {
            $selected = datatable(
                headers: $headers,
                rows: $rows,
                label: $label,
                hint: $hint,
                required: true,
            );

            return is_string($selected) || is_int($selected) ? $selected : '';
        });
    }

    /**
     * @throws PromptAborted
     */
    protected function promptConfirm(string $label, bool $default = true): bool
    {
        return $this->withPromptCancellation(fn (): bool => confirm($label, default: $default));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $prompt
     * @return TResult
     *
     * @throws PromptAborted
     */
    private function withPromptCancellation(Closure $prompt): mixed
    {
        Prompt::cancelUsing(fn () => throw new PromptAborted);

        try {
            return $prompt();
        } finally {
            Prompt::cancelUsing(null);
        }
    }
}
