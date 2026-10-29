<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Attachments;

use App\Platform\Attachments\Models\DocumentAttachment;
use App\Platform\Attachments\Support\AttachmentRecordTypeRegistry;
use App\Platform\Modules\Contracts\AttachmentFlowTarget;
use App\Platform\Modules\Contracts\AttachmentRecordType;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\RecordDocuments;
use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecordDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $sourceId;

    private RecordDocuments $documents;

    private DocumentFlowRecordType $source;

    private DocumentFlowRecordType $target;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['coreerp.attachments.disk' => 's3']);
        $this->tenantId = (string) Str::ulid();
        $this->sourceId = (string) Str::ulid();
        $tenant = $this->createMock(TenantContext::class);
        $tenant->method('tenantId')->willReturn($this->tenantId);
        $this->app->instance(TenantContext::class, $tenant);
        $registry = new AttachmentRecordTypeRegistry;
        $this->source = new DocumentFlowRecordType('test_source', $this->tenantId, [$this->sourceId]);
        $this->target = new DocumentFlowRecordType('test_target', $this->tenantId, []);
        $registry->register($this->source);
        $registry->register($this->target);
        $this->app->instance(AttachmentRecordTypes::class, $registry);
        $this->documents = $this->app->make(RecordDocuments::class);
    }

    public function test_draft_uploads_share_limits_and_validate_content_before_any_write(): void
    {
        $limits = $this->documents->limits();
        $this->assertSame(10, $limits['max_files']);
        $this->assertContains('pdf', $limits['extensions']);
        $this->assertSame([], $this->documents->validateUploads(null));
        $this->assertCount(1, $this->documents->validateUploads([$this->pdf()]));
        foreach (['not files', array_fill(0, 11, $this->pdf()), [UploadedFile::fake()->create('large.pdf', $limits['max_kb'] + 1, 'application/pdf')], [UploadedFile::fake()->createWithContent('script.exe', 'MZ')]] as $files) {
            try {
                $this->documents->validateUploads($files);
                $this->fail('Invalid upload was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, '<?php echo 1;');
        try {
            $this->documents->validateUploads([new UploadedFile($path, 'not-a-pdf.pdf', null, null, true)]);
            $this->fail('Content mismatch was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachments.0', $exception->errors());
        } finally {
            unlink($path);
        }
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_total_upload_size_rejects_individually_valid_files_before_any_write(): void
    {
        config(['coreerp.attachments.max_kb' => 10240, 'coreerp.attachments.max_total_kb' => 10]);
        $this->assertSame(10, $this->documents->limits()['max_total_kb']);
        $this->assertSame([], $this->documents->validateUploads([]));
        $first = UploadedFile::fake()->create('first.pdf', 6, 'application/pdf');
        $second = UploadedFile::fake()->create('second.pdf', 6, 'application/pdf');
        $this->assertCount(1, $this->documents->validateUploads([$first]));
        $this->assertCount(1, $this->documents->validateUploads([$second]));
        try {
            $this->documents->append('test_source', $this->sourceId, [$first, $second]);
            $this->fail('Combined upload size was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachments', $exception->errors());
        }
        $this->assertSame(0, DocumentAttachment::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_append_obeys_record_rights_and_outer_rollback_removes_new_binary(): void
    {
        $this->documents->append('test_source', $this->sourceId, [$this->pdf()]);
        $saved = DocumentAttachment::query()->sole();
        Storage::disk('s3')->assertExists($saved->storage_path);
        $this->assertSame(hash('sha256', $this->pdf()->getContent()), $saved->content_hash);
        foreach ([false, true] as $readable) {
            $this->source->readable = $readable;
            $this->source->changeable = false;
            try {
                $this->documents->append('test_source', $this->sourceId, [$this->pdf()]);
                $this->fail('Record rights were bypassed.');
            } catch (HttpException $exception) {
                $this->assertSame($readable ? 403 : 404, $exception->getStatusCode());
            }
        }
        $this->source->changeable = true;
        try {
            DB::transaction(function (): void {
                $this->documents->append('test_source', $this->sourceId, [$this->pdf()]);
                $this->assertCount(2, Storage::disk('s3')->allFiles());
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame(1, DocumentAttachment::query()->count());
        $this->assertSame([$saved->storage_path], Storage::disk('s3')->allFiles());
    }

    public function test_committed_inner_savepoint_upload_is_removed_when_outer_transaction_rolls_back(): void
    {
        try {
            DB::transaction(function (): void {
                DB::transaction(function (): void {
                    $this->documents->append('test_source', $this->sourceId, [$this->pdf()]);
                });
                $this->assertCount(1, Storage::disk('s3')->allFiles());
                throw new RuntimeException('outer rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('outer rollback', $exception->getMessage());
        }
        $this->assertSame(0, DocumentAttachment::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_flow_reuses_binary_filters_source_lines_and_does_not_restore_archived_references(): void
    {
        $this->documents->append('test_source', $this->sourceId, [$this->pdf()]);
        $header = DocumentAttachment::query()->sole();
        $this->copySource($header, ['line_number' => 1, 'storage_path' => 'line-one']);
        $this->copySource($header, ['line_number' => 2, 'storage_path' => 'line-two']);
        $this->copySource($header, ['kind' => 'picture', 'storage_path' => 'picture']);
        $this->copySource($header, ['tenant_id' => (string) Str::ulid(), 'storage_path' => 'foreign']);
        $firstId = (string) Str::ulid();
        $secondId = (string) Str::ulid();
        $this->target->records = [
            ['record_id' => $firstId, 'source_line_number' => 1],
            ['record_id' => $secondId, 'source_line_number' => 2],
        ];
        $filesBefore = Storage::disk('s3')->allFiles();
        $this->documents->flow('test_source', $this->sourceId, 'test_target');
        $targets = DocumentAttachment::query()->where('record_type', 'test_target')->get();
        $this->assertCount(4, $targets);
        $this->assertSame([$header->storage_path, 'line-one'], $targets->where('record_id', $firstId)->pluck('storage_path')->all());
        $this->assertSame([$header->storage_path, 'line-two'], $targets->where('record_id', $secondId)->pluck('storage_path')->all());
        $this->assertSame($header->content_hash, $targets->first()->content_hash);
        $this->assertSame($header->data_class, $targets->first()->data_class);
        $this->assertTrue($targets->every(fn (DocumentAttachment $attachment): bool => $attachment->line_number === null));
        $archived = $targets->first();
        $archived->delete();
        $this->documents->flow('test_source', $this->sourceId, 'test_target');
        $this->assertSame(4, DocumentAttachment::withTrashed()->where('record_type', 'test_target')->count());
        $this->assertNotNull($archived->fresh()->deleted_at);
        $this->assertSame($filesBefore, Storage::disk('s3')->allFiles());
    }

    public function test_flow_batches_target_reads_and_rejects_different_module_or_unreadable_source(): void
    {
        $this->documents->append('test_source', $this->sourceId, [$this->pdf()]);
        for ($index = 0; $index < 501; $index++) {
            $this->target->records[] = ['record_id' => (string) Str::ulid(), 'source_line_number' => $index + 1];
        }
        DB::enableQueryLog();
        $this->documents->flow('test_source', $this->sourceId, 'test_target');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $targetReads = array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], '"storage_path" in'));
        $this->assertCount(2, $targetReads);
        $this->assertSame(501, DocumentAttachment::query()->where('record_type', 'test_target')->count());
        $this->target->module = 'other-module';
        try {
            $this->documents->flow('test_source', $this->sourceId, 'test_target');
            $this->fail('Cross-module flow was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->source->readable = false;
        try {
            $this->documents->flow('test_source', $this->sourceId, 'test_target');
            $this->fail('Unreadable source was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    /** @param array<string, mixed> $attributes */
    private function copySource(DocumentAttachment $source, array $attributes): void
    {
        $source->replicate()->forceFill($attributes)->save();
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }
}

final class DocumentFlowRecordType implements AttachmentFlowTarget, AttachmentRecordType
{
    public bool $readable = true;

    public bool $changeable = true;

    public string $module = 'test-module';

    /** @var list<array{record_id: string, source_line_number: ?int}> */
    public array $records = [];

    /** @param list<string> $ids */
    public function __construct(private readonly string $type, private readonly string $tenant, private readonly array $ids) {}

    public function recordType(): string
    {
        return $this->type;
    }

    public function moduleId(): ?string
    {
        return $this->module;
    }

    public function dataClass(): DataClass
    {
        return DataClass::CustomerContent;
    }

    public function canRead(string $tenantId, string $recordId): bool
    {
        return $this->readable && $tenantId === $this->tenant && in_array($recordId, $this->ids, true);
    }

    public function canChange(string $tenantId, string $recordId): bool
    {
        return $this->changeable && $this->canRead($tenantId, $recordId);
    }

    public function hasLine(string $tenantId, string $recordId, int $lineNumber): bool
    {
        return false;
    }

    public function inheritedAttachmentRecords(string $tenantId, string $sourceType, string $sourceId): iterable
    {
        yield from $this->records;
    }
}
