<?php

namespace App\Platform\Attachments\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Catatan FactBox mengikuti hak dan klasifikasi record induk, sama seperti lampiran. */
#[DataClassification(DataClass::CustomerContent)]
class DocumentNote extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'record_type', 'record_id', 'content', 'data_class'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_by_user_id' => 'integer'];
    }
}
