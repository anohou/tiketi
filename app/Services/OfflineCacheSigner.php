<?php

namespace App\Services;

use RuntimeException;

final class OfflineCacheSigner
{
    /** @return array{signature_algorithm: string, key_id: string, public_key: string} */
    public function verificationDescriptor(): array
    {
        $publicKey = $this->publicKey();

        return [
            'signature_algorithm' => 'Ed25519',
            'key_id' => substr(hash('sha256', $publicKey), 0, 16),
            'public_key' => base64_encode($publicKey),
        ];
    }

    /** @return array{signature_algorithm: string, key_id: string, signature: string} */
    public function signPayloadHash(string $payloadHash): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $payloadHash)) {
            throw new RuntimeException('The offline cache payload hash is invalid.');
        }

        $descriptor = $this->verificationDescriptor();

        return [
            'signature_algorithm' => $descriptor['signature_algorithm'],
            'key_id' => $descriptor['key_id'],
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadHash, $this->secretKey())),
        ];
    }

    private function keyPair(): string
    {
        $configured = trim((string) config('transport.offline.signing_private_key'));
        if ($configured !== '') {
            $decoded = base64_decode($configured, true);
            if ($decoded === false) {
                throw new RuntimeException('OFFLINE_CACHE_SIGNING_PRIVATE_KEY must be valid base64.');
            }
            if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                return sodium_crypto_sign_seed_keypair($decoded);
            }
            if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $publicKey = sodium_crypto_sign_publickey_from_secretkey($decoded);

                return sodium_crypto_sign_keypair_from_secretkey_and_publickey($decoded, $publicKey);
            }

            throw new RuntimeException('OFFLINE_CACHE_SIGNING_PRIVATE_KEY must contain a 32-byte seed or 64-byte Ed25519 secret key.');
        }

        $seed = hash('sha256', (string) config('app.key').'|tiketi-offline-cache-ed25519', true);

        return sodium_crypto_sign_seed_keypair($seed);
    }

    private function secretKey(): string
    {
        return sodium_crypto_sign_secretkey($this->keyPair());
    }

    private function publicKey(): string
    {
        return sodium_crypto_sign_publickey($this->keyPair());
    }
}
