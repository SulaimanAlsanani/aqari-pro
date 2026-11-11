<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $owner = Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'Owner']);
        $property = $owner->properties()->create(['name' => 'Building', 'city' => 'Riyadh']);
        $unit = $property->units()->create(['code' => '101']);
        $tenant = $owner->tenants()->create(['name' => 'Tenant']);
        $contract = Contract::query()->create([
            'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'total' => '1000.00', 'installments' => 1, 'status' => 'draft',
        ]);

        return [$user, $owner, $property, $contract];
    }

    private function image(string $name = 'deed.png'): UploadedFile
    {
        // One valid PNG pixel: MIME validation checks content, not an extension alone.
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZsAAAAASUVORK5CYII=')
        );
    }

    public function test_owner_uploads_property_file_to_private_storage_and_downloads_it(): void
    {
        Storage::fake('local');
        [$user, $owner, $property] = $this->fixture();

        $result = $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->post("/api/v1/properties/{$property->id}/attachments", ['file' => $this->image()])
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'deed.png')
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonMissingPath('data.owner_id');

        $attachmentId = $result->json('data.id');
        $attachment = Attachment::query()->findOrFail($attachmentId);

        $this->assertSame($owner->id, $attachment->owner_id);
        $this->assertSame($property->id, $attachment->property_id);
        $this->assertNull($attachment->contract_id);
        $this->assertStringStartsWith('owner-documents/'.$owner->id.'/', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);

        $this->getJson("/api/v1/properties/{$property->id}/attachments")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.storage_path');
        $this->get("/api/v1/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_contract_documents_are_private_and_inaccessible_to_another_owner(): void
    {
        Storage::fake('local');
        [$alice] = $this->fixture();
        [$bob, , $propertyB, $contractB] = $this->fixture();

        $response = $this->actingAs($bob)->withHeader('Accept', 'application/json')
            ->post("/api/v1/contracts/{$contractB->id}/attachments", [
                'file' => $this->image('contract.png'),
            ])->assertCreated();
        $attachmentId = $response->json('data.id');
        $this->assertDatabaseHas('attachments', [
            'id' => $attachmentId, 'contract_id' => $contractB->id, 'property_id' => null,
        ]);

        $this->actingAs($alice);
        $this->getJson("/api/v1/contracts/{$contractB->id}/attachments")->assertNotFound();
        $this->post("/api/v1/contracts/{$contractB->id}/attachments", [
            'file' => $this->image('theft.png'),
        ])->assertNotFound();
        $this->get("/api/v1/attachments/{$attachmentId}/download")->assertNotFound();
        $this->assertDatabaseCount('attachments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_file_type_and_size_limits_reject_unsafe_uploads_without_writing_files(): void
    {
        Storage::fake('local');
        [$user, , $property] = $this->fixture();
        $this->actingAs($user)->withHeader('Accept', 'application/json');

        $this->post("/api/v1/properties/{$property->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('evil.html', '<script>alert(1)</script>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->post("/api/v1/properties/{$property->id}/attachments", [
            'file' => UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('attachments', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_guest_cannot_list_upload_or_download_documents(): void
    {
        [$user, , $property] = $this->fixture();
        $this->getJson("/api/v1/properties/{$property->id}/attachments")->assertUnauthorized();
        $this->post("/api/v1/properties/{$property->id}/attachments", [
            'file' => $this->image(),
        ])->assertUnauthorized();
        $this->get('/api/v1/attachments/1/download')->assertUnauthorized();
    }

    public function test_owner_gets_404_when_private_file_is_missing(): void
    {
        Storage::fake('local');
        [$user, , $property] = $this->fixture();
        $response = $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->post("/api/v1/properties/{$property->id}/attachments", [
                'file' => $this->image(),
            ])->assertCreated();
        $attachment = Attachment::query()->findOrFail($response->json('data.id'));
        Storage::disk('local')->delete($attachment->storage_path);

        $this->get("/api/v1/attachments/{$attachment->id}/download")->assertNotFound();
    }
}
