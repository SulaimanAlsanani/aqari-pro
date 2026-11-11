<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Owner;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AttachmentController extends Controller
{
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
        // Trust validated file content, not any user-controlled filename or folder.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $file->getClientOriginalName()));
        $name = mb_substr($name ?: 'document', 0, 180);

        $disk = Storage::disk('local'); // Laravel 12 local disk uses storage/app/private.
        $path = $disk->putFile('owner-documents/'.$owner->id, $file);
        if ($path === false) {
            throw new RuntimeException('Private attachment write failed.');
        }

        try {
            $attachment = Attachment::query()->create([
                'owner_id' => $owner->id,
                $parentKey => $parentId,
                'original_name' => $name,
                'storage_path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $attachment], 201);
    }

    public function download(Request $request, Attachment $attachment)
    {
        $owner = $this->owner($request);
        $attachment = Attachment::query()->where('owner_id', $owner->id)
            ->whereKey($attachment->id)->firstOrFail();

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->storage_path), 404);

        $response = $disk->download($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', 'sandbox');

        return $response;
    }
}
