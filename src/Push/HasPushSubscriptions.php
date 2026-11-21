<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * For notifiable models (usually User): their devices and the route for WebPushChannel.
 *
 * @mixin Model
 */
trait HasPushSubscriptions
{
    public static function bootHasPushSubscriptions(): void
    {
        static::deleted(function (Model $model): void {
            // Soft deletes keep the devices until the model is really gone.
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            if (method_exists($model, 'pushSubscriptions')) {
                $model->pushSubscriptions()->delete();
            }
        });
    }

    /**
     * @return MorphMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): MorphMany
    {
        return $this->morphMany(WebPush::subscriptionModel(), 'subscribable');
    }

    /**
     * @return Collection<int, PushSubscription>
     */
    public function routeNotificationForWebPush(): Collection
    {
        return $this->pushSubscriptions()->get();
    }
}
