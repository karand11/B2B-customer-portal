<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

/** Collects every log record of the running test (message, context AND extra, which is where the request id lives) without touching the log file. */
class LogCapture
{
    public static function start(): TestHandler
    {
        $handler = new TestHandler(Level::Debug);
        Log::channel()->getLogger()->pushHandler($handler);

        return $handler;
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>, extra: array<string, mixed>}> */
    public static function records(TestHandler $handler): array
    {
        return array_map(fn (LogRecord $r) => ['level' => $r->level->getName(), 'message' => $r->message, 'context' => $r->context, 'extra' => $r->extra], $handler->getRecords());
    }

    /** Everything a record holds, as one string, for "must not contain" checks. */
    public static function text(TestHandler $handler): string
    {
        return json_encode(self::records($handler), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
}
