<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    /** @var string */
    protected $table = 'permissions';

    /** @var string */
    protected $primaryKey = 'id';

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
