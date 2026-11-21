<?php

use NyonCode\LaravelPwa\PwaServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProviders(PwaServiceProvider::class))->not->toBeEmpty();
});
