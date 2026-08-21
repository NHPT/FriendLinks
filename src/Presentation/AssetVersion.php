<?php

namespace TypechoPlugin\FriendLinks\Presentation;

final class AssetVersion
{
    public static function forFile(string $path): string
    {
        if (!@is_file($path)) {
            return 'missing';
        }

        $modifiedAt = @filemtime($path);
        return false === $modifiedAt ? 'missing' : (string) $modifiedAt;
    }
}
