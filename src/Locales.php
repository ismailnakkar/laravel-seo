<?php

declare(strict_types=1);

namespace Seo;

use InvalidArgumentException;

/** The languages Route::localized() serves. The default's URL is the bare one, and also x-default. */
final readonly class Locales
{
    /**
     * @param  list<string>  $codes  each is at once the hreflang value, URL segment (/zh-Hant) and app locale
     *                               (lang/zh-Hant): ISO 639-1[-ISO 15924][-ISO 3166-1 alpha-2], shape-checked only
     * @param  string  $default  the bare URL's locale and x-default. Pass a constant, never config('app.locale'):
     *                           Application::setLocale() overwrites that key.
     *
     * @throws InvalidArgumentException empty or duplicate codes; a malformed code; default not in codes
     */
    public function __construct(public array $codes, public string $default)
    {
        foreach ($codes as $code) {
            if (! is_string($code) || preg_match('/^[a-z]{2}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/D', $code) !== 1) {
                throw new InvalidArgumentException('Locales::$codes: [' . (is_string($code) ? $code : get_debug_type($code)) . '] is not an hreflang code like en, en-GB or zh-Hant.');
            }
        }

        if ($codes === [] || count(array_unique($codes)) !== count($codes)) {
            throw new InvalidArgumentException('Locales::$codes must be non-empty and hold no duplicates.');
        }

        if (! in_array($default, $codes, true)) {
            throw new InvalidArgumentException("Locales::\$default [{$default}] is not one of the codes.");
        }
    }
}
