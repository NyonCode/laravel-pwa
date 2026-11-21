<?php

use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Pwa;

afterEach(function () {
    File::delete(storage_path('framework/down'));
    File::delete(storage_path('framework/maintenance.php'));
});

it('puts the app down with the pwa maintenance screen', function () {
    $this->artisan('pwa:down', ['--message' => 'Nasazujeme novou verzi', '--retry' => 120])->assertSuccessful();

    $payload = json_decode(File::get(storage_path('framework/down')), true);

    expect($payload['retry'])->toBe(120)
        ->and($payload['template'])->toContain('Nasazujeme novou verzi')
        ->toContain('data-retry="120"')
        ->toContain('id="pwa-maintenance"');
});

it('computes the expected end from --until', function () {
    $this->travelTo(now()->setTime(14, 0));

    $this->artisan('pwa:down', ['--until' => '14:30'])->assertSuccessful();

    $payload = json_decode(File::get(storage_path('framework/down')), true);

    expect($payload['retry'])->toBe(300) // omezeno na 300 s
        ->and($payload['template'])->toContain('data-until="'.now()->setTime(14, 30)->toIso8601String().'"');
});

it('provides stable csp hashes for the prerendered screen', function () {
    $first = app(Pwa::class)->cspHashes();
    $this->travel(5)->minutes();
    $second = app(Pwa::class)->cspHashes();

    expect($first)->toBe($second)
        ->and($first['script-src'])->toHaveCount(1)
        ->and($first['style-src'])->toHaveCount(1)
        ->and($first['script-src'][0])->toStartWith("'sha256-");
});

it('rejects an invalid --until', function () {
    $this->artisan('pwa:down', ['--until' => 'kdysi'])->assertFailed();
});
