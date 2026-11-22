#!/usr/bin/env bash
#
# Installs this package into a fresh Laravel starter kit and runs the smoke test against it.
#
#   scripts/starter-kit.sh <livewire|react|vue> [laravel-major] [app-dir]
#
# The kit comes from Packagist (composer create-project), the package from this checkout (a path
# repository). The setup follows the documentation (tests/starter-kits/setup.php), then pwa:doctor --ci
# and tests/starter-kits/smoke.spec.mjs run against `php artisan serve`. CI: .github/workflows/starter-kits.yml.
#
set -euo pipefail

kit="${1:?Usage: scripts/starter-kit.sh <livewire|react|vue> [laravel-major] [app-dir]}"
laravel="${2:-12}"
root="$(cd "$(dirname "$0")/.." && pwd)"
# Outside the checkout: the app symlinks the package, tools scanning the checkout must not see the app.
app="${3:-${TMPDIR:-/tmp}/laravel-pwa-kits/$kit-$laravel}"
port="${KIT_PORT:-8400}"
composer=(composer --no-interaction --no-progress)

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

step "laravel/$kit-starter-kit → $app"
rm -rf "$app"
mkdir -p "$(dirname "$app")"
"${composer[@]}" create-project "laravel/$kit-starter-kit" "$app" --prefer-dist
cd "$app"

if [ "$laravel" != "12" ]; then
  step "Laravel $laravel"
  "${composer[@]}" require "laravel/framework:^$laravel.0" "laravel/tinker:^3.0" --with-all-dependencies
fi

step "composer require nyoncode/laravel-pwa (this checkout)"
composer config repositories.pwa "{\"type\":\"path\",\"url\":\"$root\",\"options\":{\"symlink\":true}}"
"${composer[@]}" require "nyoncode/laravel-pwa:@dev" minishlink/web-push
php artisan pwa:install --no-interaction

step "Documented setup"
php "$root/tests/starter-kits/setup.php" "$app"
sed -i.bak "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$port#" .env && rm .env.bak
php artisan pwa:push:vapid --no-interaction
php artisan vendor:publish --tag=pwa::migrations
php artisan migrate:fresh --seed --force
php artisan pwa:icons resources/pwa/icon.png

step "Build"
npm install --no-audit --no-fund --loglevel=error
npm run build
php artisan pwa:build

step "pwa:doctor --ci"
php artisan pwa:doctor --ci

step "Smoke test"
php artisan serve --host=127.0.0.1 --port="$port" > "$app/storage/logs/serve.log" 2>&1 &
server=$!
trap 'kill $server 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf -o /dev/null "http://127.0.0.1:$port/up" && break; sleep 1; done

cd "$root"
KIT="$kit" KIT_URL="http://127.0.0.1:$port" KIT_APP="$app" npx playwright test --config=tests/starter-kits/playwright.config.mjs
