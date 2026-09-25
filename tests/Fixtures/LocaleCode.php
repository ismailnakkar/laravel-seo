<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

/** An app's enum cast on the users' locale column. */
enum LocaleCode: string
{
    case en = 'en';
    case fr = 'fr';
    case ar = 'ar';
}
