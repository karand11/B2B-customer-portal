<?php

namespace App\Support;

use Throwable;

/**
 * The log context of an unexpected exception: enough to debug an incident, nothing personal. The message has email addresses masked
 * (messages of mail and queue failures contain them), the trace is frames only (file:line and the function, never arguments or objects)
 * with paths relative to the application root, capped at MAX_FRAMES, and the chain of previous exceptions is listed the same way.
 */
class ReportedException
{
    public const MAX_FRAMES = 25;

    private const MAX_PREVIOUS = 5;

    private const MAX_MESSAGE = 300;

    /** @return array<string, mixed> */
    public static function context(Throwable $e): array
    {
        $previous = [];
        for ($p = $e->getPrevious(); $p !== null && count($previous) < self::MAX_PREVIOUS; $p = $p->getPrevious()) {
            $previous[] = ['class' => $p::class, 'message' => self::message($p), 'at' => self::location($p->getFile(), $p->getLine())];
        }

        return [
            'message' => self::message($e),
            'at' => self::location($e->getFile(), $e->getLine()),
            'trace' => self::frames($e),
        ] + ($previous === [] ? [] : ['previous' => $previous]);
    }

    /** A database exception's message ends with the SQL and its bound values: it is never logged here, only its class. */
    private static function message(Throwable $e): string
    {
        return $e instanceof \PDOException ? '[database message omitted]' : self::mask($e->getMessage());
    }

    public static function mask(string $message): string
    {
        return preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', '[email]', mb_substr($message, 0, self::MAX_MESSAGE)) ?? '[unreadable message]';
    }

    /** @return list<string> "path:line Class::function", at most MAX_FRAMES */
    public static function frames(Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $function = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $frames[] = (isset($frame['file']) ? self::location($frame['file'], $frame['line'] ?? 0) : '[internal]').' '.$function;
        }

        return $frames;
    }

    /** A path relative to the application root; a closure job reports its own source text as the file, which is never logged. */
    private static function location(string $file, int $line): string
    {
        if (str_contains($file, '://')) {
            return "closure:{$line}";
        }

        $root = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $file);

        return (str_starts_with($file, $root) ? substr($file, strlen($root)) : $file).":{$line}";
    }
}
