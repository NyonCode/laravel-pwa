<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Requests;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use NyonCode\LaravelPwa\Push\WebPush;

/**
 * POST / DELETE {route_prefix}/push/subscriptions, the JSON of PushSubscription.toJSON():
 * { endpoint, expirationTime, keys: { p256dh, auth } } plus contentEncoding and, from the
 * worker's pushsubscriptionchange, the endpoint it replaces.
 *
 * @internal
 */
class PushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $endpoint = ['required', 'string', 'max:2048', $this->allowedEndpoint(...)];

        if ($this->isMethod('DELETE')) {
            return ['endpoint' => ['required', 'string', 'max:2048']];
        }

        return [
            'endpoint' => $endpoint,
            'expirationTime' => ['nullable', 'integer', 'min:0'],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
            'replaces' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * The signed-in owner of the subscription.
     */
    public function owner(): Model
    {
        $user = $this->user();

        if (! $user instanceof Model) {
            throw new LogicException('Push subscriptions belong to Eloquent models; the signed-in user is '.get_debug_type($user).'.');
        }

        return $user;
    }

    public function endpoint(): string
    {
        return $this->string('endpoint')->toString();
    }

    protected function allowedEndpoint(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(WebPush::class)->allows($value)) {
            $fail('The endpoint is not a URL of an allowed push service.');
        }
    }
}
