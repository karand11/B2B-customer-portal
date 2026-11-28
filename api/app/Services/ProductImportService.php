<?php

namespace App\Services;

use App\Models\ProductImport;
use App\Models\User;
use App\Support\AttachmentName;
use App\Support\CatalogCache;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Two-step CSV import. preview() validates and classifies the file with NO product write and keeps it on the private `imports` disk;
 * apply() re-reads exactly those bytes (SHA-256 checked) under a row lock, upserts the valid rows once and audits it.
 */
class ProductImportService
{
    private const ALLOWED_TYPES = ['text/csv', 'text/plain', 'application/csv'];

    public function __construct(private readonly ProductImporter $importer) {}

    /** @throws ValidationException on `file` (nothing is stored) */
    public function preview(User $user, mixed $file): ProductImport
    {
        $info = $this->inspect($file);

        $handle = fopen($file->getRealPath(), 'rb');
        try {
            $report = $this->importer->run($handle, true, config('admin.imports.max_rows'));
        } catch (ImportFatal $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } finally {
            fclose($handle);
        }

        $disk = Storage::disk(config('admin.imports.disk'));
        $path = $disk->putFileAs(config('admin.imports.directory'), $file, bin2hex(random_bytes(16)));
        $path !== false || throw new \RuntimeException('The import file could not be stored.');

        try {
            $import = (new ProductImport)->forceFill([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'original_name' => $info['name'],
                'storage_path' => $path,
                'size_bytes' => $info['size'],
                'sha256' => $info['sha256'],
                'status' => 'previewed',
                'rows_read' => $report->read,
                'would_create' => $report->created,
                'would_update' => $report->updated,
                'bad_rows' => $report->bad,
                'errors' => $report->errors,
                'warnings' => $report->warnings,
                'expires_at' => now()->addMinutes(config('admin.imports.preview_minutes')),
            ]);
            $import->save();
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        return $import;
    }

    /** @throws ValidationException on `import` or `skip_invalid` */
    public function apply(User $user, string $id, bool $skipInvalid): ProductImport
    {
        $path = null;

        $import = DB::transaction(function () use ($user, $id, $skipInvalid, &$path) {
            // The row lock serialises a double click or a replay: the second request sees `applied` and is refused.
            $import = ProductImport::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();

            $import->status === 'applied' && $this->refuse('import', 'This import was already applied.');
            ($import->status !== 'previewed' || $import->expires_at->isPast()) && $this->refuse('import', 'This preview has expired. Upload the file again.');
            $import->bad_rows > 0 && ! $skipInvalid && $this->refuse('skip_invalid', 'This file has invalid rows. Confirm that they should be skipped.');

            $path = $import->storage_path;
            $disk = Storage::disk(config('admin.imports.disk'));
            $content = $path !== null && $disk->exists($path) ? $disk->get($path) : null;
            ($content === null || ! is_string($content) || ! hash_equals($import->sha256, hash('sha256', $content)))
                && $this->refuse('import', 'The uploaded file is no longer available or has changed. Upload it again.');

            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $content);
            rewind($stream);

            try {
                $report = $this->importer->run($stream, false, config('admin.imports.max_rows'), true);
            } catch (ImportFatal $e) {
                $this->refuse('import', $e->getMessage());
            } finally {
                fclose($stream);
            }

            $import->forceFill([
                'status' => 'applied',
                'result' => ['created' => $report->created, 'updated' => $report->updated, 'bad_rows' => $report->bad],
                'rows_read' => $report->read,
                'bad_rows' => $report->bad,
                'applied_at' => now(),
                'storage_path' => null,
            ])->save();

            AuditLog::record(
                'product.imported', 'import', $import->id, null, $user, [], [],
                [
                    'file_name' => $import->original_name, 'sha256' => $import->sha256, 'rows_read' => $report->read, 'created' => $report->created, 'updated' => $report->updated,
                    'bad_rows' => $report->bad, 'applied_rows' => $report->created + $report->updated, 'skipped_rows' => $report->bad,
                ],
            );

            // One row per UPDATED product with a real difference (created products are in the aggregate row above). Same transaction:
            // a failing audit write rolls the whole import back.
            AuditLog::recordMany('product.import_updated', 'product', $report->changes, $user, ['import_id' => $import->id, 'file_name' => $import->original_name]);

            return $import;
        });

        // After the transaction: a failed or rolled-back apply keeps the file and the catalog cache untouched.
        CatalogCache::flush();
        try {
            Storage::disk(config('admin.imports.disk'))->delete($path);
        } catch (Throwable $e) {
            report($e);
        }

        return $import;
    }

    /**
     * The extension AND the sniffed content must say CSV/plain text, valid UTF-8 (BOM allowed) without NUL. The client Content-Type is never used.
     *
     * @return array{name: string, size: int, sha256: string}
     */
    private function inspect(mixed $file): array
    {
        $max = config('admin.imports.max_bytes');

        $file instanceof UploadedFile || $this->refuse('file', 'The file field is required and must be a file.');
        ($file->isValid() && $file->getRealPath() !== false) || $this->refuse('file', 'The file failed to upload.');

        $size = (int) $file->getSize();
        $size > 0 || $this->refuse('file', 'The file is empty.');
        $size <= $max || $this->refuse('file', 'The file must not be greater than '.($max / 1024 / 1024).' MB.');

        $name = AttachmentName::clean((string) $file->getClientOriginalName());
        AttachmentName::extension($name) === 'csv' || $this->refuse('file', 'Only .csv files are accepted.');

        in_array((new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()), self::ALLOWED_TYPES, true)
            || $this->refuse('file', 'The file content is not CSV text.');

        $content = (string) file_get_contents($file->getRealPath());
        (! str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8')) || $this->refuse('file', 'The file must be valid UTF-8 text without NUL bytes.');

        return ['name' => $name, 'size' => $size, 'sha256' => hash('sha256', $content)];
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
