<?php

declare(strict_types=1);

namespace Seo\Testing;

use InvalidArgumentException;

/** Site::robotsTxt() output as a crawler reads it: groups split by blank lines, Disallow rules only. */
final readonly class RobotsMatcher
{
    /** @var array<string, list<string>> lower-cased token => Disallow regexes */
    private array $groups;

    public function __construct(public string $body)
    {
        $groups = [];

        foreach (explode("\n\n", $body) as $block) {
            preg_match_all('/^user-agent:[ \t]*(\S+)/mi', $block, $agents);
            preg_match_all('/^disallow:[ \t]*(\S+)/mi', $block, $rules);

            foreach ($agents[1] as $agent) {
                $groups[strtolower($agent)] = array_map(self::regex(...), $rules[1]);
            }
        }

        $this->groups = $groups;
    }

    /**
     * The token's group, else `*`: any Disallow prefix matching path+query blocks. /robots.txt is always allowed.
     *
     * @param  string  $token  a product token, e.g. 'Googlebot-Image'
     *
     * @throws InvalidArgumentException $token is not a product token: a full user-agent string would silently read `*`;
     *                                  $path does not start with '/': a URL would match nothing
     */
    public function allows(string $token, string $path): bool
    {
        if (preg_match('/^[A-Za-z_-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException("RobotsMatcher::allows(): [{$token}] is not a product token such as Googlebot.");
        }

        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException("RobotsMatcher::allows(): [{$path}] is not a path starting with /.");
        }

        $path = self::encode($path);

        return explode('?', $path, 2)[0] === '/robots.txt'
            || ! array_any($this->groups[strtolower($token)] ?? $this->groups['*'] ?? [], static fn (string $regex): bool => preg_match($regex, $path) === 1);
    }

    /** `*` is any sequence; only a trailing `$` anchors. */
    private static function regex(string $pattern): string
    {
        $anchored = str_ends_with($pattern, '$');
        $parts = explode('*', self::encode($anchored ? substr($pattern, 0, -1) : $pattern));

        return '~^' . implode('.*', array_map(static fn (string $part): string => preg_quote($part, '~'), $parts)) . ($anchored ? '\z' : '') . '~';
    }

    /** One spelling on both sides: non-ASCII octets escaped, hex upper-cased. */
    private static function encode(string $value): string
    {
        return (string)preg_replace_callback('/%[0-9a-f]{2}|[\x80-\xFF]/i', static fn (array $m): string => strlen($m[0]) === 3 ? strtoupper($m[0]) : sprintf('%%%02X', ord($m[0])), $value);
    }
}
