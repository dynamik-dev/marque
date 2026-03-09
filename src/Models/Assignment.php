<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $subject_type
 * @property int|string $subject_id
 * @property string $role_id
 * @property string|null $scope
 */
class Assignment extends Model
{
    /** @var string */
    protected $table = 'assignments';

    /** @var list<string> */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'role_id',
        'scope',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
