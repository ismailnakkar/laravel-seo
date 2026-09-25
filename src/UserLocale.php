<?php

declare(strict_types=1);

namespace Seo;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;

/** @internal The signed-in user's language, in the attribute config('seo.user_locale') names. */
final class UserLocale
{
    /** The user's configured code; null without the setting, for a non-Eloquent user, or for an unset or unknown value. */
    public static function of(mixed $user, Locales $locales): ?string
    {
        $column = self::column();

        if ($column === null || ! $user instanceof Model) {
            return null;
        }

        $value = $user->getAttribute($column);
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return is_string($value) && in_array($value, $locales->codes, true) ? $value : null;
    }

    /**
     * Through a fresh instance: nothing else the request changed on $user is written, and model events fire, so an
     * app's observers can clear a user cache. Then $user is synced, so later reads in the request see it.
     */
    public static function save(mixed $user, string $code): void
    {
        $column = self::column();

        if ($column === null || ! $user instanceof Model) {
            return;
        }

        $fresh = $user->newQueryWithoutScopes()->find($user->getKey());

        if (! $fresh instanceof Model) {
            return;
        }

        $fresh->forceFill([$column => $code])->save();
        $user->setAttribute($column, $fresh->getAttribute($column));
        $user->syncOriginalAttribute($column);
    }

    private static function column(): ?string
    {
        $column = Container::getInstance()->make('config')->get('seo.user_locale');

        return is_string($column) && $column !== '' ? $column : null;
    }
}
