<?php

declare(strict_types=1);

namespace HalalPulse\Operations;

use RuntimeException;

final class BackupEncryptor
{
    private const MAGIC = "HPBK1\0";
    private const SALT_BYTES = 16;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const ITERATIONS = 200000;
    private const CHUNK_BYTES = 1048576;

    /** @return array{sha256: string, bytes: int, chunks: int} */
    public function encrypt(string $sourcePath, string $destinationPath, string $passphrase): array
    {
        $this->validatePassphrase($passphrase);
        $input = fopen($sourcePath, 'rb');
        if ($input === false) {
            throw new RuntimeException('Backup source cannot be opened.');
        }

        $output = fopen($destinationPath, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Encrypted backup destination already exists or cannot be created.');
        }

        @chmod($destinationPath, 0600);
        $salt = random_bytes(self::SALT_BYTES);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $header = self::MAGIC . $salt . pack('N', self::ITERATIONS) . pack('N', self::CHUNK_BYTES);
        $hash = hash_init('sha256');
        $bytes = 0;
        $chunks = 0;

        try {
            $this->writeAll($output, $header);
            hash_update($hash, $header);
            $bytes += strlen($header);

            while (!feof($input)) {
                $plain = fread($input, self::CHUNK_BYTES);
                if ($plain === false) {
                    throw new RuntimeException('Unable to read backup source.');
                }
                if ($plain === '') {
                    break;
                }

                $nonce = random_bytes(self::NONCE_BYTES);
                $tag = '';
                $aad = self::MAGIC . pack('N', $chunks);
                $cipher = openssl_encrypt(
                    $plain,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    $aad,
                    self::TAG_BYTES,
                );
                if (!is_string($cipher) || strlen($tag) !== self::TAG_BYTES) {
                    throw new RuntimeException('Backup encryption failed.');
                }

                $record = pack('N', strlen($cipher)) . $nonce . $tag . $cipher;
                $this->writeAll($output, $record);
                hash_update($hash, $record);
                $bytes += strlen($record);
                $chunks++;
            }

            $footer = pack('N', 0);
            $this->writeAll($output, $footer);
            hash_update($hash, $footer);
            $bytes += strlen($footer);

            if (!fflush($output)) {
                throw new RuntimeException('Unable to flush encrypted backup.');
            }
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($destinationPath);
            throw $exception;
        }

        fclose($input);
        fclose($output);

        return ['sha256' => hash_final($hash), 'bytes' => $bytes, 'chunks' => $chunks];
    }

    /** @return array{sha256: string, bytes: int, chunks: int} */
    public function decrypt(string $sourcePath, string $destinationPath, string $passphrase): array
    {
        $this->validatePassphrase($passphrase);
        $input = fopen($sourcePath, 'rb');
        if ($input === false) {
            throw new RuntimeException('Encrypted backup cannot be opened.');
        }
        $output = fopen($destinationPath, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Decrypted destination already exists or cannot be created.');
        }
        @chmod($destinationPath, 0600);

        $headerLength = strlen(self::MAGIC) + self::SALT_BYTES + 8;
        $header = $this->readExact($input, $headerLength);
        if (substr($header, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            fclose($input);
            fclose($output);
            @unlink($destinationPath);
            throw new RuntimeException('Encrypted backup header is invalid.');
        }

        $offset = strlen(self::MAGIC);
        $salt = substr($header, $offset, self::SALT_BYTES);
        $offset += self::SALT_BYTES;
        $iterations = unpack('Nvalue', substr($header, $offset, 4))['value'] ?? 0;
        $offset += 4;
        $chunkBytes = unpack('Nvalue', substr($header, $offset, 4))['value'] ?? 0;
        if ($iterations < 100000 || $iterations > 1000000 || $chunkBytes < 65536 || $chunkBytes > 8388608) {
            fclose($input);
            fclose($output);
            @unlink($destinationPath);
            throw new RuntimeException('Encrypted backup parameters are invalid.');
        }

        $key = hash_pbkdf2('sha256', $passphrase, $salt, (int) $iterations, 32, true);
        $plainHash = hash_init('sha256');
        $plainBytes = 0;
        $chunks = 0;

        try {
            while (true) {
                $lengthBytes = $this->readExact($input, 4, true);
                if ($lengthBytes === '') {
                    throw new RuntimeException('Encrypted backup ended before its footer.');
                }
                $cipherLength = unpack('Nvalue', $lengthBytes)['value'] ?? -1;
                if ($cipherLength === 0) {
                    break;
                }
                if ($cipherLength < 1 || $cipherLength > $chunkBytes + 64) {
                    throw new RuntimeException('Encrypted backup chunk length is invalid.');
                }

                $nonce = $this->readExact($input, self::NONCE_BYTES);
                $tag = $this->readExact($input, self::TAG_BYTES);
                $cipher = $this->readExact($input, (int) $cipherLength);
                $aad = self::MAGIC . pack('N', $chunks);
                $plain = openssl_decrypt(
                    $cipher,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    $aad,
                );
                if (!is_string($plain)) {
                    throw new RuntimeException('Encrypted backup authentication failed.');
                }

                $this->writeAll($output, $plain);
                hash_update($plainHash, $plain);
                $plainBytes += strlen($plain);
                $chunks++;
            }

            if (fread($input, 1) !== '') {
                throw new RuntimeException('Encrypted backup contains trailing bytes.');
            }
            if (!fflush($output)) {
                throw new RuntimeException('Unable to flush decrypted backup.');
            }
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($destinationPath);
            throw $exception;
        }

        fclose($input);
        fclose($output);

        return ['sha256' => hash_final($plainHash), 'bytes' => $plainBytes, 'chunks' => $chunks];
    }

    public function verify(string $sourcePath, string $passphrase, string $expectedPlainSha256): bool
    {
        $temporary = tempnam(sys_get_temp_dir(), 'hpbk_verify_');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create backup verification file.');
        }
        @unlink($temporary);

        try {
            $result = $this->decrypt($sourcePath, $temporary, $passphrase);
            $actual = $result['sha256'];
        } finally {
            @unlink($temporary);
        }

        return strlen($expectedPlainSha256) === 64 && hash_equals(strtolower($expectedPlainSha256), strtolower($actual));
    }

    private function validatePassphrase(string $passphrase): void
    {
        if (strlen($passphrase) < 20 || strlen($passphrase) > 1024) {
            throw new RuntimeException('Backup encryption passphrase must contain 20 to 1,024 bytes.');
        }
    }

    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write backup data.');
            }
            $offset += $written;
        }
    }

    private function readExact($stream, int $length, bool $allowImmediateEof = false): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false) {
                throw new RuntimeException('Unable to read encrypted backup data.');
            }
            if ($chunk === '') {
                if ($allowImmediateEof && $data === '') {
                    return '';
                }
                throw new RuntimeException('Encrypted backup is truncated.');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
