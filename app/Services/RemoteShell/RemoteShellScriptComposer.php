<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

final readonly class RemoteShellScriptComposer
{
    public function __construct(
        private RemoteShellMetadata $metadata,
    ) {}

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    public function compose(string $script, array $options): string
    {
        if ((bool) ($options['strict'] ?? false)) {
            return $this->composeStrict($script, $options);
        }

        $prefix = '';

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $prefix .= $this->metadata->prologue($this->metadataFromOptions($options));
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $prefix .= 'cd '.escapeshellarg($options['cwd']).' && ';
        }

        return $prefix.$script;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     * @return array<string, string>
     */
    public function metadataFromOptions(array $options, bool $validate = false): array
    {
        if (! isset($options['metadata']) || ! is_array($options['metadata'])) {
            return [];
        }

        $resolved = [];

        foreach ($options['metadata'] as $key => $value) {
            $stringKey = (string) $key;
            $stringValue = (string) $value;

            if ($validate) {
                $this->metadata->validate($stringKey, $stringValue);
            }

            $resolved[$stringKey] = $stringValue;
        }

        return $resolved;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function composeStrict(string $script, array $options): string
    {
        $lines = ['set -e'];

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $prologue = $this->metadata->prologue($this->metadataFromOptions($options));

            foreach (array_filter(explode('; ', trim($prologue))) as $line) {
                $lines[] = rtrim($line, ';');
            }
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $lines[] = 'cd '.escapeshellarg($options['cwd']);
        }

        $lines[] = $script;

        return implode(PHP_EOL, $lines);
    }
}
