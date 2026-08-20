<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

final class WebhookSigner
{
    public function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    }
}
