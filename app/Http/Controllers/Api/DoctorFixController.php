<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\Doctor\DoctorValidationFailure;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DoctorFixController implements Loggable
{
    private string $activityMode = 'restore';

    private ?string $activityKey = null;

    private bool $activityDryRun = false;

    public function __invoke(
        Request $request,
        DoctorReportRunner $runner,
        DoctorScopeValidator $validator,
        NodeAccessAuthorizer $authorizer,
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

        $mode = $this->mode($request);

        if ($mode === null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Doctor fix mode must be restore or adopt.',
                    'meta' => ['fields' => ['mode']],
                ],
            ], 422);
        }

        $this->activityMode = $mode;
        $key = $this->key($request);
        $dryRun = $request->boolean('dry_run');
        $this->activityKey = $key;
        $this->activityDryRun = $dryRun;

        $families = $this->families($request);
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

        $authorization = $this->authorizeDoctorFix($authorizer, $caller, $target, $mode);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
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

        $issues = $this->issues($request);

        if ($this->wantsEventStream($request)) {
            return $this->stream($streams, $runner, $target, $mode, $families, $issues, $key, $dryRun);
        }

        $doctor = $issues === null || $dryRun
            ? $runner->run($target, mode: $mode, families: $families, key: $key, dryRun: $dryRun)
            : $this->applySelectedIssues($runner, $target, $mode, $families, $issues, $key);

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
     * @param  list<array<string, mixed>>|null  $issues
     */
    private function stream(
        ProgressEventStreamResponseFactory $streams,
        DoctorReportRunner $runner,
        Node $target,
        string $mode,
        array $families,
        ?array $issues,
        ?string $key,
        bool $dryRun,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use ($runner, $target, $mode, $families, $issues, $key, $dryRun): void {
            $renderedFamilies = $families === [] ? $runner->categoriesForNode($target) : $families;
            $events->tree('Running Doctor', array_map(
                fn (string $family): array => [
                    'key' => $family,
                    'label' => "{$mode} {$family}",
                ],
                $renderedFamilies,
            ));

            foreach ($renderedFamilies as $family) {
                $events->stepEvent($family, 'running', "{$mode} {$family}");
            }

            $doctor = $issues === null || $dryRun
                ? $runner->run($target, mode: $mode, families: $families, key: $key, dryRun: $dryRun)
                : $this->applySelectedIssues($runner, $target, $mode, $families, $issues, $key);

            foreach ($renderedFamilies as $family) {
                $events->stepEvent($family, 'done', "{$family} {$mode} complete");
            }

            if (($doctor['healthy'] ?? false) === true || $dryRun) {
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

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    private function applySelectedIssues(DoctorReportRunner $runner, Node $target, string $mode, array $families, array $issues, ?string $key): array
    {
        $probe = $runner->probe($target, $families, $key);
        $actions = $runner->apply($target, $mode, $issues);

        return $runner->finalize($probe, $mode, $actions);
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

    private function authorizeDoctorFix(NodeAccessAuthorizer $authorizer, Node $caller, Node $target, string $mode): ?JsonResponse
    {
        $permission = $mode === 'adopt' ? 'doctor:adopt' : 'doctor:restore';
        $result = $authorizer->authorize($caller, $target, $permission);

        if ($result->allowed) {
            return null;
        }

        return $this->authorizationFailed($target, $permission, $result, $mode);
    }

    private function authorizationFailed(Node $target, string $permission, AuthorizationResult $result, string $mode): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => "This node is not authorized for '{$permission}' on '{$target->name}'.",
                'meta' => [
                    'reason' => $result->reason,
                    'missing_permission' => $result->missingPermission,
                    'serving_node' => $target->name,
                    'mode' => $mode,
                ],
            ],
        ], 403);
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

    private function mode(Request $request): ?string
    {
        $mode = $request->input('mode');

        return is_string($mode) && in_array($mode, ['restore', 'adopt'], true) ? $mode : null;
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    private function key(Request $request): ?string
    {
        $key = $request->input('key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function issues(Request $request): ?array
    {
        if (! $request->has('issues')) {
            return null;
        }

        $issues = $request->input('issues');

        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_filter($issues, is_array(...)));
    }

    public function effect(): ActivityLogType
    {
        if ($this->activityDryRun) {
            return ActivityLogType::Read;
        }

        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /doctor/fix';
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
        return array_filter([
            'mode' => $this->activityMode,
            'key' => $this->activityKey,
            'dry_run' => $this->activityDryRun ? true : null,
        ], static fn (mixed $value): bool => $value !== null);
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
