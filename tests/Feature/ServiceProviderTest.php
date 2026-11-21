<?php

use NyonCode\LaravelPwa\PwaServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProviders(PwaServiceProvider::class))->not->toBeEmpty();
});
