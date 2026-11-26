<?php

namespace App\Support;

/** Display-only file names. The result is never used to build a path. */
class AttachmentName
{
    public const MAX = 100;

    public static function clean(string $clientName): string
    {
        $name = mb_scrub($clientName);
        $name = preg_split('/[\/\\\\]/', $name);
        $name = end($name);
        $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, ' .');

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (mb_strlen($name) > self::MAX) {
            $suffix = $extension !== '' && mb_strlen($extension) < 20 ? '.'.$extension : '';
            $name = mb_substr($name, 0, self::MAX - mb_strlen($suffix)).$suffix;
        }

        return $name === '' ? 'file' : $name;
    }

    /** Lowercase extension of the cleaned name ('' when none). */
    public static function extension(string $cleanName): string
    {
        return strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    }
}
