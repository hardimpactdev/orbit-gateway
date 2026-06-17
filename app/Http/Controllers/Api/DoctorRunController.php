<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\Doctor\DoctorValidationFailure;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DoctorRunController implements Loggable
{
    public function __invoke(
        Request $request,
        DoctorReportRunner $runner,
        DoctorScopeValidator $validator,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ], 403);
        }

        $families = $this->families($request);
        $key = $this->key($request);
        $target = $this->resolveTarget($request, $caller);

        if ($target === null) {
            return response()->json([
                'error' => [
                    'code' => 'scope_not_found',
                    'message' => 'Target node could not be resolved.',
                    'meta' => ['node' => $request->input('node')],
                ],
            ], 422);
        }

        $failure = $validator->validate($families, $runner, $target);

        if ($failure instanceof DoctorValidationFailure) {
            return response()->json([
                'error' => [
                    'code' => $failure->code,
                    'message' => $failure->message,
                    'meta' => $failure->meta,
                ],
            ], 422);
        }

        if ($this->wantsEventStream($request)) {
            return $this->stream($streams, $runner, $target, $families, $key);
        }

        $doctor = $runner->probe($target, families: $families, key: $key);

        return response()->json([
            'success' => [
                'data' => [
                    'doctor' => $doctor,
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $families
     */
    private function stream(
        ProgressEventStreamResponseFactory $streams,
        DoctorReportRunner $runner,
        Node $target,
        array $families,
        ?string $key,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use ($runner, $target, $families, $key): void {
            $renderedFamilies = $families === [] ? $runner->categoriesForNode($target) : $families;
            $events->tree('Running Doctor', array_map(
                fn (string $family): array => [
                    'key' => $family,
                    'label' => "Check {$family}",
                ],
                $renderedFamilies,
            ));

            foreach ($renderedFamilies as $family) {
                $events->stepEvent($family, 'running', "Checking {$family}");
            }

            $doctor = $runner->probe($target, families: $families, key: $key);

            foreach ($renderedFamilies as $family) {
                $events->stepEvent($family, 'done', "{$family} checked");
            }

            if (($doctor['healthy'] ?? false) === true) {
                $events->complete(0, [
                    'footer' => 'Doctor completed.',
                    'doctor' => $doctor,
                ]);

                return;
            }

            $events->error('Doctor detected drift.', 1, [
                'code' => 'drift_detected',
                'message' => 'Doctor detected drift.',
                'meta' => [],
                'data' => ['doctor' => $doctor],
                'footer' => 'Doctor detected drift.',
            ]);
        });
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    /**
     * @return list<string>
     */
    private function families(Request $request): array
    {
        $families = $request->input('families', []);

        if (! is_array($families)) {
            return [];
        }

        return array_values(array_filter($families, static fn (mixed $family): bool => is_string($family) && $family !== ''));
    }

    private function resolveTarget(Request $request, Node $caller): ?Node
    {
        $name = $request->input('node');

        if (is_string($name) && $name !== '') {
            $target = Node::query()->where('name', $name)->first();

            return $target instanceof Node ? $target : null;
        }

        return $caller;
    }

    private function key(Request $request): ?string
    {
        $key = $request->input('key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /doctor/run';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
