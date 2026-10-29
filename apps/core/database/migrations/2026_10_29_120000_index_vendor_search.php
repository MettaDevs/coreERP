<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ekspresi ini dibekukan; perubahan engine membutuhkan migration indeks baru.
        DB::statement("CREATE INDEX vendors_number_text_search ON vendors USING gin (to_tsvector('simple'::regconfig, regexp_replace(normalize(coalesce(CAST(number AS text), ''), NFC), '[^[:alnum:]]+', ' ', 'g') || ' ' || regexp_replace(normalize(coalesce(CAST(number AS text), ''), NFC), '[[:space:].\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff-]+', '', 'g')))");
        DB::statement("CREATE INDEX vendors_tax_number_text_search ON vendors USING gin (to_tsvector('simple'::regconfig, regexp_replace(normalize(coalesce(CAST(tax_number AS text), ''), NFC), '[^[:alnum:]]+', ' ', 'g') || ' ' || regexp_replace(normalize(coalesce(CAST(tax_number AS text), ''), NFC), '[[:space:].\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000\ufeff-]+', '', 'g')))");
        DB::statement("CREATE INDEX parties_name_text_search ON parties USING gin (to_tsvector('simple'::regconfig, regexp_replace(normalize(coalesce(CAST(name AS text), ''), NFC), '[^[:alnum:]]+', ' ', 'g'))) WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vendors_number_text_search');
        DB::statement('DROP INDEX IF EXISTS vendors_tax_number_text_search');
        DB::statement('DROP INDEX IF EXISTS parties_name_text_search');
    }
};
