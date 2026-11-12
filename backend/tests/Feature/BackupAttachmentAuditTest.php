<?php
namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Support\BackupAttachmentAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class BackupAttachmentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function document(string $path, int $size): Document
    {
        $owner=User::create([
            'name'=>'Backup audit owner',
            'email'=>'backup-audit-'.uniqid().'@example.test',
            'password'=>'a-long-test-password',
        ]);
        return Document::create([
            'user_id'=>$owner->id,
            'entity_type'=>'owner',
            'entity_id'=>$owner->id,
            'path'=>$path,
            'original_name'=>'receipt.pdf',
            'mime'=>'application/pdf',
            'size'=>$size,
        ]);
    }

    public function test_empty_document_store_passes(): void
    {
        self::assertSame(0, BackupAttachmentAudit::verify());
    }

    public function test_document_and_file_match(): void
    {
        $owner=User::create(['name'=>'Owner','email'=>'audit-match@example.test','password'=>'a-long-test-password']);
        $path='owners/'.$owner->id.'/documents/file.pdf';
        $payload='PDF sample';
        Storage::disk('local')->put($path,$payload);
        Document::create([
            'user_id'=>$owner->id,'entity_type'=>'owner','entity_id'=>$owner->id,
            'path'=>$path,'original_name'=>'file.pdf','mime'=>'application/pdf','size'=>strlen($payload),
        ]);
        self::assertSame(1,BackupAttachmentAudit::verify());
    }

    public function test_orphan_private_file_is_rejected(): void
    {
        Storage::disk('local')->put('owners/1/documents/untracked.pdf','orphan');
        $this->expectException(RuntimeException::class);
        BackupAttachmentAudit::verify();
    }

    public function test_wrong_document_size_is_rejected(): void
    {
        $owner=User::create(['name'=>'Owner','email'=>'audit-size@example.test','password'=>'a-long-test-password']);
        $path='owners/'.$owner->id.'/documents/file.pdf';
        Storage::disk('local')->put($path,'short');
        Document::create([
            'user_id'=>$owner->id,'entity_type'=>'owner','entity_id'=>$owner->id,
            'path'=>$path,'original_name'=>'file.pdf','mime'=>'application/pdf','size'=>9999,
        ]);
        $this->expectException(RuntimeException::class);
        BackupAttachmentAudit::verify();
    }
}
