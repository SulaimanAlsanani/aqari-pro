<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExportPrivateAttachments extends Command
{
    protected $signature = 'aqari:export-attachments {directory}';
    protected $description = 'Export owner-authorized private attachment object bytes and SHA-256 manifest into an empty restricted directory.';

    public function handle(): int
    {
        $directory = (string) $this->argument('directory');

        // This command is used only with a fresh private staging directory.
        // Never merge into an existing backup or write through a symlink.
        if (! is_dir($directory) || is_link($directory) || ! is_writable($directory)
            || count(scandir($directory)) !== 2) {
            $this->error('An existing empty, writable, non-symlink directory is required.');
            return self::FAILURE;
        }

        $objects = rtrim($directory, '/').'/objects';
        if (! mkdir($objects, 0700)) {
            $this->error('Cannot create private attachment export directory.');
            return self::FAILURE;
        }

        try {
            $items = [];

            Attachment::query()->orderBy('id')->chunkById(100, function ($attachments) use (&$items, $objects): void {
                foreach ($attachments as $attachment) {
                    $diskName = $attachment->storage_disk ?: 'local';
                    if (! in_array($diskName, ['local', 's3'], true)) {
                        throw new RuntimeException('Disallowed attachment storage disk.');
                    }

                    $relativeFile = 'objects/'.$attachment->id.'.bin';
                    $destination = $objects.'/'.$attachment->id.'.bin';

                    $source = Storage::disk($diskName)->readStream($attachment->storage_path);
                    if (! is_resource($source)) {
                        throw new RuntimeException('Private attachment cannot be read.');
                    }

                    $output = fopen($destination, 'xb');
                    if ($output === false) {
                        fclose($source);
                        throw new RuntimeException('Cannot create exclusive backup object.');
                    }

                    try {
                        $copied = stream_copy_to_stream($source, $output);
                        if ($copied === false) {
                            throw new RuntimeException('Failed to copy a private attachment.');
                        }
                    } finally {
                        fclose($source);
                        fclose($output);
                    }
                    chmod($destination, 0600);
                    if (filesize($destination) !== (int) $attachment->size_bytes) {
                        throw new RuntimeException('Private attachment changed during backup or size is incorrect.');
                    }
                    $hash = hash_file('sha256', $destination);
                    if ($hash === false) {
                        throw new RuntimeException('Cannot hash a backed-up attachment.');
                    }

                    $items[] = [
                        'id' => (int) $attachment->id,
                        'disk' => $diskName,
                        'storage_path' => $attachment->storage_path,
                        'bytes' => (int) $attachment->size_bytes,
                        'sha256' => $hash,
                        'file' => $relativeFile,
                    ];
                }
            });

            $manifest = [
                'format' => 'aqari-private-attachments-v1',
                'created_utc' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'count' => count($items),
                'objects' => $items,
            ];
            $written = file_put_contents(
                rtrim($directory, '/').'/attachments.json',
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX,
            );
            if ($written === false) {
                throw new RuntimeException('Cannot write attachment backup manifest.');
            }
            chmod(rtrim($directory, '/').'/attachments.json', 0600);

            $this->info('Private attachment objects and SHA-256 manifest exported: '.count($items));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Attachment export failed; no backup should be uploaded.');
            return self::FAILURE;
        }
    }
}
