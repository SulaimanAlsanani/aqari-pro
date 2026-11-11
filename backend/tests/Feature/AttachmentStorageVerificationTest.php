<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentStorageVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function attachment(string $disk = 'local', string $content = 'valid-pdf'): Attachment
    {
        $user = User::factory()->create();
        $owner = Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'Owner']);
        $property = $owner->properties()->create(['name' => 'Building', 'city' => 'Riyadh']);
        $path = "owner-documents/{$owner->id}/example.pdf";

        if ($disk === 'local' || $disk === 's3') {
            Storage::disk($disk)->put($path, $content);
        }

        return Attachment::query()->create([
            'owner_id' => $owner->id,
            'property_id' => $property->id,
            'original_name' => 'example.pdf',
            'storage_path' => $path,
            'storage_disk' => $disk,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
        ]);
    }

    public function test_empty_and_healthy_private_storage_passes(): void
    {
        Storage::fake('local');
        $this->artisan('aqari:verify-attachments')->assertExitCode(0);

        $this->attachment();
        $this->artisan('aqari:verify-attachments')->assertExitCode(0);
    }

    public function test_missing_attachment_object_causes_nonzero_exit_code(): void
    {
        Storage::fake('local');
        $record = $this->attachment();
        Storage::disk('local')->delete($record->storage_path);

        $this->artisan('aqari:verify-attachments')->assertExitCode(1);
    }

    public function test_object_size_mismatch_fails_even_when_file_exists(): void
    {
        Storage::fake('local');
        $record = $this->attachment();
        Storage::disk('local')->put($record->storage_path, 'truncated');

        $this->artisan('aqari:verify-attachments')->assertExitCode(1);
    }

    public function test_unrecognized_or_public_storage_disks_are_rejected(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->attachment('public');

        $this->artisan('aqari:verify-attachments')->assertExitCode(1);
    }

    public function test_s3_records_are_checked_on_their_original_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        $this->attachment('s3');

        $this->artisan('aqari:verify-attachments')->assertExitCode(0);
    }
}
