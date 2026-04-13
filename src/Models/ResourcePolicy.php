<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Models;

use DynamikDev\Marque\Enums\Effect;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $resource_type
 * @property string|null $resource_id
 * @property string $effect
 * @property string $action
 * @property string|null $principal_pattern
 * @property array<int, mixed>|null $conditions
 */
class ResourcePolicy extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $guarded = [];

    public function getTable(): string
    {
        /** @var string $prefix */
        $prefix = config('marque.table_prefix', '');

        return $this->table ??= $prefix.'resource_policies';
    }

    public function getEffectEnum(): Effect
    {
        return match ($this->effect) {
            'Allow' => Effect::Allow,
            'Deny' => Effect::Deny,
            default => throw new \UnexpectedValueException("Unknown effect: {$this->effect}"),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
        ];
    }
}
