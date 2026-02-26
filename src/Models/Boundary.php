<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Models;

use Illuminate\Database\Eloquent\Model;

class Boundary extends Model
{
    /** @var string */
    protected $table = 'boundaries';

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
