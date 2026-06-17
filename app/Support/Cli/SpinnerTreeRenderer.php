<?php

declare(strict_types=1);

namespace App\Support\Cli;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Owns the ANSI mechanics for spinner-tree output:
 * box-drawing characters, cursor hide/show, and line update formula.
 *
 * Callers are responsible for content (titles, labels, result text).
 */
final readonly class SpinnerTreeRenderer
{
    /** @var list<string> Pre-colored spinner frames: ○/◉ alternation in cyan */
    public const array SPINNER_FRAMES = [
        "\e[36m○\e[39m",
        "\e[36m◉\e[39m",
    ];

    public const string DIM = "\e[38;5;242m";

    public const string ACCENT = "\e[97m";

    public const string GREEN = "\e[32m";

    public const string RED = "\e[31m";

    public const string ORANGE = "\e[38;5;208m";

    public const string RESET = "\e[39m";

    public function __construct(
        private bool $styled = true,
    ) {}

    /**
     * @return list<string>
     */
    public static function spinnerFrames(): array
    {
        return self::SPINNER_FRAMES;
    }

    /**
     * Render the initial tree frame: header line, idle rows, and footer line.
     * Hides the cursor at the end.
     *
     * @param  list<string>  $labels  One label string per item row (already formatted)
     */
    public function renderFrame(
        OutputInterface $output,
        string $title,
        array $labels,
        string $footer,
    ): void {
        $output->writeln('');
        $output->writeln($this->render('  '.self::DIM.'┌'.self::RESET.'  '.self::ACCENT.$title.self::RESET));

        foreach ($labels as $label) {
            $output->writeln($this->render('  '.self::DIM.'│'.self::RESET));
            $output->writeln($this->render('  '.self::DIM.'○  '.$label.self::RESET));
        }

        $output->writeln($this->render('  '.self::DIM.'│'.self::RESET));
        $output->writeln($this->footerLine($footer));

        if ($this->styled) {
            $this->hideCursor($output);
        }
    }

    /**
     * Move cursor to item row $index (0-based) within a tree of $total items
     * and overwrite the line with $content. Leaves cursor on the footer line.
     */
    public function updateLine(
        OutputInterface $output,
        int $index,
        int $total,
        string $content,
    ): void {
        if (! $this->styled) {
            $output->writeln($this->render($content));

            return;
        }

        $up = 2 * ($total - $index) + 1;
        $output->write("\e[{$up}A\e[2K\r{$content}\e[{$up}B\r");
    }

    /**
     * Overwrite the footer line (the └ line) with new content.
     * Assumes cursor is already positioned one line below the footer.
     */
    public function updateFooter(OutputInterface $output, string $content): void
    {
        if (! $this->styled) {
            $output->writeln($this->render($content));

            return;
        }

        $output->write("\e[1A\e[2K\r{$content}\e[1B\r");
    }

    public function footerLine(string $footer, string $footerColor = self::DIM): string
    {
        return $this->render('  '.self::DIM.'└'.self::RESET.'  '.$footerColor.$footer.self::RESET);
    }

    public function hideCursor(OutputInterface $output): void
    {
        $output->write("\e[?25l");
    }

    public function showCursor(OutputInterface $output): void
    {
        $output->write("\e[?25h");
    }

    private function render(string $content): string
    {
        if ($this->styled) {
            return $content;
        }

        return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $content) ?? $content;
    }
}
