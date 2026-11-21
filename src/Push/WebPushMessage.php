<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * A notification for WebPushChannel, returned from your notification's toWebPush():
 *
 *     return (new WebPushMessage)
 *         ->title('Order shipped')
 *         ->body("Order {$this->order->number} is on its way.")
 *         ->url(route('orders.show', $this->order))
 *         ->tag("order-{$this->order->id}");
 */
class WebPushMessage
{
    /** What push services accept after encryption (RFC 8291: 4096 bytes minus overhead). */
    public const MAX_PAYLOAD = 4078;

    public const URGENCIES = ['very-low', 'low', 'normal', 'high'];

    protected string $title = '';

    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var array<string, mixed> */
    protected array $data = [];

    protected ?string $url = null;

    /** @var list<array{action: string, title: string, icon?: string, url?: string}> */
    protected array $actions = [];

    protected ?int $appBadge = null;

    protected bool $declarative = false;

    protected bool $private = true;

    protected ?int $ttl = null;

    protected ?string $urgency = null;

    protected ?string $topic = null;

    final public function __construct() {}

    public static function create(string $title = ''): static
    {
        return (new static)->title($title);
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): static
    {
        return $this->option('body', $body);
    }

    public function icon(string $url): static
    {
        return $this->option('icon', $url);
    }

    /** Small monochrome icon in the Android status bar. */
    public function badge(string $url): static
    {
        return $this->option('badge', $url);
    }

    public function image(string $url): static
    {
        return $this->option('image', $url);
    }

    /** Page opened on click: a path or a URL of this app. Other origins open the app's scope. */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /** Replaces an earlier notification with the same tag; renotify plays the sound again. */
    public function tag(string $tag, bool $renotify = false): static
    {
        $this->option('tag', $tag);

        return $renotify ? $this->option('renotify', true) : $this;
    }

    public function requireInteraction(bool $value = true): static
    {
        return $this->option('requireInteraction', $value);
    }

    public function silent(bool $value = true): static
    {
        return $this->option('silent', $value);
    }

    public function lang(string $lang): static
    {
        return $this->option('lang', $lang);
    }

    /** @param  'auto'|'ltr'|'rtl'  $dir */
    public function dir(string $dir): static
    {
        return $this->option('dir', $dir);
    }

    public function timestamp(DateTimeInterface $at): static
    {
        return $this->option('timestamp', (int) $at->format('Uv'));
    }

    /** A button; `url` is opened when it is clicked (otherwise the message url). */
    public function action(string $action, string $title, ?string $url = null, ?string $icon = null): static
    {
        $this->actions[] = array_filter(
            ['action' => $action, 'title' => $title, 'url' => $url, 'icon' => $icon],
            static fn (?string $value): bool => $value !== null,
        );

        return $this;
    }

    /**
     * Extra data for your own sw.extend code (event.notification.data).
     *
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): static
    {
        $this->data = [...$this->data, ...$data];

        return $this;
    }

    /** Number on the app icon (Badging API), 0 clears it. */
    public function appBadge(int $count): static
    {
        $this->appBadge = max(0, $count);

        return $this;
    }

    /**
     * Declarative Web Push (Safari 18.4+, macOS 15.5+): the browser shows it without running the
     * worker. Requires a url. The per-user check of the worker does not apply.
     */
    public function declarative(bool $value = true): static
    {
        $this->declarative = $value;

        return $this;
    }

    /**
     * By default a device where another user is signed in shows only a neutral notification.
     * Call public() for messages that may be shown to anyone on the device.
     */
    public function public(): static
    {
        $this->private = false;

        return $this;
    }

    /** Seconds the push service keeps the message while the device is offline. */
    public function ttl(int $seconds): static
    {
        $this->ttl = max(0, $seconds);

        return $this;
    }

    /** One of very-low, low, normal, high. */
    public function urgency(string $urgency): static
    {
        if (! in_array($urgency, self::URGENCIES, true)) {
            throw new InvalidArgumentException("Invalid push urgency [{$urgency}], expected one of: ".implode(', ', self::URGENCIES).'.');
        }

        $this->urgency = $urgency;

        return $this;
    }

    /** A newer message with the same topic replaces an undelivered one (max 32 URL-safe characters). */
    public function topic(string $topic): static
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $topic) !== 1) {
            throw new InvalidArgumentException("Invalid push topic [{$topic}], expected 1–32 characters A-Z, a-z, 0-9, - or _.");
        }

        $this->topic = $topic;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function isDeclarative(): bool
    {
        return $this->declarative;
    }

    /**
     * Delivery options for the push service (TTL, urgency, topic).
     *
     * @return array{TTL: int, urgency: string, topic?: string}
     */
    public function deliveryOptions(): array
    {
        $urgency = $this->urgency ?? config()->string('pwa.push.urgency', 'normal');

        return array_filter([
            'TTL' => $this->ttl ?? config()->integer('pwa.push.ttl', 60 * 60 * 24 * 28),
            'urgency' => in_array($urgency, self::URGENCIES, true) ? $urgency : 'normal',
            'topic' => $this->topic,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    /**
     * What the worker receives. $user is the recipient's Pwa::identityHash(), null when the
     * recipient is not a signed-in user (a team, a channel).
     *
     * @return array<string, mixed>
     */
    public function toPayload(?string $user = null): array
    {
        if ($this->title === '') {
            throw new InvalidArgumentException('A push notification needs a title.');
        }

        return $this->declarative ? $this->declarativePayload() : array_filter([
            'pwa' => 1,
            'title' => $this->title,
            'user' => $this->private ? $user : null,
            'appBadge' => $this->appBadge,
            'options' => [
                ...$this->options,
                'actions' => array_map(static fn (array $action): array => array_diff_key($action, ['url' => true]), $this->actions),
                'data' => [
                    ...$this->data,
                    'url' => $this->url,
                    'actions' => array_column(array_filter($this->actions, static fn (array $a): bool => isset($a['url'])), 'url', 'action'),
                ],
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * JSON as sent, checked against the payload limit.
     */
    public function toJson(?string $user = null): string
    {
        $json = json_encode($this->toPayload($user), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (strlen($json) > self::MAX_PAYLOAD) {
            throw new InvalidArgumentException('The push payload has '.strlen($json).' bytes, push services accept at most '.self::MAX_PAYLOAD.'. Shorten the body or move data to the page.');
        }

        return $json;
    }

    protected function option(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function declarativePayload(): array
    {
        $url = $this->url;

        if ($url === null) {
            throw new InvalidArgumentException('A declarative push notification needs a url().');
        }

        $notification = array_filter([
            'title' => $this->title,
            'body' => $this->options['body'] ?? null,
            'navigate' => URL::to($url),
            'lang' => $this->options['lang'] ?? null,
            'dir' => $this->options['dir'] ?? null,
            'silent' => $this->options['silent'] ?? null,
            'tag' => $this->options['tag'] ?? null,
            'actions' => array_map(static fn (array $action): array => [
                'action' => $action['action'],
                'title' => $action['title'],
                'navigate' => URL::to($action['url'] ?? $url),
            ], $this->actions) ?: null,
        ], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'web_push' => 8030,
            'notification' => $notification,
            'app_badge' => $this->appBadge !== null ? (string) $this->appBadge : null,
            // Browsers with a worker still pass it the push event (icon, badge, data).
            'mutable' => true,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
