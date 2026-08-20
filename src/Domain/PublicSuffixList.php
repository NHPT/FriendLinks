<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class PublicSuffixList
{
    /** @var array<string,bool> */
    private $exact = [];

    /** @var array<string,bool> */
    private $wildcard = [];

    /** @var array<string,bool> */
    private $exception = [];

    public function __construct(string $data)
    {
        $private = false;
        foreach (preg_split('/\r\n|\r|\n/', $data) as $line) {
            $line = trim($line);
            if ('// ===BEGIN PRIVATE DOMAINS===' === $line) {
                $private = true;
            }
            if ($private || '' === $line || 0 === strpos($line, '//')) {
                continue;
            }
            $line = strtolower($line);
            if ('!' === $line[0]) {
                $this->exception[substr($line, 1)] = true;
            } elseif (0 === strpos($line, '*.')) {
                $this->wildcard[substr($line, 2)] = true;
            } else {
                $this->exact[$line] = true;
            }
        }
    }

    public static function bundled(): self
    {
        $file = dirname(__DIR__, 2) . '/resources/public_suffix_list.dat';
        if (!is_file($file)) {
            throw new \RuntimeException('缺少内置 Public Suffix List。');
        }
        return new self((string) file_get_contents($file));
    }

    public function registrableDomain(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ('' === $host || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return null;
        }

        $publicSuffixLabels = 1;
        $exceptionLabels = 0;
        $total = count($labels);
        for ($index = 0; $index < $total; $index++) {
            $suffix = implode('.', array_slice($labels, $index));
            $count = $total - $index;
            if (isset($this->exception[$suffix])) {
                $exceptionLabels = max($exceptionLabels, $count);
            }
            if (isset($this->exact[$suffix])) {
                $publicSuffixLabels = max($publicSuffixLabels, $count);
            }
            if ($index > 0 && isset($this->wildcard[$suffix])) {
                $publicSuffixLabels = max($publicSuffixLabels, $count + 1);
            }
        }

        if ($exceptionLabels > 0) {
            $publicSuffixLabels = $exceptionLabels - 1;
        }
        if ($total <= $publicSuffixLabels) {
            return null;
        }

        return implode('.', array_slice($labels, -($publicSuffixLabels + 1)));
    }
}
