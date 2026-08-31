<?php

declare(strict_types=1);

namespace Datawell\Ai\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class Tag extends Model
{
    protected $guarded = [];
}
