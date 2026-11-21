<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\ServiceWorker;

use InvalidArgumentException;

/**
 * One caching rule of the service worker: which GET requests it matches and how they are cached.
 *
 *     Pwa::serviceWorker()->cache('/api/catalog*')->staleWhileRevalidate()->maxEntries(200)->maxAge(days: 1);
 *
 * Rules apply to same-origin GET requests that are not navigations; HTML pages are cached only
 * with the server's consent (X-PWA-Cache: allow). The first matching rule wins.
 */
final class CacheRule
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    public const DESTINATIONS = ['image', 'font', 'style', 'script', 'audio', 'video', 'track', 'manifest', 'worker', 'document', ''];

    protected CacheStrategy $strategy = CacheStrategy::NetworkFirst;

    protected ?string $name = null;

    protected ?int $maxEntries = null;

    protected ?int $maxAge = null;

    protected ?int $timeout = null;

    protected bool $shared = false;

    /** @var list<string> */
    protected array $destinations = [];

    public function __construct(
        protected ?string $match = null,
    ) {}

    /**
     * Path pattern with `*` wildcards, matched against the URL path (without the query string).
     */
    public static function for(?string $match): self
    {
        return new self($match);
    }

    /**
     * Builds a rule from a `pwa.cache.rules` entry.
     *
     * @param  array<array-key, mixed>  $rule
     */
    public static function fromArray(array $rule): self
    {
        $known = ['match', 'strategy', 'cache', 'max_entries', 'max_age', 'timeout', 'shared', 'destination'];
        $unknown = array_diff(array_keys($rule), $known);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown cache rule key(s) ['.implode(', ', $unknown).']. Allowed: '.implode(', ', $known).'.');
        }

        $match = $rule['match'] ?? null;

        if ($match !== null && ! is_string($match)) {
            throw new InvalidArgumentException('Cache rule [match] must be a path pattern string.');
        }

        $instance = new self($match);

        $strategy = $rule['strategy'] ?? CacheStrategy::NetworkFirst;
        $instance->strategy($strategy instanceof CacheStrategy ? $strategy : self::strategyFrom($strategy));

        if (isset($rule['cache'])) {
            $instance->name(self::string($rule['cache'], 'cache'));
        }

        if (isset($rule['max_entries'])) {
            $instance->maxEntries(self::int($rule['max_entries'], 'max_entries'));
        }

        if (isset($rule['max_age'])) {
            $instance->maxAge(self::int($rule['max_age'], 'max_age'));
        }

        if (isset($rule['timeout'])) {
            $instance->timeout(self::int($rule['timeout'], 'timeout'));
        }

        if (isset($rule['shared'])) {
            $instance->shared(filter_var($rule['shared'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($rule['destination'])) {
            $destinations = is_array($rule['destination']) ? $rule['destination'] : [$rule['destination']];
            $instance->destination(...array_map(static fn (mixed $d): string => self::string($d, 'destination'), array_values($destinations)));
        }

        return $instance->validate();
    }

    public function strategy(CacheStrategy|string $strategy): self
    {
        $this->strategy = $strategy instanceof CacheStrategy ? $strategy : self::strategyFrom($strategy);

        return $this;
    }

    /**
     * @param  int|null  $timeout  seconds before a cached copy is used while the network is slow
     */
    public function networkFirst(?int $timeout = null): self
    {
        if ($timeout !== null) {
            $this->timeout($timeout);
        }

        return $this->strategy(CacheStrategy::NetworkFirst);
    }

    public function cacheFirst(): self
    {
        return $this->strategy(CacheStrategy::CacheFirst);
    }

    public function staleWhileRevalidate(): self
    {
        return $this->strategy(CacheStrategy::StaleWhileRevalidate);
    }

    public function networkOnly(): self
    {
        return $this->strategy(CacheStrategy::NetworkOnly);
    }

    public function cacheOnly(): self
    {
        return $this->strategy(CacheStrategy::CacheOnly);
    }

    /**
     * Cache name suffix: lowercase letters, digits and dashes. Rules may share a name.
     */
    public function name(string $name): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Cache name [{$name}] must match ".self::NAME_PATTERN.'.');
        }

        $this->name = $name;

        return $this;
    }

    public function maxEntries(int $entries): self
    {
        if ($entries < 1) {
            throw new InvalidArgumentException('Cache rule [max_entries] must be at least 1.');
        }

        $this->maxEntries = $entries;

        return $this;
    }

    public function maxAge(int $seconds = 0, int $minutes = 0, int $hours = 0, int $days = 0): self
    {
        $total = $seconds + $minutes * 60 + $hours * 3600 + $days * 86400;

        if ($total < 1) {
            throw new InvalidArgumentException('Cache rule [max_age] must be at least 1 second.');
        }

        $this->maxAge = $total;

        return $this;
    }

    /**
     * Network-first only: seconds to wait for the network before a cached copy is served.
     */
    public function timeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Cache rule [timeout] must be at least 1 second.');
        }

        $this->timeout = $seconds;

        return $this;
    }

    /**
     * One cache for every user instead of one per user. The worker then stores only responses that
     * are not `Cache-Control: private` and carry no signed-in user (`X-PWA-User` missing or `guest`).
     */
    public function shared(bool $shared = true): self
    {
        $this->shared = $shared;

        return $this;
    }

    /**
     * Restrict the rule to request destinations (Request.destination), e.g. `image`, `font`.
     */
    public function destination(string ...$destinations): self
    {
        foreach ($destinations as $destination) {
            if (! in_array($destination, self::DESTINATIONS, true)) {
                throw new InvalidArgumentException("Unknown request destination [{$destination}]. Allowed: ".implode(', ', array_filter(self::DESTINATIONS)).'.');
            }
        }

        $this->destinations = array_values(array_unique([...$this->destinations, ...$destinations]));

        return $this;
    }

    public function validate(): self
    {
        if (($this->match === null || $this->match === '') && $this->destinations === []) {
            throw new InvalidArgumentException('A cache rule needs a path pattern [match], a [destination], or both.');
        }

        if ($this->match !== null && $this->match !== '' && ! str_starts_with($this->match, '/') && $this->match !== '*') {
            throw new InvalidArgumentException("Cache rule pattern [{$this->match}] must start with '/'.");
        }

        if ($this->timeout !== null && $this->strategy !== CacheStrategy::NetworkFirst) {
            throw new InvalidArgumentException("Cache rule [timeout] only applies to network-first, [{$this->strategy->value}] given.");
        }

        return $this;
    }

    public function match(): ?string
    {
        return $this->match;
    }

    public function getStrategy(): CacheStrategy
    {
        return $this->strategy;
    }

    /**
     * Explicit name, or a stable one derived from the pattern and destinations.
     */
    public function getName(): string
    {
        return $this->name ?? 'r'.substr(hash('sha256', ($this->match ?? '').'|'.implode(',', $this->destinations)), 0, 8);
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    /**
     * What the service worker receives in self.__PWA.rules.
     *
     * @return array{match: string|null, strategy: string, cache: string, shared: bool, maxEntries: int|null, maxAge: int|null, timeout: int|null, destinations: list<string>}
     */
    public function toArray(): array
    {
        $this->validate();

        return [
            'match' => $this->match === '' ? null : $this->match,
            'strategy' => $this->strategy->value,
            'cache' => $this->getName(),
            'shared' => $this->shared,
            'maxEntries' => $this->maxEntries,
            'maxAge' => $this->maxAge,
            'timeout' => $this->timeout,
            'destinations' => $this->destinations,
        ];
    }

    protected static function strategyFrom(mixed $value): CacheStrategy
    {
        $strategy = is_string($value) ? CacheStrategy::tryFrom($value) : null;

        if ($strategy === null) {
            $allowed = implode(', ', array_map(static fn (CacheStrategy $s): string => $s->value, CacheStrategy::cases()));

            throw new InvalidArgumentException('Unknown cache strategy ['.(is_scalar($value) ? $value : get_debug_type($value))."]. Allowed: {$allowed}.");
        }

        return $strategy;
    }

    protected static function string(mixed $value, string $key): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Cache rule [{$key}] must be a string.");
        }

        return $value;
    }

    protected static function int(mixed $value, string $key): int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw new InvalidArgumentException("Cache rule [{$key}] must be an integer.");
        }

        return (int) $value;
    }
}
