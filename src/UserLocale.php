<?php

declare(strict_types=1);

namespace Seo;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;

/** @internal The signed-in user's language, in the attribute config('seo.user_locale') names. */
final class UserLocale
{
    /** The user's configured code; null without the column (hasColumn()), or for an unset or unknown value. */
    public static function of(mixed $user, Locales $locales): ?string
    {
        if (! self::hasColumn($user)) {
            return null;
        }

        $value = $user->getAttribute(self::column());
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return is_string($value) && in_array($value, $locales->codes, true) ? $value : null;
    }

    /**
     * Whether $user's loaded row has the column: never without the setting, for a non-Eloquent user or another
     * guard's model, or for a user created in this request, which lacks it until read back.
     *
     * @phpstan-assert-if-true Model $user
     */
    public static function hasColumn(mixed $user): bool
    {
        $column = self::column();

        return $column !== null && $user instanceof Model && array_key_exists($column, $user->getAttributes());
    }

    /**
     * Through Seo::saveUserLocaleUsing()'s closure, else a fresh instance: nothing else the request changed on $user is
     * written, and model events fire, so an app's observers can clear a user cache. Then $user is synced, so later
     * reads in the request see it. With $unlessSet, a row already holding one of its codes is left alone: a cached
     * $user can be staler than the row.
     */
    public static function save(mixed $user, string $code, ?Locales $unlessSet = null): void
    {
        $column = self::column();

        if ($column === null || ! $user instanceof Model) {
            return;
        }

        $fresh = $user->newQueryWithoutScopes()->find($user->getKey());

        // The row, not $user: a user created in this request lacks the column until read back.
        if (! $fresh instanceof Model || ! array_key_exists($column, $fresh->getAttributes())) {
            return;
        }

        if ($unlessSet !== null && self::of($fresh, $unlessSet) !== null) {
            return;
        }

        $save = Container::getInstance()->make(Seo::class)->userLocaleSaver();

        if ($save === null) {
            $fresh->forceFill([$column => $code])->save();
            $user->setAttribute($column, $fresh->getAttribute($column));
        } else {
            $save($user, $code);
            $user->setAttribute($column, $code);
        }

        $user->syncOriginalAttribute($column);
    }

    private static function column(): ?string
    {
        $column = Container::getInstance()->make('config')->get('seo.user_locale');

        return is_string($column) && $column !== '' ? $column : null;
    }
}
