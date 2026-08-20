<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class PreparedTarget
{
    /** @var string */
    public $url;

    /** @var string */
    public $scheme;

    /** @var string */
    public $host;

    /** @var int */
    public $port;

    /** @var string[] */
    public $addresses;

    public function __construct(string $url, string $scheme, string $host, int $port, array $addresses)
    {
        $this->url = $url;
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->addresses = array_values(array_unique($addresses));
    }
}
