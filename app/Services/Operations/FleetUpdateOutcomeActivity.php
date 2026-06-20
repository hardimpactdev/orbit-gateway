<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use Illuminate\Database\Eloquent\Model;

/**
 * Loggable for the terminal outcome of a durable fleet update run.
 * Emitted gateway-side at the chokepoint in UpdateRunner — one entry per run.
 */
final readonly class FleetUpdateOutcomeActivity implements Loggable
{
    public function __construct(
        private OperationRun $operationRun,
        private OperationUpdatePlan $plan,
        private string $status,
        private ?string $failedStep,
    ) {}

    #[\Override]
    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    #[\Override]
    public function type(): string
    {
        return 'update:all';
    }

    #[\Override]
    public function subject(): Model
    {
        return $this->operationRun;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function properties(): array
    {
        $props = [
            'scope' => 'fleet',
            'operation_run_id' => $this->operationRun->id,
            'status' => $this->status,
            'target_version' => $this->plan->target_version,
            'gateway_image_digest' => $this->gatewayImageDigest(),
            'manifest_version' => $this->plan->manifest_version,
            'manifest_source' => $this->plan->manifest_source,
        ];

        if ($this->failedStep !== null) {
            $props['failed_step'] = $this->failedStep;
        }

        return $props;
    }

    #[\Override]
    public function description(): ?string
    {
        return null;
    }

    private function gatewayImageDigest(): ?string
    {
        $image = $this->plan->gateway_image;

        $atPos = strrpos($image, '@');

        if ($atPos === false) {
            return null;
        }

        $digest = substr($image, $atPos + 1);

        return $digest !== '' ? $digest : null;
    }
}
