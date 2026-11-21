<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use RuntimeException;

/**
 * VAPID keys (RFC 8292): a P-256 key pair, both halves base64url encoded as browsers expect
 * them (public key uncompressed, 65 bytes; private key 32 bytes).
 *
 * @internal
 */
final class Vapid
{
    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (! is_array($details) || ! isset($details['ec']) || ! is_array($details['ec'])) {
            throw new RuntimeException('OpenSSL could not create a P-256 key: '.(openssl_error_string() ?: 'unknown error'));
        }

        /** @var array{x: string, y: string, d: string} $ec */
        $ec = $details['ec'];

        return [
            'publicKey' => self::encode("\x04".self::pad($ec['x']).self::pad($ec['y'])),
            'privateKey' => self::encode(self::pad($ec['d'])),
        ];
    }

    /**
     * What is wrong with the configured keys, or null when they look valid.
     */
    public static function problem(mixed $publicKey, mixed $privateKey, mixed $subject): ?string
    {
        if (! is_string($publicKey) || $publicKey === '' || ! is_string($privateKey) || $privateKey === '') {
            return 'VAPID keys are missing: php artisan pwa:push:vapid';
        }

        $public = self::decode($publicKey);
        if ($public === null || strlen($public) !== 65 || $public[0] !== "\x04") {
            return 'PWA_VAPID_PUBLIC_KEY is not an uncompressed P-256 key (65 bytes, base64url)';
        }

        $private = self::decode($privateKey);
        if ($private === null || strlen($private) !== 32) {
            return 'PWA_VAPID_PRIVATE_KEY is not a P-256 private key (32 bytes, base64url)';
        }

        if (! is_string($subject) || (! str_starts_with($subject, 'mailto:') && ! str_starts_with($subject, 'https://'))) {
            return 'PWA_VAPID_SUBJECT must be a mailto: address or an https:// URL';
        }

        return null;
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $text) !== 1) {
            return null;
        }

        $binary = base64_decode(strtr($text, '-_', '+/'), true);

        return $binary === false ? null : $binary;
    }

    private static function pad(string $coordinate): string
    {
        return str_pad($coordinate, 32, "\0", STR_PAD_LEFT);
    }
}
