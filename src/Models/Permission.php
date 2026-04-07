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
        return $this->table ??= config('policy-engine.table_prefix', '').'permissions';
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
