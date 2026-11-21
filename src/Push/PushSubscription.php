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
     * Subscriptions past the expirationTime the browser announced (php artisan model:prune).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
