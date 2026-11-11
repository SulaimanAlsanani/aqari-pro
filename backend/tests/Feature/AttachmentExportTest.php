<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentExportTest extends TestCase
{
    use RefreshDatabase;

    private array $createdDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDirectories as $directory) {
            if (is_dir($directory)) {
                \Illuminate\Support\Facades\File::deleteDirectory($directory);
            }
        }
        parent::tearDown();
    }

    private function newDirectory(): string
    {
        $directory = sys_get_temp_dir().'/aqari-export-test-'.bin2hex(random_bytes(10));
        mkdir($directory, 0700);
        $this->createdDirectories[] = $directory;
        return $directory;
    }

    private function attachment(string $disk, string $body): Attachment
    {
        $user = User::factory()->create();
        $owner = Owner::query()->forceCreate([
            'user_id' => $user->id,
            'name' => 'Recovery Owner',
        ]);
        $property = $owner->properties()->create([
            'name' => 'Building',
            'city' => 'Riyadh',
        ]);
        $path = 'owner-documents/'.$owner->id.'/test-document.bin';
        Storage::disk($disk)->put($path, $body);

        return Attachment::query()->create([
            'owner_id' => $owner->id,
            'property_id' => $property->id,
            'original_name' => 'private-document.pdf',
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($body),
        ]);
    }

    public function test_empty_attachment_export_and_verification_pass(): void
    {
        $directory = $this->newDirectory();
        $this->artisan('aqari:export-attachments', ['directory' => $directory])->assertExitCode(0);
        $this->artisan('aqari:verify-attachment-export', ['directory' => $directory])->assertExitCode(0);
        $manifest = json_decode(file_get_contents($directory.'/attachments.json'), true);
        $this->assertSame(0, $manifest['count']);
    }

    public function test_private_local_and_s3_bytes_are_exported_and_verifiable(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        $first = $this->attachment('local', 'Document alpha content');
        $second = $this->attachment('s3', 'Document beta content');

        $directory = $this->newDirectory();
        $this->artisan('aqari:export-attachments', ['directory' => $directory])->assertExitCode(0);
        $this->artisan('aqari:verify-attachment-export', ['directory' => $directory])->assertExitCode(0);

        $manifest = json_decode(file_get_contents($directory.'/attachments.json'), true);
        $this->assertSame(2, $manifest['count']);
        $this->assertSame('Document alpha content', file_get_contents($directory.'/objects/'.$first->id.'.bin'));
        $this->assertSame('Document beta content', file_get_contents($directory.'/objects/'.$second->id.'.bin'));
        $this->assertSame('s3', $manifest['objects'][1]['disk']);
        $this->assertNotEquals($first->id, $second->id);
    }

    public function test_corruption_and_missing_backup_objects_are_rejected(): void
    {
        Storage::fake('local');
        $record = $this->attachment('local', 'original contents');
        $directory = $this->newDirectory();
        $this->artisan('aqari:export-attachments', ['directory' => $directory])->assertExitCode(0);

        $file = $directory.'/objects/'.$record->id.'.bin';
        file_put_contents($file, 'changed contents');
        $this->artisan('aqari:verify-attachment-export', ['directory' => $directory])->assertExitCode(1);

        unlink($file);
        $this->artisan('aqari:verify-attachment-export', ['directory' => $directory])->assertExitCode(1);
    }

    public function test_export_refuses_nonempty_directory_and_missing_live_objects(): void
    {
        Storage::fake('local');
        $record = $this->attachment('local', 'expected content');
        $directory = $this->newDirectory();
        file_put_contents($directory.'/preexisting.txt', 'leave untouched');

        $this->artisan('aqari:export-attachments', ['directory' => $directory])->assertExitCode(1);
        $this->assertFileExists($directory.'/preexisting.txt');

        $fresh = $this->newDirectory();
        Storage::disk('local')->delete($record->storage_path);
        $this->artisan('aqari:export-attachments', ['directory' => $fresh])->assertExitCode(1);
        $this->assertFileDoesNotExist($fresh.'/attachments.json');
    }

    public function test_export_manifest_requires_current_database_record_metadata(): void
    {
        Storage::fake('local');
        $record = $this->attachment('local', 'valid content');
        $directory = $this->newDirectory();
        $this->artisan('aqari:export-attachments', ['directory' => $directory])->assertExitCode(0);

        $record->forceFill(['size_bytes' => 1000])->save();
        $this->artisan('aqari:verify-attachment-export', ['directory' => $directory])->assertExitCode(1);
    }
}
