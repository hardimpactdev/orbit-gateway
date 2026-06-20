<?php

declare(strict_types=1);

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\WireGuardIdentity;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const LOG_TEST_WG_IP = '10.6.0.99';

final class FakeWriteController implements Loggable
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function type(): string
    {
        return 'api:POST /_test/fake-write';
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function properties(): array
    {
        return ['probe' => 'ok'];
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }

    public function description(): ?string
    {
        return null;
    }
}

final class FakeDestructiveController implements Loggable
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function type(): string
    {
        return 'api:DELETE /_test/fake-destructive';
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function properties(): array
    {
        return [];
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }

    public function description(): ?string
    {
        return null;
    }
}

final class FakeDoctrineController implements Loggable
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /_test/fake-doctrine';
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function properties(): array
    {
        return ['method_surface' => 'doctrine'];
    }

    public function description(): ?string
    {
        return 'doctrine method names';
    }

    public function activityLogType(): ActivityLogType
    {
        throw new RuntimeException('legacy effect method should not be called');
    }

    public function activityLogAction(): string
    {
        throw new RuntimeException('legacy type method should not be called');
    }

    public function activityLogSubject(): ?Model
    {
        throw new RuntimeException('legacy subject method should not be called');
    }

    public function activityLogProperties(): array
    {
        throw new RuntimeException('legacy properties method should not be called');
    }

    public function activityLogDescription(): ?string
    {
        throw new RuntimeException('legacy description method should not be called');
    }
}

describe('LogActivity middleware', function (): void {
    beforeEach(function (): void {
        Node::factory()->gateway()->create([
            'name' => 'gw',
            'host' => '10.6.0.1',
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => '10.6.0.1',
        ]);

        Route::middleware([WireGuardIdentity::class, LogActivity::class])
            ->post('/_test/fake-write', FakeWriteController::class);

        Route::middleware([WireGuardIdentity::class, LogActivity::class])
            ->get('/_test/fake-doctrine', FakeDoctrineController::class);
    });

    it('logs an entry with causer hydrated from the authenticated node', function (): void {
        Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => LOG_TEST_WG_IP,
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => LOG_TEST_WG_IP,
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => LOG_TEST_WG_IP])
            ->postJson('/_test/fake-write')
            ->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->log_name)->toBe('api');
        expect($entry->event)->toBe('api:POST /_test/fake-write');
        expect($entry->causer_type)->toBe(Node::class);
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('probe'))->toBe('ok');
        expect($entry->properties->get('method'))->toBe('POST');
        expect($entry->properties->get('path'))->toBe('_test/fake-write');
    });

    it('resumes correlation from X-Orbit-Request-Id header', function (): void {
        Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => LOG_TEST_WG_IP,
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => LOG_TEST_WG_IP,
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => LOG_TEST_WG_IP])
            ->withHeaders(['X-Orbit-Request-Id' => '77777777-8888-9999-aaaa-bbbbbbbbbbbb'])
            ->postJson('/_test/fake-write')
            ->assertOk();

        $entry = Activity::query()->first();
        expect($entry->batch_uuid)->toBe('77777777-8888-9999-aaaa-bbbbbbbbbbbb');
    });

    it('generates a fresh batch uuid when no header is supplied', function (): void {
        Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => LOG_TEST_WG_IP,
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => LOG_TEST_WG_IP,
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => LOG_TEST_WG_IP])
            ->postJson('/_test/fake-write')
            ->assertOk();

        $entry = Activity::query()->first();
        expect($entry->batch_uuid)->toMatch('/^[0-9a-f\-]{36}$/');
    });

    it('does not log when controller does not implement Loggable', function (): void {
        Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => LOG_TEST_WG_IP,
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => LOG_TEST_WG_IP,
        ]);

        Route::middleware([WireGuardIdentity::class, LogActivity::class])
            ->get('/_test/no-log', fn () => response()->json(['ok' => true]));

        $this
            ->withServerVariables(['REMOTE_ADDR' => LOG_TEST_WG_IP])
            ->getJson('/_test/no-log')
            ->assertOk();

        expect(Activity::query()->count())->toBe(0);
    });

    it('logs destructive effects', function (): void {
        Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => LOG_TEST_WG_IP,
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => LOG_TEST_WG_IP,
        ]);

        Route::middleware([WireGuardIdentity::class, LogActivity::class])
            ->delete('/_test/fake-destructive', FakeDestructiveController::class);

        $this
            ->withServerVariables(['REMOTE_ADDR' => LOG_TEST_WG_IP])
            ->deleteJson('/_test/fake-destructive')
            ->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:DELETE /_test/fake-destructive');
        expect($entry->properties->get('type'))->toBe('destructive');
    });

    it('uses the doctrine Loggable method names when writing activity', function (): void {
        Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => LOG_TEST_WG_IP,
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'wireguard_address' => LOG_TEST_WG_IP,
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => LOG_TEST_WG_IP])
            ->getJson('/_test/fake-doctrine')
            ->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:GET /_test/fake-doctrine');
        expect($entry->description)->toBe('doctrine method names');
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('method_surface'))->toBe('doctrine');
    });
});
