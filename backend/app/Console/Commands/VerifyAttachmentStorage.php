<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VerifyAttachmentStorage extends Command
{
    protected $signature = 'aqari:verify-attachments';

    protected $description = 'Verify private attachment objects exist and match recorded byte sizes.';

    public function handle(): int
    {
        $checked = 0;
        $errors = 0;

        Attachment::query()->orderBy('id')->chunkById(200, function ($attachments) use (&$checked, &$errors): void {
            foreach ($attachments as $attachment) {
                $checked++;
                $diskName = $attachment->storage_disk ?: 'local';

                if (! in_array($diskName, ['local', 's3'], true)) {
                    $errors++;
                    $this->error("Attachment #{$attachment->id}: unsupported private disk.");
                    continue;
                }

                try {
                    $disk = Storage::disk($diskName);

                    if (! $disk->exists($attachment->storage_path)) {
                        $errors++;
                        $this->error("Attachment #{$attachment->id}: object missing.");
                        continue;
                    }

                    if ((int) $disk->size($attachment->storage_path) !== (int) $attachment->size_bytes) {
                        $errors++;
                        $this->error("Attachment #{$attachment->id}: object size mismatch.");
                    }
                } catch (Throwable $exception) {
                    $errors++;
                    report($exception);
                    $this->error("Attachment #{$attachment->id}: storage verification failed.");
                }
            }
        });

        $this->line("Private attachments checked: $checked; errors: $errors.");
        if ($errors) {
            return self::FAILURE;
        }

        $this->info('Private attachment existence and byte-size verification passed.');
        return self::SUCCESS;
    }
}
