<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use LogicException;

/** What Route::localized() stamped on a route: its group's locales and the locale this copy serves. */
final readonly class LocalizedRoute
{
    /** @internal Route-action key. Its value stays a plain array so route:cache can var_export it. */
    public const string ACTION = 'seo_locale';

    public function __construct(public Locales $locales, public string $locale) {}

    /** null outside Route::localized() */
    public static function of(?Route $route): ?self
    {
        $marker = $route?->getAction(self::ACTION);

        return is_array($marker) ? new self(new Locales($marker['codes'], $marker['default']), $marker['locale']) : null;
    }

    /**
     * This copy's $path as $code's path. On the fr copy, default en: ('/fr/terms', 'ar') → '/ar/terms';
     * ('/fr', 'en') → '/'; ('/%66r%2Fterms', 'fr') → '/fr/terms'. $path is '/'-prefixed, as path info gives it.
     *
     * @throws LogicException $path is not a path of this copy
     */
    public function path(string $path, string $code): string
    {
        $base = $path;

        if ($this->locale !== $this->locales->default) {
            // The router matches the decoded path (/%66r/terms, /fr%2Fterms): strip the prefix as it decodes,
            // keep the rest as spelt.
            preg_match('~^(?:%[0-9A-Fa-f]{2}|.){' . (strlen($this->locale) + 1) . '}~s', $path, $prefix);
            $rest = (string)preg_replace('~^%2F~i', '/', substr($path, strlen($prefix[0] ?? '')));

            if (rawurldecode($prefix[0] ?? '') !== '/' . $this->locale || ! in_array(substr($rest, 0, 1), ['', '/'], true)) {
                throw new LogicException("[{$path}] is not a path of the {$this->locale} copy.");
            }

            $base = $rest ?: '/';
        }

        // One leading slash: a catch-all's `/fr//host` would otherwise leave `//host`, which a browser reads as a host.
        return $code === $this->locales->default ? '/' . ltrim($base, '/') : '/' . $code . rtrim($base, '/');
    }

    /**
     * $route's own name, as its default copy has it: `seo.{locale}.` stripped on a non-default copy, never touched
     * on the default (the package never prefixes it). null once that leaves nothing: an unnamed route.
     */
    public function name(Route $route): ?string
    {
        $name = (string)$route->getName();

        if ($this->locale !== $this->locales->default) {
            $name = Str::replaceFirst("seo.{$this->locale}.", '', $name);
        }

        return $name === '' ? null : $name;
    }

    /** $route's URI with this copy's own locale segment gone, trimmed of '/': the shape every copy shares. */
    public function unprefixedUri(Route $route): string
    {
        $uri = trim($route->uri(), '/');

        if ($this->locale === $this->locales->default) {
            return $uri;
        }

        return $uri === $this->locale ? '' : Str::after($uri, "{$this->locale}/");
    }
}
