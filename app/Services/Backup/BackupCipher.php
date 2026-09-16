<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Encrypts a database dump at rest.
 *
 * A dump holds every account's password hash, every stored credential, and every
 * row this app has ever recorded. It already lives outside the public root and
 * behind an authorized route - but that only protects it while the application is
 * the only way in. It does nothing about a mis-set permission on storage/, a
 * stolen disk image, or a backup folder that ends up inside someone's sync tool.
 *
 * AES-256-GCM, so a tampered file fails to decrypt rather than decrypting into
 * something subtly wrong - a backup is exactly the kind of file where silently
 * corrupted output is worse than an error.
 *
 * The key is derived from APP_KEY. That means: keep APP_KEY backed up somewhere
 * other than inside these backups, or they cannot be restored. The Pengaturan
 * page says so out loud next to the download button.
 */
class BackupCipher
{
    /** Leading bytes that mark a file this class wrote. */
    protected const MAGIC = "MHBK1\0";

    protected const CIPHER = 'aes-256-gcm';

    protected const IV_BYTES = 12;

    protected const TAG_BYTES = 16;

    /** MAGIC | iv | tag | ciphertext. */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Gagal mengenkripsi berkas backup.');
        }

        return self::MAGIC.$iv.$tag.$ciphertext;
    }

    public function decrypt(string $payload): string
    {
        if (! $this->looksEncrypted($payload)) {
            throw new RuntimeException('Berkas backup ini tidak dikenali sebagai berkas terenkripsi.');
        }

        $offset = strlen(self::MAGIC);
        $iv = substr($payload, $offset, self::IV_BYTES);
        $tag = substr($payload, $offset + self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, $offset + self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        // GCM fails closed: a wrong APP_KEY and a tampered file are the same answer
        // here, which is the right one for a file meant to be restored verbatim.
        if ($plaintext === false) {
            throw new RuntimeException('Backup gagal didekripsi. Pastikan APP_KEY masih sama dengan saat backup dibuat.');
        }

        return $plaintext;
    }

    public function looksEncrypted(string $payload): bool
    {
        return str_starts_with($payload, self::MAGIC);
    }

    /**
     * 32 bytes derived from APP_KEY, never APP_KEY itself - so this key and the
     * one Laravel uses for its own encrypted casts are not the same value.
     */
    protected function key(): string
    {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY belum diset, backup tidak bisa dienkripsi.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        return hash_hkdf('sha256', $appKey, 32, 'backup-terenkripsi');
    }
}
