<?php

declare(strict_types=1);

namespace App\Support\Cli;

/**
 * Formats per-item result lines for spinner-tree output.
 *
 * Owns: dot selection, label padding, color application.
 * Callers own: label text, result message text, and dot choice logic.
 */
final readonly class LifecycleSummaryRenderer
{
    public function __construct(
        private bool $styled = true,
    ) {}

    /**
     * Format a result line with a colored dot and a padded label.
     *
     *   ●  Label     Message
     */
    public function resultLine(
        string $dot,
        string $label,
        int $labelWidth,
        string $message = '',
        string $labelColor = SpinnerTreeRenderer::ACCENT,
    ): string {
        $padded = $labelColor.str_pad($label, $labelWidth).SpinnerTreeRenderer::RESET;

        $line = $message !== ''
            ? "  {$dot}  {$padded}  {$message}"
            : "  {$dot}  {$padded}";

        if ($this->styled) {
            return $line;
        }

        return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $line) ?? $line;
    }

    /**
     * Format a success result line (green dot, accent message).
     */
    public function success(string $label, int $labelWidth, string $message): string
    {
        return $this->resultLine(
            SpinnerTreeRenderer::GREEN.'●'.SpinnerTreeRenderer::RESET,
            $label,
            $labelWidth,
            SpinnerTreeRenderer::ACCENT.$message.SpinnerTreeRenderer::RESET,
        );
    }

    /**
     * Format a failure result line (red dot, red message).
     */
    public function failure(string $label, int $labelWidth, string $message): string
    {
        return $this->resultLine(
            SpinnerTreeRenderer::RED.'●'.SpinnerTreeRenderer::RESET,
            $label,
            $labelWidth,
            SpinnerTreeRenderer::RED.$message.SpinnerTreeRenderer::RESET,
        );
    }

    /**
     * Format a skipped/warning result line (orange dot, dim message).
     */
    public function skipped(string $label, int $labelWidth, string $message): string
    {
        return $this->resultLine(
            SpinnerTreeRenderer::ORANGE.'●'.SpinnerTreeRenderer::RESET,
            $label,
            $labelWidth,
            SpinnerTreeRenderer::DIM.$message.SpinnerTreeRenderer::RESET,
        );
    }

    /**
     * Format a dimmed/idle result line (dim dot, dim message).
     */
    public function idle(string $label, int $labelWidth, string $message = ''): string
    {
        return $this->resultLine(
            SpinnerTreeRenderer::DIM.'●'.SpinnerTreeRenderer::RESET,
            $label,
            $labelWidth,
            $message !== '' ? SpinnerTreeRenderer::DIM.$message.SpinnerTreeRenderer::RESET : '',
            SpinnerTreeRenderer::DIM,
        );
    }

    /**
     * Format a spinner animation line with a padded label.
     */
    public function spinnerLine(string $frame, string $label, int $labelWidth, string $message = ''): string
    {
        $line = "  {$frame}  ".SpinnerTreeRenderer::ACCENT.str_pad($label, $labelWidth).SpinnerTreeRenderer::RESET;

        if ($message !== '') {
            $line .= '  '.SpinnerTreeRenderer::DIM.$message.SpinnerTreeRenderer::RESET;
        }

        if ($this->styled) {
            return $line;
        }

        return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $line) ?? $line;
    }
}
