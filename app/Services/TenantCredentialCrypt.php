<?php

namespace App\Services;

use RuntimeException;

class TenantCredentialCrypt
{
    private function getKey(): string
    {
        $encodedKey = env('TENANT_CREDENTIALS_KEY');

        if (!is_string($encodedKey) || $encodedKey === '') {
            throw new RuntimeException(
                'TENANT_CREDENTIALS_KEY is not configured.'
            );
        }

        $key = base64_decode($encodedKey, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'TENANT_CREDENTIALS_KEY must be a Base64-encoded 32-byte key.'
            );
        }

        return $key;
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->getKey();

        $nonce = random_bytes(24);

        $ciphertext = sodium_crypto_secretbox(
            $plaintext,
            $nonce,
            $key
        );

        return base64_encode($nonce . $ciphertext);
    }

    
    public function decrypt(string $encrypted): string
    {
        $key = $this->getKey();

        // Sodium secretbox nonce length is 24 bytes.
        $nonceLength = 24;

        $payload = base64_decode($encrypted, true);

        if (
            $payload === false ||
            strlen($payload) <= $nonceLength
        ) {
            throw new RuntimeException(
                'The encrypted tenant credential is invalid.'
            );
        }

        $nonce = substr($payload, 0, $nonceLength);
        $ciphertext = substr($payload, $nonceLength);

        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new RuntimeException(
                'The PHP Sodium extension is not available.'
            );
        }

        $plaintext = sodium_crypto_secretbox_open(
            $ciphertext,
            $nonce,
            $key
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Unable to decrypt the tenant credential. Verify the encryption key.'
            );
        }

        return $plaintext;
    }

}
