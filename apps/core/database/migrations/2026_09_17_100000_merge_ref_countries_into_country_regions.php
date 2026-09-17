<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu tabel negara, bukan dua.
 *
 * Repo ini sempat punya dua daftar negara yang tidak pernah saling melihat:
 *
 * - `country_regions` — dibuat bersama buku alamat, kunci ISO 3166-1 alpha-2, nama
 *   Indonesia, dan dirujuk `postal_addresses` lewat foreign key. Inilah negara yang
 *   dipakai alamat sungguhan.
 * - `ref_countries` — dibuat bersama master wilayah, kolom kode `varchar(3)` yang
 *   diisi alpha-2 maupun alpha-3, dan dirujuk lima tabel wilayah.
 *
 * Akibatnya satu negara dapat hidup dua kali dengan nama berbeda, dan master wilayah
 * tidak pernah menjadi master bagi alamat. Migrasi ini menyatukannya ke
 * `country_regions`, memindahkan kolom yang hanya dimiliki `ref_countries`
 * (`phone_code`, `timezone`, `active`), lalu membuang `ref_countries`.
 *
 * Dikerjakan dua langkah, mengikuti aturan mundur pada
 * docs/dev/03-release-and-on-prem.md: rilis ini **berhenti memakai** `ref_countries`
 * — foreign key dialihkan, kode dan seeder pindah — tetapi tabelnya ditinggal utuh
 * supaya mundur ke image sebelumnya tetap menemukan daftar negara yang dibacanya.
 * Rilis berikutnya yang membuangnya.
 *
 * @kompatibel-mundur Kolom kode negara disempitkan dari varchar(3) ke char(2), dan itu
 * tetap aman bagi kode rilis sebelumnya: sejak konsolidasi 9 September 2026 seluruh
 * nilainya memang alpha-2, dan halaman Address setup tidak pernah menyimpan alpha-3.
 *
 * Kode tiga huruf yang tersisa di tabel anak dipetakan lewat `country_regions.iso3`,
 * bukan dipotong dua huruf: memotong `AUT` menghasilkan `AU` (Australia), padahal
 * Austria adalah `AT`. Bila ada nilai yang tidak dapat dipetakan, migrasi berhenti
 * dan menyebutkan barisnya — lebih baik gagal daripada memindahkan alamat ke negara
 * yang salah.
 */
return new class extends Migration
{
    /**
     * Tabel anak yang menunjuk negara, beserta nama kolomnya.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const CHILDREN = [
        ['ref_provinces', 'country_code'],
        ['ref_postal_codes', 'country_code'],
        ['ref_address_parameters', 'country_code'],
        ['ref_administrative_divisions', 'country_id'],
        ['ref_country_hierarchy_levels', 'country_code'],
        ['time_zones', 'country_code'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->tambahKolomKeCountryRegions();
            $this->pindahkanIsiRefCountries();
            $this->petakanKodeTigaHurufDiTabelAnak();
            $this->pastikanSetiapKodeAnakDikenal();
            $this->alihkanForeignKey();

            // `ref_countries` sengaja ditinggal, tidak dibuang. Mundur ke rilis sebelumnya
            // hanya mengganti image dan tidak memulihkan database; kode rilis lama masih
            // membaca tabel ini, jadi membuangnya sekarang membuat mundur berujung 500.
            // Isinya dibiarkan apa adanya sebagai bekal mundur, tidak ada lagi yang
            // menulisinya, dan tidak ada satu pun foreign key yang menunjuknya.
            // Rilis berikutnya yang membuangnya — lihat GAB-24 pada
            // docs/todo/buku-alamat-global/.
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // Negara yang lahir sesudah penggabungan belum pernah dikenal `ref_countries`;
            // tanpa baris ini, memasang kembali foreign key lama akan gagal.
            DB::statement('
                insert into ref_countries (code, iso3, name, phone_code, timezone, active, created_at, updated_at)
                select cr.code, cr.iso3, cr.name, cr.phone_code, coalesce(cr.timezone, \'UTC+07:00\'), cr.active, cr.created_at, cr.updated_at
                from country_regions cr
                where not exists (select 1 from ref_countries rc where rc.code = cr.code)
            ');

            foreach (self::CHILDREN as [$tabel, $kolom]) {
                $fk = $this->namaForeignKey($tabel, $kolom);
                DB::statement("alter table {$tabel} drop constraint if exists {$fk}");
                DB::statement("alter table {$tabel} alter column {$kolom} type varchar(3) using {$kolom}::varchar(3)");

                if ($tabel !== 'ref_country_hierarchy_levels') {
                    DB::statement("alter table {$tabel} add constraint {$fk} foreign key ({$kolom}) references ref_countries(code) on delete cascade");
                }
            }

            Schema::table('country_regions', function (Blueprint $table): void {
                $table->dropColumn(['phone_code', 'timezone', 'active']);
            });
        });
    }

    /** Kolom yang selama ini hanya dimiliki `ref_countries`. */
    private function tambahKolomKeCountryRegions(): void
    {
        Schema::table('country_regions', function (Blueprint $table): void {
            $table->string('phone_code', 10)->nullable()->after('name');
            $table->string('timezone', 50)->nullable()->after('phone_code');
            // Negara yang dimatikan tidak muncul di pilihan alamat, tetapi barisnya
            // tetap ada karena alamat lama boleh jadi masih menunjuknya.
            $table->boolean('active')->default(true)->after('timezone');
        });

        // `iso3` tidak lagi wajib: negara yang ditambahkan sendiri lewat halaman
        // Address setup belum tentu punya kode alpha-3.
        DB::statement('alter table country_regions alter column iso3 drop not null');
    }

    private function pindahkanIsiRefCountries(): void
    {
        // Negara yang sudah ada di kedua tabel: ambil kolom tambahannya saja. Namanya
        // sengaja tidak ditimpa — `country_regions` memakai nama Indonesia.
        DB::statement('
            update country_regions cr
            set phone_code = rc.phone_code,
                timezone = rc.timezone,
                active = rc.active,
                updated_at = now()
            from ref_countries rc
            where rc.code = cr.code
        ');

        // Negara yang hanya ada di `ref_countries` ikut pindah. `iso3` dikosongkan bila
        // sudah dipakai baris lain, karena kolom itu unique.
        DB::statement('
            insert into country_regions (code, iso3, name, phone_code, timezone, active, created_at, updated_at)
            select rc.code,
                   case
                       when rc.iso3 is null or rc.iso3 = \'\' then null
                       when exists (select 1 from country_regions c2 where c2.iso3 = rc.iso3) then null
                       else rc.iso3
                   end,
                   rc.name, rc.phone_code, rc.timezone, rc.active, now(), now()
            from ref_countries rc
            where length(rc.code) = 2
              and not exists (select 1 from country_regions cr where cr.code = rc.code)
        ');
    }

    /** `IDN` menjadi `ID` lewat `iso3`, bukan lewat pemotongan dua huruf pertama. */
    private function petakanKodeTigaHurufDiTabelAnak(): void
    {
        foreach (self::CHILDREN as [$tabel, $kolom]) {
            DB::statement("
                update {$tabel} anak
                set {$kolom} = cr.code
                from country_regions cr
                where length(trim(anak.{$kolom})) = 3
                  and upper(trim(anak.{$kolom})) = cr.iso3
            ");
        }

        // Zona waktu per divisi menyimpan kode negara sebagai teks biasa, tanpa foreign key.
        if (Schema::hasTable('ref_administrative_division_timezones')) {
            DB::statement("
                update ref_administrative_division_timezones t
                set division_id = cr.code
                from country_regions cr
                where t.division_type = 'country'
                  and length(trim(t.division_id)) = 3
                  and upper(trim(t.division_id)) = cr.iso3
            ");
        }
    }

    /**
     * Berhenti bila masih ada kode yang bukan dua huruf atau tidak dikenal
     * `country_regions`. Tanpa pemeriksaan ini, `alter column` akan memotong nilainya
     * diam-diam dan barisnya berpindah ke negara lain.
     */
    private function pastikanSetiapKodeAnakDikenal(): void
    {
        $masalah = [];

        foreach (self::CHILDREN as [$tabel, $kolom]) {
            /** @var list<object{nilai: string, jumlah: int}> $baris */
            $baris = DB::select("
                select anak.{$kolom} as nilai, count(*) as jumlah
                from {$tabel} anak
                where anak.{$kolom} is not null
                  and not exists (select 1 from country_regions cr where cr.code = upper(trim(anak.{$kolom})))
                group by anak.{$kolom}
                order by count(*) desc
                limit 5
            ");

            foreach ($baris as $b) {
                $masalah[] = sprintf('%s.%s = "%s" (%d baris)', $tabel, $kolom, $b->nilai, $b->jumlah);
            }
        }

        if ($masalah !== []) {
            throw new RuntimeException(
                'Penggabungan tabel negara dihentikan: ada kode negara yang tidak dikenal '.
                "country_regions.\n- ".implode("\n- ", $masalah)."\n".
                'Perbaiki atau hapus baris itu lebih dulu, lalu jalankan ulang migrasinya.',
            );
        }
    }

    private function alihkanForeignKey(): void
    {
        foreach (self::CHILDREN as [$tabel, $kolom]) {
            $fk = $this->namaForeignKey($tabel, $kolom);

            DB::statement("alter table {$tabel} drop constraint if exists {$fk}");
            DB::statement("update {$tabel} set {$kolom} = upper(trim({$kolom})) where {$kolom} is not null");
            DB::statement("alter table {$tabel} alter column {$kolom} type char(2) using {$kolom}::char(2)");

            // `restrict`, bukan `cascade`: menghapus satu negara tidak boleh menghapus
            // seluruh provinsi, kode pos, dan zona waktunya tanpa ada yang bertanya.
            DB::statement("alter table {$tabel} add constraint {$fk} foreign key ({$kolom}) references country_regions(code) on delete restrict");
        }
    }

    private function namaForeignKey(string $tabel, string $kolom): string
    {
        return "{$tabel}_{$kolom}_foreign";
    }
};
