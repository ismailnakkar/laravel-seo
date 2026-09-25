<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A users-table model for the language tests.
 *
 * @property int $id
 * @property string $name
 * @property mixed $locale
 */
final class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
