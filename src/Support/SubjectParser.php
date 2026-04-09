<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use InvalidArgumentException;

class SubjectParser
{
    /**
     * Parse a subject string in "type::id" format.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $subject): array
    {
        $separatorPos = strpos($subject, '::');

        if ($separatorPos === false || $separatorPos === 0) {
            throw new InvalidArgumentException("Malformed subject string [{$subject}]. Expected format: 'type::id'.");
        }

        $id = substr($subject, $separatorPos + 2);

        if ($id === '') {
            throw new InvalidArgumentException("Malformed subject string [{$subject}]. Expected format: 'type::id'.");
        }

        return [
            substr($subject, 0, $separatorPos),
            $id,
        ];
    }
}
