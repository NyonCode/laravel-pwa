# Upgrade

Před verzí 1.0 může každá minor verze měnit formát cache nebo service workeru. Postup při
každé aktualizaci:

1. `composer update nyoncode/laravel-pwa`
2. Porovnejte `config/pwa.php` s `vendor/nyoncode/laravel-pwa/config/pwa.php`.
3. `npm run build && php artisan pwa:build`
4. `php artisan pwa:doctor`

Změny formátu cache řeší service worker v události `activate` (staré cache maže sám).
