<?php

namespace App\Console\Commands;

use App\Models\ProductImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Previews that were never applied expire after an hour: the row becomes `expired` and the stored file is deleted. Not audited (system job). */
class PruneProductImports extends Command
{
    protected $signature = 'imports:prune';

    protected $description = 'Expire product import previews past their expiry and delete their files';

    public function handle(): int
    {
        $expired = 0;
        $disk = Storage::disk(config('admin.imports.disk'));

        ProductImport::where('status', 'previewed')->where('expires_at', '<=', now())->orderBy('created_at')->each(function (ProductImport $import) use ($disk, &$expired) {
            try {
                $import->storage_path === null || $disk->delete($import->storage_path);
            } catch (Throwable $e) {
                report($e);
            }

            // Re-checked in the UPDATE itself: an apply that won the race keeps its `applied` status.
            $expired += ProductImport::whereKey($import->id)->where('status', 'previewed')->update(['status' => 'expired', 'storage_path' => null]);
        });

        $this->line("Expired previews: {$expired}");

        return self::SUCCESS;
    }
}
