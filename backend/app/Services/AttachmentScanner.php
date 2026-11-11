<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Process\Process;
use Throwable;

class AttachmentScanner
{
    public function assertClean(UploadedFile $file): void
    {
        // Scan the server's temporary uploaded file before writing any object
        // to permanent storage. The command is passed as an argument array,
        // never as interpolated shell text.
        $binary = config('aqari_documents.clamav_binary', 'clamscan');
        $timeout = max(1, min(120, (int) config('aqari_documents.clamav_timeout_seconds', 30)));
        $path = $file->getRealPath();

        if (! $path || ! is_file($path)) {
            throw new ServiceUnavailableHttpException(null, 'تعذر التحقق من الملف المرفوع.');
        }

        $scanner = new Process([$binary, '--no-summary', '--', $path]);
        $scanner->setTimeout($timeout);

        try {
            $scanner->run();
        } catch (Throwable $exception) {
            report($exception);
            // A broken scanner must NEVER silently allow the upload.
            throw new ServiceUnavailableHttpException(null, 'خدمة فحص المرفقات غير متاحة.');
        }

        if ($scanner->getExitCode() === 1) {
            throw ValidationException::withMessages([
                'file' => ['تم رفض الملف بسبب نتيجة الفحص الأمني.'],
            ]);
        }

        if ($scanner->getExitCode() !== 0) {
            throw new ServiceUnavailableHttpException(null, 'تعذر إكمال الفحص الأمني للملف.');
        }
    }
}
