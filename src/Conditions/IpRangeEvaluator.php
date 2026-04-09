<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Conditions;

use DynamikDev\Marque\Contracts\ConditionEvaluator;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\EvaluationRequest;

class IpRangeEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        $ranges = $condition->parameters['ranges'] ?? null;

        if (! is_array($ranges) || $ranges === []) {
            return false;
        }

        $ip = $request->context->environment['ip'] ?? null;

        if (! is_string($ip) || $ip === '') {
            return false;
        }

        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return false;
        }

        foreach ($ranges as $range) {
            if (! is_string($range)) {
                continue;
            }

            if ($this->ipMatchesRange($ipLong, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesRange(int $ipLong, string $range): bool
    {
        if (str_contains($range, '/')) {
            [$network, $bits] = explode('/', $range, 2);

            $bits = (int) $bits;

            if ($bits < 0 || $bits > 32) {
                return false;
            }

            $networkLong = ip2long($network);

            if ($networkLong === false) {
                return false;
            }

            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));

            return ($ipLong & $mask) === ($networkLong & $mask);
        }

        $exactLong = ip2long($range);

        if ($exactLong === false) {
            return false;
        }

        return $ipLong === $exactLong;
    }
}
