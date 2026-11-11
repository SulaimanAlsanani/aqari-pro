<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class VerifyAttachmentExport extends Command
{
    protected $signature = 'aqari:verify-attachment-export {directory}';
    protected $description = 'Verify exported attachment objects, SHA-256 digests and restored database metadata without writing objects.';

    public function handle(): int
    {
        $directory = (string) $this->argument('directory');

        try {
            if (! is_dir($directory) || is_link($directory)) {
                throw new RuntimeException('Export directory is unavailable.');
            }
            $manifestPath = rtrim($directory, '/').'/attachments.json';
            if (! is_file($manifestPath) || is_link($manifestPath)) {
                throw new RuntimeException('Manifest missing or linked.');
            }

            $data = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['format'] ?? '') !== 'aqari-private-attachments-v1'
                || ! is_array($data['objects'] ?? null) || ! is_int($data['count'] ?? null)
                || $data['count'] !== count($data['objects'])) {
                throw new RuntimeException('Invalid attachment export manifest.');
            }

            // Verify against the RECOVERED database as well as all exported
            // bytes; a manifest with missing rows must never pass.
            if (Attachment::query()->count() !== $data['count']) {
                throw new RuntimeException('Attachment metadata count differs from export.');
            }

            $seen = [];
            foreach ($data['objects'] as $item) {
                $id = $item['id'] ?? null;
                if (! is_int($id) || $id < 1 || isset($seen[$id])) {
                    throw new RuntimeException('Duplicate or invalid attachment ID in manifest.');
                }
                $seen[$id] = true;
                $file = 'objects/'.$id.'.bin';
                if (($item['file'] ?? null) !== $file
                    || ! in_array($item['disk'] ?? null, ['local', 's3'], true)
                    || ! is_string($item['storage_path'] ?? null)
                    || ! is_int($item['bytes'] ?? null) || $item['bytes'] < 0
                    || ! is_string($item['sha256'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/', $item['sha256'])) {
                    throw new RuntimeException('Invalid attachment manifest metadata.');
                }

                $record = Attachment::query()->find($id);
                if (! $record || ($record->storage_disk ?: 'local') !== $item['disk']
                    || $record->storage_path !== $item['storage_path']
                    || (int) $record->size_bytes !== $item['bytes']) {
                    throw new RuntimeException('Restored attachment metadata mismatch.');
                }

                $absolute = rtrim($directory, '/').'/'.$file;
                if (! is_file($absolute) || is_link($absolute)
                    || filesize($absolute) !== $item['bytes']
                    || ! hash_equals($item['sha256'], (string) hash_file('sha256', $absolute))) {
                    throw new RuntimeException('Private attachment object checksum mismatch.');
                }
            }

            $this->info('Attachment export and database record checks passed: '.count($seen));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Attachment export verification failed; do not restore these objects.');
            return self::FAILURE;
        }
    }
}
