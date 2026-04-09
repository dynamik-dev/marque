<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $name
 * @property bool $is_system
 */
class Role extends Model
{
    /** @var string */
    protected $primaryKey = 'id';

    public function getTable(): string
    {
        /** @var string $prefix */
        $prefix = config('marque.table_prefix', '');

        return $this->table ??= $prefix.'roles';
    }

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'name',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        /** @var string $prefix */
        $prefix = config('marque.table_prefix', '');

        return $this->belongsToMany(
            related: Permission::class,
            table: $prefix.'role_permissions',
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'permission_id',
        );
    }
}
