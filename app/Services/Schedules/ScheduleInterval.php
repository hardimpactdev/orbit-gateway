<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use Carbon\CarbonImmutable;
use DateTimeZone;

final readonly class ScheduleInterval
{
    public function isDue(string $expression, string $timezone, CarbonImmutable $now): bool
    {
        $expression = strtolower(trim($expression));
        $localNow = $now->setTimezone($this->timezone($timezone));

        if ($expression === 'every minute') {
            return true;
        }

        if (preg_match('/^every ([1-9][0-9]*) minutes?$/', $expression, $matches) === 1) {
            return ((int) $localNow->format('i')) % (int) $matches[1] === 0;
        }

        if (preg_match('/^daily at ([0-2][0-9]):([0-5][0-9])$/', $expression, $matches) === 1) {
            return $this->matchesTime($localNow, $matches[1], $matches[2]);
        }

        if (preg_match('/^weekdays at ([0-2][0-9]):([0-5][0-9])$/', $expression, $matches) === 1) {
            return (int) $localNow->format('N') <= 5
                && $this->matchesTime($localNow, $matches[1], $matches[2]);
        }

        if (preg_match('/^weekly on ([a-z]+) at ([0-2][0-9]):([0-5][0-9])$/', $expression, $matches) === 1) {
            return strtolower($localNow->format('l')) === $matches[1]
                && $this->matchesTime($localNow, $matches[2], $matches[3]);
        }

        return false;
    }

    private function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function matchesTime(CarbonImmutable $now, string $hour, string $minute): bool
    {
        return (int) $now->format('H') === (int) $hour
            && (int) $now->format('i') === (int) $minute;
    }
}
