<?php
namespace App\Support;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fail closed when private owner document records diverge from their files.
 *
 * Invoke while all database and filesystem writers are stopped. This audit
 * does not replace a post-restore drill against the recovered database.
 */
final class BackupAttachmentAudit
{
    public static function verify(): int
    {
        $disk=Storage::disk('local');
        $expected=[];
        foreach (Document::query()->select(['id','user_id','path','size'])->cursor() as $document) {
            $path=(string)$document->path;
            $owner=(int)$document->user_id;
            if ($owner<1 || !preg_match('~^owners/'.preg_quote((string)$owner,'~').'/documents/[A-Za-z0-9_./-]+$~D',$path)
                || str_contains($path,'..') || isset($expected[$path])) {
                throw new RuntimeException('Document records contain invalid or duplicate private paths.');
            }
            $size=$disk->size($path);
            if (!is_int($size) || $size<0 || $size!==(int)$document->size) {
                throw new RuntimeException('A private document is missing or its size differs from its record.');
            }
            $expected[$path]=true;
        }

        $seen=0;
        foreach ($disk->allFiles('owners') as $path) {
            if (!isset($expected[$path])) {
                throw new RuntimeException('Private owner documents contain an untracked file.');
            }
            $seen++;
        }
        if ($seen!==count($expected)) {
            throw new RuntimeException('Private document listing does not match database records.');
        }
        return $seen;
    }
}
