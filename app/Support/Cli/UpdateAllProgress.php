<?php

declare(strict_types=1);

namespace App\Support\Cli;

use Orbit\Core\Progress\LifecycleSummaryRenderer;
use Orbit\Core\Progress\SpinnerTreeRenderer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the per-target progress tree for `update:all`.
 *
 * Owns: tree layout, per-row stage updates as each target moves through
 * `Pulling source` → `Installing dependencies` → `Running migrations` → `Done`,
 * and dynamic extension when remote targets are discovered mid-flight.
 *
 * Each target is one row in the tree. The active row alternates cyan `○`/`◉`
 * with the target name and present-participle stage; completed rows use green
 * `●` with `Done`; failed rows use red `●` with `Failed`.
 */
final class UpdateAllProgress
{
    private const int FRAME_INTERVAL_US = 300_000;

    private const string STATE_PENDING = 'pending';

    private const string STATE_ACTIVE = 'active';

    private const string STATE_DONE = 'done';

    private const string STATE_FAILED = 'failed';

    private const string TITLE = 'Updating Orbit nodes';

    private readonly SpinnerTreeRenderer $tree;

    private readonly LifecycleSummaryRenderer $summary;

    /** @var list<string> Ordered target keys */
    private array $order = [];

    /** @var array<string, array{stage: string, state: string, message: string}> */
    private array $rows = [];

    private int $labelWidth = 0;

    private int $targetWidth = 0;

    private bool $finished = false;

    private bool $rendered = false;

    private int $frame = 0;

    private int $lastFrameAtUs = 0;

    private readonly int $frameIntervalUs;

    private readonly bool $decorated;

    /**
     * @param  list<array{target: string, node: string|null, role: string|null}>  $initialTargets
     */
    public function __construct(
        private readonly OutputInterface $output,
        array $initialTargets,
    ) {
        $this->decorated = $output->isDecorated();
        $this->frameIntervalUs = max(0, (int) config('orbit.progress.frame_interval_us', self::FRAME_INTERVAL_US));
        $this->tree = new SpinnerTreeRenderer($output->isDecorated());
        $this->summary = new LifecycleSummaryRenderer($output->isDecorated());

        foreach ($initialTargets as $target) {
            $this->registerTarget($target['target']);
        }

        $this->renderInitial();
    }

    public function start(string $key): void
    {
        $this->setRow($key, self::STATE_ACTIVE, 'pulling_source');
    }

    public function stage(string $key, string $stage): void
    {
        $this->setRow($key, self::STATE_ACTIVE, $stage);
    }

    public function done(string $key): void
    {
        $this->setRow($key, self::STATE_DONE, 'done');
    }

    public function fail(string $key, string $message): void
    {
        $this->setRow($key, self::STATE_FAILED, 'failed', $message);
    }

    public function tick(): void
    {
        if (! $this->decorated || $this->finished) {
            return;
        }

        $nowUs = (int) (microtime(true) * 1_000_000);

        if ($this->lastFrameAtUs !== 0 && ($nowUs - $this->lastFrameAtUs) < $this->frameIntervalUs) {
            return;
        }

        $this->lastFrameAtUs = $nowUs;
        $this->frame++;

        $frame = $this->activeFrame();

        foreach ($this->order as $key) {
            if (($this->rows[$key]['state'] ?? null) === self::STATE_ACTIVE) {
                $this->repaintRow($key, $frame);
            }
        }
    }

    /**
     * Append rows for targets discovered after the initial render.
     *
     * @param  list<array{target: string, node?: string|null, role?: string|null}>  $additionalTargets
     */
    public function extendWith(array $additionalTargets): void
    {
        $newKeys = [];

        foreach ($additionalTargets as $target) {
            $key = $target['target'];

            if (in_array($key, $this->order, true)) {
                continue;
            }

            $this->registerTarget($key);
            $newKeys[] = $key;
        }

        if ($newKeys === []) {
            return;
        }

        $this->renderExtension($newKeys);
    }

    public function finish(bool $success, string $footer): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        $this->tree->updateFooter(
            $this->output,
            $this->tree->footerLine($footer, $success ? SpinnerTreeRenderer::ACCENT : SpinnerTreeRenderer::RED),
        );

        if ($this->output->isDecorated()) {
            $this->tree->showCursor($this->output);
        }

        $this->output->writeln('');
    }

    private function registerTarget(string $key): void
    {
        if (in_array($key, $this->order, true)) {
            return;
        }

        $this->order[] = $key;
        $this->rows[$key] = [
            'stage' => 'waiting',
            'state' => self::STATE_PENDING,
            'message' => '',
        ];

        $this->targetWidth = max($this->targetWidth, mb_strlen($key));
        $this->labelWidth = $this->maxLabelWidth();
    }

    private function renderInitial(): void
    {
        $this->tree->renderFrame(
            $this->output,
            self::TITLE,
            array_map($this->labelFor(...), $this->order),
            'Working...',
        );
        $this->rendered = true;
    }

    /**
     * @param  list<string>  $newKeys
     */
    private function renderExtension(array $newKeys): void
    {
        if (! $this->rendered) {
            $this->renderInitial();

            return;
        }

        if ($this->output->isDecorated()) {
            // Move cursor up to overwrite the existing footer line, write the
            // new rows, then re-emit the footer below them.
            $this->output->write("\e[1A\e[2K\r");
        }

        foreach ($newKeys as $key) {
            $this->output->writeln('  '.SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET);
            $this->output->writeln('  '.SpinnerTreeRenderer::DIM.'○  '.$this->labelFor($key).SpinnerTreeRenderer::RESET);
        }

        $this->output->writeln('  '.SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET);
        $this->output->writeln($this->tree->footerLine('Working...'));

        foreach ($this->order as $key) {
            if (! in_array($key, $newKeys, true)) {
                $this->repaintRow($key);
            }
        }
    }

    private function setRow(string $key, string $state, string $stage, string $message = ''): void
    {
        if (! isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key] = [
            'stage' => $stage,
            'state' => $state,
            'message' => $message,
        ];

        $this->repaintRow($key);
    }

    private function repaintRow(string $key, ?string $activeFrame = null): void
    {
        $index = array_search($key, $this->order, true);

        if ($index === false) {
            return;
        }

        $row = $this->rows[$key];
        $label = $this->labelFor($key);
        $line = match ($row['state']) {
            self::STATE_ACTIVE => $this->summary->spinnerLine(
                $activeFrame ?? $this->activeFrame(),
                $label,
                $this->labelWidth,
            ),
            self::STATE_DONE => $this->summary->success($label, $this->labelWidth, ''),
            self::STATE_FAILED => $this->summary->failure($label, $this->labelWidth, $row['message']),
            default => $this->summary->idle($label, $this->labelWidth),
        };

        $this->tree->updateLine($this->output, $index, count($this->order), $line);
    }

    private function activeFrame(): string
    {
        return $this->frame % 2 === 0
            ? "\e[36m○\e[39m"
            : "\e[36m◉\e[39m";
    }

    private function maxLabelWidth(): int
    {
        $stages = ['Waiting', 'Pulling source', 'Installing dependencies', 'Running migrations', 'Done', 'Failed'];

        return $this->targetWidth + 1 + max(array_map(mb_strlen(...), $stages));
    }

    private function labelFor(string $target): string
    {
        return str_pad($target, $this->targetWidth).' '.$this->stageName($this->rows[$target]['stage'] ?? 'pulling_source');
    }

    private function stageName(string $stage): string
    {
        return match ($stage) {
            'waiting' => 'Waiting',
            'start', 'pulling_source' => 'Pulling source',
            'installing_dependencies' => 'Installing dependencies',
            'running_migrations' => 'Running migrations',
            'done' => 'Done',
            'failed', 'fail' => 'Failed',
            default => $stage,
        };
    }
}
