<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Owner;
use App\Models\Property;
use App\Services\AttachmentScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use RuntimeException;
use Throwable;

class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentScanner $scanner)
    {
    }

    private function owner(Request $request): Owner
    {
        return Owner::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    private function authorizedProperty(Owner $owner, Property $property): Property
    {
        return Property::query()->where('owner_id', $owner->id)
            ->whereKey($property->id)->firstOrFail();
    }

    private function authorizedContract(Owner $owner, Contract $contract): Contract
    {
        return Contract::query()->whereKey($contract->id)
            ->whereHas('unit.property', fn ($q) => $q->where('owner_id', $owner->id))
            ->firstOrFail();
    }

    private function selectedDisk(): string
    {
        $disk = (string) config('aqari_documents.disk', 'local');

        if (! in_array($disk, ['local', 's3'], true)) {
            throw new ServiceUnavailableHttpException(null, 'قرص تخزين المستندات غير مهيأ.');
        }

        return $disk;
    }

    private function attachmentForOwner(Request $request, Attachment $attachment): Attachment
    {
        $owner = $this->owner($request);

        return Attachment::query()->where('owner_id', $owner->id)
            ->whereKey($attachment->id)->firstOrFail();
    }

    public function propertyIndex(Request $request, Property $property): JsonResponse
    {
        $owner = $this->owner($request);
        $property = $this->authorizedProperty($owner, $property);

        return response()->json(
            Attachment::query()->where('owner_id', $owner->id)
                ->where('property_id', $property->id)
                ->orderByDesc('id')->paginate(20)
        );
    }

    public function propertyStore(Request $request, Property $property): JsonResponse
    {
        $owner = $this->owner($request);
        $this->authorizedProperty($owner, $property);

        return $this->storeFile($request, $owner, 'property_id', $property->id);
    }

    public function contractIndex(Request $request, Contract $contract): JsonResponse
    {
        $owner = $this->owner($request);
        $contract = $this->authorizedContract($owner, $contract);

        return response()->json(
            Attachment::query()->where('owner_id', $owner->id)
                ->where('contract_id', $contract->id)
                ->orderByDesc('id')->paginate(20)
        );
    }

    public function contractStore(Request $request, Contract $contract): JsonResponse
    {
        $owner = $this->owner($request);
        $this->authorizedContract($owner, $contract);

        return $this->storeFile($request, $owner, 'contract_id', $contract->id);
    }

    private function storeFile(Request $request, Owner $owner, string $parentKey, int $parentId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        // Fail closed: ClamAV must inspect the temporary file before any permanent write.
        $this->scanner->assertClean($file);

        // Never use an untrusted filename for storage paths.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $file->getClientOriginalName()));
        $name = mb_substr($name ?: 'document', 0, 180);
        $diskName = $this->selectedDisk();
        $disk = Storage::disk($diskName);
        $path = $disk->putFile('owner-documents/'.$owner->id, $file, [
            'visibility' => 'private',
        ]);

        if ($path === false) {
            throw new RuntimeException('Private attachment write failed.');
        }

        try {
            $attachment = DB::transaction(function () use ($request, $owner, $parentKey, $parentId, $name, $file, $diskName, $path) {
                $record = Attachment::query()->create([
                    'owner_id' => $owner->id,
                    $parentKey => $parentId,
                    'original_name' => $name,
                    'storage_path' => $path,
                    'storage_disk' => $diskName,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                ]);

                $record->events()->create([
                    'actor_user_id' => $request->user()->getAuthIdentifier(),
                    'action' => 'uploaded',
                ]);

                return $record;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $attachment], 201);
    }

    public function events(Request $request, Attachment $attachment): JsonResponse
    {
        $record = $this->attachmentForOwner($request, $attachment);

        return response()->json($record->events()->orderByDesc('id')->paginate(20));
    }

    public function download(Request $request, Attachment $attachment)
    {
        $record = $this->attachmentForOwner($request, $attachment);
        $diskName = $record->storage_disk ?: 'local';

        if (! in_array($diskName, ['local', 's3'], true)) {
            throw new ServiceUnavailableHttpException(null, 'قرص تخزين المستندات غير مهيأ.');
        }

        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($record->storage_path), 404);

        // This audit event denotes an authorized download response, not a
        // guarantee the browser completed receiving all response bytes.
        $record->events()->create([
            'actor_user_id' => $request->user()->getAuthIdentifier(),
            'action' => 'download_requested',
        ]);

        $response = $disk->download($record->storage_path, $record->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', 'sandbox');

        return $response;
    }
}
