<?php

declare(strict_types=1);

namespace DynamikDev\Marque\DTOs;

readonly class Condition
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $type,
        public array $parameters,
    ) {}

    /**
     * @param  array<int, mixed>  $raw
     * @return array<int, self>
     */
    public static function hydrateMany(array $raw): array
    {
        return array_map(
            static fn (array $item): self => new self(
                type: $item['type'],
                parameters: $item['parameters'] ?? [],
            ),
            array_filter($raw, 'is_array'),
        );
    }
}
