<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class TargetException extends \RuntimeException
{
    /** @var string */
    private $reasonCode;

    public function __construct(string $reasonCode, string $message)
    {
        parent::__construct($message);
        $this->reasonCode = $reasonCode;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
