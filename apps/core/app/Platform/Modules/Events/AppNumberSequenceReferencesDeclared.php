<?php

declare(strict_types=1);

namespace App\Platform\Modules\Events;

/**
 * Manifest sebuah app menyatakan referensi urutan nomornya.
 *
 * Event internal Core. Dikirim sinkron di dalam transaksi pendaftaran katalog, jadi gagal menyimpan
 * referensi membatalkan seluruh pendaftaran manifest.
 */
final class AppNumberSequenceReferencesDeclared
{
    /** @param  list<array{code:string,name:string,default_prefix:?string,allowed_scopes:list<string>,default_settings?:array{allow_automatic?:bool,allow_manual?:bool,minimum_number?:int,maximum_number?:int,segments?:list<array<string,mixed>>}|null}>  $references */
    public function __construct(
        public readonly string $appId,
        public readonly array $references,
    ) {}
}
