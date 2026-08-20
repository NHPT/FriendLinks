<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

interface NotificationChannelInterface
{
    public function send(array $notification, array $settings, ?float $deadline = null): void;
}
