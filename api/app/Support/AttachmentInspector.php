<?php

namespace App\Support;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Decides whether an upload is acceptable, from its CONTENT: the extension of the sanitised name must be on the allowlist
 * AND finfo must sniff a type allowed for that extension. The client-sent Content-Type is never looked at.
 */
class AttachmentInspector
{
    /**
     * @return array{name: string, mime: string, size: int, sha256: string}
     *
     * @throws ValidationException on `file`
     */
    public function inspect(mixed $file): array
    {
        $max = config('quotes.attachments.max_bytes');

        if (! $file instanceof UploadedFile) {
            $this->fail('The file field is required and must be a file.');
        }
        if (! $file->isValid() || $file->getRealPath() === false) {
            $this->fail('The file failed to upload.');
        }

        $size = (int) $file->getSize();
        $size > 0 || $this->fail('The file is empty.');
        $size <= $max || $this->fail('The file must not be greater than '.($max / 1024 / 1024).' MB.');

        $name = AttachmentName::clean((string) $file->getClientOriginalName());
        $type = config('quotes.attachments.types')[AttachmentName::extension($name)] ?? null;
        $type !== null || $this->fail('This file type is not allowed. Allowed types: '.implode(', ', array_keys(config('quotes.attachments.types'))).'.');

        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        in_array($sniffed, $type[1], true) || $this->fail('The file content does not match its file type.');

        if (str_starts_with($type[0], 'text/')) {
            $content = (string) file_get_contents($file->getRealPath());
            (! str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8')) || $this->fail('Text files must be valid UTF-8 without NUL bytes.');
        }

        return ['name' => $name, 'mime' => $type[0], 'size' => $size, 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
