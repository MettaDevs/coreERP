<?php

namespace App\Platform\Attachments\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Lampiran dokumen pada satu record, atau pada satu baris dokumennya (gap 7, K-09), padanan `Document
 * Attachment` di Business Central. Satu tabel untuk semua record; jenis record yang boleh diberi lampiran
 * didaftarkan pemilik tabelnya lewat `AttachmentRecordTypes`.
 *
 * Bawaan tabel `CustomerContent`, sama dengan BC. Klasifikasi isi berkas per baris ada di `data_class`,
 * disalin dari jenis record induknya. Nama berkas ditulis sebagai data pribadi karena orang menamai berkas
 * dengan nama orang ("KTP Budi.pdf"), apa pun induknya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $record_type
 * @property string $record_id
 * @property string $kind
 * @property ?int $line_number
 * @property string $file_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $storage_path
 * @property string $content_hash
 * @property string $data_class
 * @property ?int $created_by_user_id
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
#[DataClassification(DataClass::CustomerContent)]
class DocumentAttachment extends Model
{
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'file_name' => DataClass::EndUserIdentifiableInformation,
        'storage_path' => DataClass::SystemMetadata,
        'content_hash' => DataClass::SystemMetadata,
        'data_class' => DataClass::SystemMetadata,
        'kind' => DataClass::SystemMetadata,
    ];

    protected $fillable = [
        'tenant_id', 'record_type', 'record_id', 'line_number', 'file_name', 'mime_type', 'size_bytes',
        'storage_path', 'content_hash', 'data_class', 'kind',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'size_bytes' => 'integer',
            'created_by_user_id' => 'integer',
            'version' => 'integer',
        ];
    }
}
