<?php

declare(strict_types=1);

namespace Datawell\Ai\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property bool $active
 * @property int $workspace_id
 * @property list<string> $abilities
 */
class User extends Authenticatable
{
    protected $guarded = [];

    protected $casts = ['abilities' => 'array', 'active' => 'boolean'];

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_user');
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
