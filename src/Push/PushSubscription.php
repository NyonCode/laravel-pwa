<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One browser's push subscription (one device, one browser profile, one worker scope).
 *
 * @property int $id
 * @property string $subscribable_type
 * @property int|string $subscribable_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string|null $user_agent
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $subscribable
 */
class PushSubscription extends Model
{
    use MassPrunable;

    protected $fillable = [
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
        'expires_at',
        'last_used_at',
    ];

    protected $hidden = ['public_key', 'auth_token'];

    public function getTable(): string
    {
        return config()->string('pwa.push.table', 'pwa_push_subscriptions');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The user (or any other model) that receives the notifications.
     *
     * @return MorphTo<Model, $this>
     */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Endpoints are long URLs; the unique index uses their SHA-256.
     */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Subscriptions past the expirationTime the browser announced, and those unused for
     * pwa.push.prune_after_days days: no successful delivery and no visit of the app, which confirms
     * its subscription once a day (php artisan pwa:push:prune or model:prune).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        // Missing from a config published before 1.1: expired subscriptions only, as before.
        $days = config('pwa.push.prune_after_days');
        $before = is_numeric($days) && (int) $days > 0 ? now()->subDays((int) $days) : null;

        return static::query()->where(static function (Builder $query) use ($before): void {
            $query->where(static fn (Builder $expired): Builder => $expired->whereNotNull('expires_at')->where('expires_at', '<', now()));

            if ($before !== null) {
                $query->orWhere('last_used_at', '<', $before)
                    ->orWhere(static fn (Builder $never): Builder => $never->whereNull('last_used_at')->where('created_at', '<', $before));
            }
        });
    }
}
