<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $role_id
 * @property string $permission_id
 * @property array<int, array{type: string, parameters: array<string, mixed>}>|null $conditions
 */
class RolePermission extends Model
{
    public function getTable(): string
    {
        /** @var string $prefix */
        $prefix = config('marque.table_prefix', '');

        return $this->table ??= $prefix.'role_permissions';
    }

    /** @var bool */
    public $incrementing = false;

    /** @var bool */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'role_id',
        'permission_id',
        'conditions',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'conditions' => 'array',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}
