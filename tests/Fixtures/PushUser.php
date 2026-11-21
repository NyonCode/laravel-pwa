<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use NyonCode\LaravelPwa\Push\HasPushSubscriptions;

class PushUser extends User
{
    use HasPushSubscriptions;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public static function make(string $name): self
    {
        return self::query()->create(['name' => $name, 'email' => strtolower($name).'@pwa.test', 'password' => 'secret']);
    }
}
