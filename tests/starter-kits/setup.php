<?php

/*
 * Applies the documented setup (docs/en/installation.md, docs/en/recipes.md, docs/en/push.md) to a
 * fresh Laravel starter kit: php tests/starter-kits/setup.php <app-dir>
 *
 * Run by scripts/starter-kit.sh after composer require. Each edit must change its file, so a kit
 * whose layout or routes moved fails here instead of passing a smoke test without the package.
 */

declare(strict_types=1);

$app = rtrim($argv[1] ?? '', '/');

if ($app === '' || ! is_file($app.'/artisan')) {
    fwrite(STDERR, "Usage: php tests/starter-kits/setup.php <app-dir>\n");
    exit(1);
}

$edit = static function (string $file, callable $change) use ($app): void {
    $path = $app.'/'.$file;
    $before = (string) file_get_contents($path);
    $after = $change($before);

    if (! is_string($after) || $after === $before) {
        throw new RuntimeException("The setup step did not change {$file}: the starter kit changed, update tests/starter-kits/setup.php.");
    }

    file_put_contents($path, $after);
    fwrite(STDOUT, "  edited {$file}\n");
};

// 2. Layout: <x-pwa::head/> in the shared <head>.
if (is_file($app.'/resources/views/app.blade.php')) {
    // Inertia (React, Vue): the root view, before the Vite assets.
    $edit('resources/views/app.blade.php', static fn (string $s): ?string => preg_replace('/^(\s*)@vite\(/m', "$1<x-pwa::head/>\n$1@vite(", $s, 1));
} else {
    // Livewire: the head partial every layout includes.
    $edit('resources/views/partials/head.blade.php', static fn (string $s): string => rtrim($s)."\n<x-pwa::head/>\n");
}

// 4. Routes: the dashboard works offline.
$edit('routes/web.php', static fn (string $s): string => str_replace("->name('dashboard')", "->middleware('pwa.cache')->name('dashboard')", $s));

// 5. Maintenance mode exceptions.
$edit('bootstrap/app.php', static fn (string $s): ?string => preg_replace(
    '/(->withMiddleware\(function \(Middleware \$middleware\)(?:: void)? \{\n)/',
    "$1        \$middleware->preventRequestsDuringMaintenance(except: ['manifest.webmanifest', 'sw.js', 'pwa/*']);\n",
    $s,
    1,
));

// Web push: the user model receives notifications.
$edit('app/Models/User.php', static fn (string $s): ?string => preg_replace(
    '/(class User extends [^\n]*\n\{\n)/',
    "$1    use \\NyonCode\\LaravelPwa\\Push\\HasPushSubscriptions;\n\n",
    $s,
    1,
));

// The service worker is off in the local environment by default; the push keys come from pwa:push:vapid.
$edit('.env', static fn (string $s): string => rtrim($s)."\n\nPWA_ENABLED=true\nPWA_PUSH_ENABLED=true\nPWA_VAPID_SUBJECT=mailto:ci@example.test\n");

// 3. Icons: a generated source image (pwa:icons runs in scripts/starter-kit.sh).
@mkdir($app.'/resources/pwa', 0o755, true);
$image = imagecreatetruecolor(1024, 1024);
imagefilledrectangle($image, 0, 0, 1023, 1023, (int) imagecolorallocate($image, 79, 70, 229));
imagefilledellipse($image, 512, 512, 520, 520, (int) imagecolorallocate($image, 255, 255, 255));
imagepng($image, $app.'/resources/pwa/icon.png');
fwrite(STDOUT, "  wrote resources/pwa/icon.png\n");
