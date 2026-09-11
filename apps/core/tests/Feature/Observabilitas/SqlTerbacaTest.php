<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Support\Observabilitas\SqlTerbaca;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Penjaga: rekonstruksi query hanya boleh terjadi ketika ia pasti benar.
 *
 * Sifat yang paling penting di sini bukan seberapa rapi hasilnya, melainkan kapan kelas ini
 * **menolak** menghasilkan apa pun. Query yang disusun ulang dari data yang tidak konsisten
 * tetap terlihat seperti query yang sah, dan orang yang membacanya akan menelusuri baris data
 * yang tidak pernah terlibat — kesalahan yang jauh lebih mahal daripada tidak punya
 * rekonstruksi sama sekali.
 *
 * Memakai `PHPUnit\Framework\TestCase` polos: kelas yang diuji tidak menyentuh container,
 * database, maupun I/O apa pun, dan menggantungkannya pada PostgreSQL berarti sifat di atas
 * berhenti terjaga setiap kali basis data test kebetulan tidak menyala.
 */
class SqlTerbacaTest extends TestCase
{
    public function test_binding_disisipkan_pada_urutan_yang_benar(): void
    {
        $hasil = SqlTerbaca::gabungkan(
            'insert into "aset" ("kode", "nama", "jumlah") values (?, ?, ?)',
            ['AST-001', 'Mesin A', 12],
        );

        $this->assertSame(
            'insert into "aset" ("kode", "nama", "jumlah") values (\'AST-001\', \'Mesin A\', 12)',
            $hasil,
        );
    }

    public function test_jumlah_binding_tidak_cocok_menghasilkan_null(): void
    {
        // Dua tanda tanya, satu nilai. Menebak yang kedua berarti menerbitkan query yang
        // salah dengan penuh percaya diri.
        $this->assertNull(SqlTerbaca::gabungkan(
            'select * from "aset" where "kode" = ? and "tenant_id" = ?',
            ['AST-001'],
        ));

        // Kebalikannya juga ditolak.
        $this->assertNull(SqlTerbaca::gabungkan(
            'select * from "aset" where "kode" = ?',
            ['AST-001', 'tenant-1'],
        ));
    }

    public function test_binding_bernama_ditolak_bukan_disisipkan_setengah(): void
    {
        $this->assertNull(SqlTerbaca::gabungkan(
            'select * from "aset" where "kode" = :kode',
            ['kode' => 'AST-001'],
        ));
    }

    public function test_tanpa_binding_query_dikembalikan_apa_adanya(): void
    {
        $sql = 'select count(*) from "aset"';

        $this->assertSame($sql, SqlTerbaca::gabungkan($sql, []));
    }

    public function test_tiap_jenis_nilai_punya_bentuk_harfiahnya(): void
    {
        $hasil = SqlTerbaca::gabungkan(
            'values (?, ?, ?, ?, ?)',
            [null, true, false, 3.5, new DateTimeImmutable('2026-09-11 08:30:00+07:00')],
        );

        $this->assertNotNull($hasil);
        $this->assertStringContainsString('NULL', $hasil);
        $this->assertStringContainsString('true', $hasil);
        $this->assertStringContainsString('false', $hasil);
        $this->assertStringContainsString('3.5', $hasil);
        $this->assertStringContainsString('2026-09-11 08:30:00', $hasil);
    }

    public function test_kutip_tunggal_di_dalam_nilai_tidak_memecah_query(): void
    {
        $hasil = SqlTerbaca::gabungkan('values (?)', ["Apotek O'Brien"]);

        // Tanpa penggandaan kutip, nilai ini menutup string lebih awal dan sisa query
        // terbaca sebagai perintah — bentuk yang sama persis dengan injeksi SQL, meski di
        // sini korbannya hanya berkas log.
        $this->assertSame("values ('Apotek O''Brien')", $hasil);
    }

    public function test_nilai_biner_tidak_ikut_masuk_berkas_log(): void
    {
        $hasil = SqlTerbaca::gabungkan('values (?)', ["\xff\xfe\x00binary"]);

        $this->assertNotNull($hasil);
        $this->assertStringContainsString('<biner', $hasil);
        $this->assertStringNotContainsString("\xff", $hasil);
    }

    public function test_query_sangat_panjang_dipotong(): void
    {
        $hasil = SqlTerbaca::gabungkan('values (?)', [str_repeat('a', 20000)]);

        $this->assertNotNull($hasil);
        $this->assertStringEndsWith('(dipotong)', $hasil);
        $this->assertLessThan(20000, mb_strlen($hasil));
    }
}
