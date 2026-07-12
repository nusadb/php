<?php

declare(strict_types=1);

namespace NusaDB;

/**
 * Client-side SCRAM-SHA-256 (RFC 5802 / RFC 7677), docs/wire-protocol.md §7.2.
 * Pure PHP (hash/hash_hmac/hash_pbkdf2/random_bytes) — no extensions beyond ext-hash.
 */
final class Scram
{
    private const GS2_HEADER = 'n,,';

    /** @var string */
    public $clientFirstBare;
    /** @var string */
    public $fullClientFirst;
    /** @var string */
    private $expectedSignature = '';

    private function __construct(string $bare, string $full)
    {
        $this->clientFirstBare = $bare;
        $this->fullClientFirst = $full;
    }

    public static function start(string $user): self
    {
        $nonce = base64_encode(random_bytes(18));
        $bare = "n={$user},r={$nonce}";
        return new self($bare, self::GS2_HEADER . $bare);
    }

    /** Build the client-final message from the server-first message. */
    public function clientFinal(string $password, string $serverFirst): string
    {
        $combinedNonce = null;
        $salt = null;
        $iterations = 0;
        foreach (explode(',', $serverFirst) as $field) {
            $eq = strpos($field, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $key = substr($field, 0, $eq);
            $value = substr($field, $eq + 1);
            if ($key === 'r') {
                $combinedNonce = $value;
            } elseif ($key === 's') {
                $salt = base64_decode($value, true);
            } elseif ($key === 'i') {
                $iterations = (int) $value;
            }
        }
        if ($combinedNonce === null || $salt === false || $salt === null || $iterations <= 0) {
            throw new NusaException('nusadb: malformed server-first message', '08P01');
        }

        $salted = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
        $storedKey = hash('sha256', $clientKey, true);

        $channelBinding = base64_encode(self::GS2_HEADER);
        $withoutProof = "c={$channelBinding},r={$combinedNonce}";
        $authMessage = "{$this->clientFirstBare},{$serverFirst},{$withoutProof}";
        $clientSig = hash_hmac('sha256', $authMessage, $storedKey, true);
        $proof = $clientKey ^ $clientSig;
        $final = "{$withoutProof},p=" . base64_encode($proof);

        $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);
        $serverSig = hash_hmac('sha256', $authMessage, $serverKey, true);
        $this->expectedSignature = base64_encode($serverSig);

        return $final;
    }

    /** Verify the server-final v=<signature> in constant time. */
    public function verifyServerFinal(string $serverFinal): bool
    {
        $got = '';
        foreach (explode(',', $serverFinal) as $field) {
            if (strncmp($field, 'v=', 2) === 0) {
                $got = substr($field, 2);
            }
        }
        return hash_equals($this->expectedSignature, $got);
    }
}
