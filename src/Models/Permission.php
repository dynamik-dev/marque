<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    /** @var string */
    protected $primaryKey = 'id';

    public function getTable(): string
    {
        /** @var string $prefix */
        $prefix = config('policy-engine.table_prefix', '');

        return $this->table ??= $prefix.'permissions';
    }

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'description',
    ];
}
