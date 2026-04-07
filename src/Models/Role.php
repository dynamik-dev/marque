<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Models;

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
        return $this->table ??= config('policy-engine.table_prefix', '').'roles';
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
        return $this->belongsToMany(
            related: Permission::class,
            table: config('policy-engine.table_prefix', '').'role_permissions',
            foreignPivotKey: 'role_id',
            relatedPivotKey: 'permission_id',
        );
    }
}
