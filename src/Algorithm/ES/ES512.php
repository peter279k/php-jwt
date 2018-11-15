<?php

namespace Lindelius\JWT\Algorithm\ES;

/**
 * Trait ES512
 */
trait ES512
{
    /**
     * Encodes given data using a given key.
     *
     * @param  string $data
     * @param  mixed  $key
     * @param  string $passphrase
     * @return string|null
     */
    protected function encodeWithES512(string $data, $key, $passphrase = ''): ?string
    {
        $privateKey = openssl_pkey_get_private($key, $passphrase);
        $digest = openssl_digest($data, 'sha512');

        $signature = '';
        openssl_sign($digest, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return base64_encode($signature);
    }

    /**
     * Verifies a given signature.
     *
     * @param  string $signature
     * @param  string $data
     * @param  mixed  $key
     * @return bool
     */
    protected function verifyWithES512(string $signature, string $data, $key): bool
    {
        $publicKey = openssl_pkey_get_public($key);
        $digest = openssl_digest($data, 'sha512');

        return openssl_verify($digest, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA512);
    }
}