<?php

declare(strict_types=1);

namespace Pest\Realtime\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 */
final class Post extends Model
{
    protected $guarded = [];
}
