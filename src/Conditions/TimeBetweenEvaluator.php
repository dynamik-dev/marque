<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Conditions;

use Carbon\Carbon;
use DynamikDev\PolicyEngine\Contracts\ConditionEvaluator;
use DynamikDev\PolicyEngine\DTOs\Condition;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use Throwable;

class TimeBetweenEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        $start = $condition->parameters['start'] ?? null;
        $end = $condition->parameters['end'] ?? null;
        $timezone = $condition->parameters['timezone'] ?? 'UTC';

        if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
            return false;
        }

        try {
            $now = Carbon::now($timezone);
            $startTime = Carbon::createFromFormat('H:i', $start, $timezone);
            $endTime = Carbon::createFromFormat('H:i', $end, $timezone);
        } catch (Throwable) {
            return false;
        }

        if ($startTime === false || $endTime === false) {
            return false;
        }

        $nowMinutes = $now->hour * 60 + $now->minute;
        $startMinutes = $startTime->hour * 60 + $startTime->minute;
        $endMinutes = $endTime->hour * 60 + $endTime->minute;

        if ($startMinutes <= $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        // Wraps midnight
        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }
}
