<?php

use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Manifest\DisplayStrategy;

it('falls back to override for an unknown strategy', function () {
    config(['pwa.display.strategy' => 'nonsense']);

    expect(DisplayStrategy::fromConfig())->toBe(DisplayStrategy::Override);
});

it('detects desktops', function (array $server, bool $desktop) {
    $request = Request::create('/', 'GET', server: $server);

    expect(DisplayStrategy::isDesktop($request))->toBe($desktop);
})->with([
    [['HTTP_SEC_CH_UA_PLATFORM' => '"Windows"'], true],
    [['HTTP_SEC_CH_UA_PLATFORM' => '"Chrome OS"'], true],
    [['HTTP_SEC_CH_UA_PLATFORM' => '"Android"'], false],
    [['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64)'], true],
    [['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X)'], false],
    [['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'], false],
]);
