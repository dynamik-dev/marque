<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

class IdentifierValidator
{
    public static function validate(string $id, string $type): void
    {
        if ($id === '' || preg_match('/[\s:]/', $id) || str_starts_with($id, '!')) {
            throw new \InvalidArgumentException(
                "Invalid {$type} ID [{$id}]. IDs must not be empty, contain whitespace or colons, or start with '!'.",
            );
        }

        if (strlen($id) > 255) {
            throw new \InvalidArgumentException(
                "Invalid {$type} ID [{$id}]. IDs must not exceed 255 characters.",
            );
        }
    }
}
