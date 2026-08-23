<?php

namespace TypechoPlugin\FriendLinks\Domain;

final class Text
{
    public static function firstCharacter(string $value): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1, 'UTF-8');
        }
        return 1 === preg_match('/^./us', $value, $match) ? $match[0] : '';
    }

    public static function truncateUtf8(string $value, int $maxBytes): string
    {
        if (1 !== preg_match('//u', $value)) {
            preg_match_all(
                '/[\x00-\x7F]'
                    . '|[\xC2-\xDF][\x80-\xBF]'
                    . '|\xE0[\xA0-\xBF][\x80-\xBF]'
                    . '|[\xE1-\xEC\xEE-\xEF][\x80-\xBF]{2}'
                    . '|\xED[\x80-\x9F][\x80-\xBF]'
                    . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
                    . '|[\xF1-\xF3][\x80-\xBF]{3}'
                    . '|\xF4[\x80-\x8F][\x80-\xBF]{2}/',
                $value,
                $matches
            );
            $value = implode('', $matches[0] ?? []);
        }
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        if (function_exists('mb_strcut')) {
            return (string) mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        $value = substr($value, 0, $maxBytes);
        while ('' !== $value && 1 !== preg_match('//u', $value)) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }
}
