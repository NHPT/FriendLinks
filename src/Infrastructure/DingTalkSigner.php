<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

final class DingTalkSigner
{
    public function sign(string $secret, string $timestamp): string
    {
        return base64_encode(hash_hmac(
            'sha256',
            $timestamp . "\n" . $secret,
            $secret,
            true
        ));
    }
}
