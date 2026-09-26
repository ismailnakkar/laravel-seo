<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** A second guard's model, on a table without the users' `locale` column. */
final class Admin extends Authenticatable
{
    protected $table = 'admins';

    protected $guarded = [];
}
