<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Conditions;

use Carbon\Carbon;
use DynamikDev\Marque\Contracts\ConditionEvaluator;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use Throwable;

class TimeBetweenEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        $start = $condition->parameters['start'] ?? null;
        $end = $condition->parameters['end'] ?? null;
        $timezone = $condition->parameters['timezone'] ?? 'UTC';

        if (! is_string($start) || ! is_string($end) || $start === '' || $end === '' || ! is_string($timezone)) {
            return false;
        }

        try {
            $now = Carbon::now($timezone);
            $startTime = Carbon::createFromFormat('H:i', $start, $timezone);
            $endTime = Carbon::createFromFormat('H:i', $end, $timezone);
        } catch (Throwable) {
            return false;
        }

        // Carbon::createFromFormat returns false on parse failure (and null in some
        // historical versions). Treat anything that is not a Carbon instance as a
        // failed parse and deny, rather than evaluating against a surprise object.
        if (! $startTime instanceof Carbon || ! $endTime instanceof Carbon) {
            return false;
        }

        $nowMinutes = $now->hour * 60 + $now->minute;
        $startMinutes = $startTime->hour * 60 + $startTime->minute;
        $endMinutes = $endTime->hour * 60 + $endTime->minute;

        // The window is a half-open interval [start, end): the start minute is
        // included, the end minute is excluded. A 09:00-17:00 window passes at
        // 09:00 sharp but fails at 17:00 sharp.
        if ($startMinutes <= $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        // Wraps midnight: same half-open semantics, with [start, midnight) ∪ [00:00, end).
        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }
}
