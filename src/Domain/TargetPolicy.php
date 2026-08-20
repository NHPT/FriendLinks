<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class TargetPolicy
{
    /** @var UrlNormalizer */
    private $normalizer;

    public function __construct(?UrlNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?: new UrlNormalizer();
    }

    public function prepare(string $url, ?float $deadline = null): PreparedTarget
    {
        try {
            $normalized = $this->normalizer->normalize($url);
        } catch (\InvalidArgumentException $error) {
            throw new TargetException('dns_blocked_target', $error->getMessage());
        }

        if (!$this->normalizer->canDetect($normalized)) {
            throw new TargetException('idn_unsupported', '缺少 ext-intl，无法安全检测国际化域名。');
        }

        $scheme = (string) parse_url($normalized, PHP_URL_SCHEME);
        $host = trim((string) parse_url($normalized, PHP_URL_HOST), '[]');
        $port = (int) (parse_url($normalized, PHP_URL_PORT) ?: ('https' === $scheme ? 443 : 80));
        $addresses = $this->resolve($host, $deadline);

        foreach ($addresses as $address) {
            if (!IpAddress::isPublic($address)) {
                throw new TargetException('dns_blocked_target', '目标解析到非公网地址，检测已拒绝。');
            }
        }

        return new PreparedTarget($normalized, $scheme, $host, $port, $addresses);
    }

    /**
     * @return string[]
     */
    private function resolve(string $host, ?float $deadline): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (!function_exists('dns_get_record')) {
            throw new TargetException('dns_failed', '运行环境无法安全获取完整 DNS 结果。');
        }

        $addresses = [];
        $queue = [strtolower(rtrim($host, '.'))];
        $visited = [];
        $depth = 0;

        while ($queue) {
            if (null !== $deadline && microtime(true) >= $deadline) {
                throw new TargetException('dns_failed', 'DNS 解析超过允许时间。');
            }
            if (++$depth > 8) {
                throw new TargetException('dns_failed', 'CNAME 链超过安全深度。');
            }
            $name = array_shift($queue);
            if (isset($visited[$name])) {
                throw new TargetException('dns_failed', 'DNS CNAME 链存在循环。');
            }
            $visited[$name] = true;

            $records = @dns_get_record($name, DNS_A | DNS_AAAA | DNS_CNAME);
            if (null !== $deadline && microtime(true) >= $deadline) {
                throw new TargetException('dns_failed', 'DNS 解析超过允许时间。');
            }
            if (false === $records) {
                throw new TargetException('dns_failed', 'DNS 解析失败。');
            }

            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
                if (!empty($record['target'])) {
                    $target = strtolower(rtrim((string) $record['target'], '.'));
                    if ('' !== $target && !isset($visited[$target])) {
                        $queue[] = $target;
                    }
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if (!$addresses) {
            throw new TargetException('dns_failed', '目标没有可用的 A 或 AAAA 记录。');
        }

        return $addresses;
    }
}
