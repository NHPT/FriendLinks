<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

final class WorkerSigner
{
    /** @var Repositories */
    private $repositories;

    public function __construct(?Repositories $repositories = null)
    {
        $this->repositories = $repositories;
    }

    public function sign(
        string $secret,
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $body
    ): string {
        return hash_hmac('sha256', $this->canonical($method, $path, $timestamp, $nonce, $body), $secret);
    }

    public function verify(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $body,
        ?int $now = null
    ): bool {
        $now = $now ?: time();
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > 300) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        $expected = $this->sign($secret, $method, $path, (int) $timestamp, $nonce, $body);
        if (!hash_equals($expected, strtolower($signature))) {
            return false;
        }

        $repositories = $this->repositories ?: new Repositories();
        return $repositories->consumeNonce($nonce, $now + 300);
    }

    private function canonical(string $method, string $path, int $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $body);
    }
}
