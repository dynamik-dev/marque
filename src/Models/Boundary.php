<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $scope
 * @property array<int, string> $max_permissions
 */
class Boundary extends Model
{
    public function getTable(): string
    {
        /** @var string $prefix */
        $prefix = config('policy-engine.table_prefix', '');

        return $this->table ??= $prefix.'boundaries';
    }

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'max_permissions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_permissions' => 'array',
        ];
    }
}
