<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * The languages Route::localized() serves. The default's URL is the bare one, and also x-default.
 *
 * @internal
 */
final readonly class Locales
{
    /**
     * @param  list<string>  $codes  each is at once the hreflang value, URL segment (/zh-Hant) and app locale
     *                               (lang/zh-Hant): ISO 639-1[-ISO 15924][-ISO 3166-1 alpha-2], shape-checked only
     * @param  string  $default  the bare URL's locale and x-default. Pass a constant, never config('app.locale'):
     *                           Application::setLocale() overwrites that key.
     *
     * @throws InvalidArgumentException a malformed or duplicate code; default not in codes, as with none
     */
    public function __construct(public array $codes, public string $default)
    {
        foreach ($codes as $i => $code) {
            if (! is_string($code) || preg_match('/^[a-z]{2}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/D', $code) !== 1) {
                throw new InvalidArgumentException('seo.locales: [' . (is_string($code) ? $code : get_debug_type($code)) . '] is not an hreflang code like en, en-GB or zh-Hant.');
            }

            if (array_search($code, $codes, true) !== $i) {
                throw new InvalidArgumentException("seo.locales: [{$code}] is listed twice.");
            }
        }

        if (! in_array($default, $codes, true)) {
            throw new InvalidArgumentException("seo.locales: the default [{$default}] is not one of the codes.");
        }
    }

    /**
     * config('seo.locales'), its first code the default; null below two, where the language features are off.
     *
     * @throws InvalidArgumentException not a list of hreflang codes; a duplicate code
     */
    public static function configured(): ?self
    {
        $locales = Container::getInstance()->make('config')->get('seo.locales');

        if (blank($locales) || $locales === false) {
            return null;
        }

        // Integer keys, gaps and all (array_filter()); a code => name map is refused, not guessed at.
        if (! is_array($locales) || ! array_all($locales, static fn (mixed $code, int|string $key): bool => is_int($key) && is_string($code))) {
            throw new InvalidArgumentException("seo.locales: list the codes only, default first, e.g. ['en', 'fr']; label them with your own translations.");
        }

        $codes = array_values($locales);

        return count($codes) < 2 ? null : new self($codes, $codes[0]);
    }

    /**
     * The browser's first language that matches: exactly (any case, `_` = `-`), else by its primary language
     * (fr-CA → fr), the bare code first (pt over pt-BR). Symfony's getPreferredLanguage() returns its own spelling
     * (en_GB), never a configured code. null: nothing matches.
     */
    public function preferredBy(Request $request): ?string
    {
        $codes = [];

        foreach ($this->codes as $code) {
            $codes[strtolower($code)] = $code;
        }

        foreach ($request->getLanguages() as $language) {
            $language = strtolower(str_replace('_', '-', $language));
            $primary = explode('-', $language)[0];

            $match = $codes[$language]
                ?? $codes[$primary]
                ?? Arr::first($codes, static fn (string $code, string $lower): bool => str_starts_with($lower, "{$primary}-"));

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }
}
