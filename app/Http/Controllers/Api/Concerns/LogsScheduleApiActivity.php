<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait LogsScheduleApiActivity
{
    protected function setScheduleActivitySubject(Request $request, Schedule $schedule): void
    {
        $request->attributes->set('schedule_activity_subject', $schedule);
    }

    public function subject(): ?Model
    {
        $subject = request()->attributes->get('schedule_activity_subject');

        return $subject instanceof Schedule ? $subject : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        $request = request();

        return array_filter([
            'name' => $request->route('name') ?? $this->optionalActivityString($request, 'name'),
            'app' => $this->optionalActivityString($request, 'app'),
            'node' => $this->optionalActivityString($request, 'node'),
            'run' => $request->query('run'),
            'lines' => $request->query('lines'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        return null;
    }

    private function optionalActivityString(Request $request, string $key): ?string
    {
        $value = $request->input($key, $request->query($key));

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
