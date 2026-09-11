<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Support\Observabilitas\TersangkaPemotongan;
use PHPUnit\Framework\TestCase;

/**
 * Penjaga: kesalahan "nilai tidak muat" menunjuk nilai mana, bukan menyerahkan pekerjaan
 * menghitung tanda tanya kepada pembaca laporan.
 *
 * Ini satu-satunya jenis kesalahan database yang pesannya tidak pernah menyebut penyebabnya.
 * Driver hanya berkata data akan terpotong; kolom mana dan nilai mana tidak pernah ikut.
 */
class TersangkaPemotonganTest extends TestCase
{
    public function test_kolom_terpanjang_muncul_paling_atas_dengan_namanya(): void
    {
        $daftar = TersangkaPemotongan::daftar(
            'insert into "GD_trMutasiDetail" ("Barang_ID", "Kode_Satuan", "No_Batch") values (?, ?, ?)',
            ['52501', 'Vial + Ampul Pelarut', '202506137AX'],
        );

        $this->assertSame('Kode_Satuan', $daftar[0]['kolom']);
        $this->assertSame(20, $daftar[0]['panjang']);
        $this->assertStringContainsString('Vial + Ampul Pelarut', $daftar[0]['cuplikan']);
    }

    public function test_bentuk_update_juga_dikenali(): void
    {
        $daftar = TersangkaPemotongan::daftar(
            'update "aset" set "kode" = ?, "keterangan" = ? where "id" = ?',
            ['AST-1', str_repeat('x', 300), 'id-1'],
        );

        $this->assertSame('keterangan', $daftar[0]['kolom']);
        $this->assertSame(300, $daftar[0]['panjang']);
    }

    public function test_query_yang_tidak_dikenali_jatuh_ke_penomoran(): void
    {
        // Menebak nama kolom dari bentuk yang tidak dipahami akan menempelkan nama yang salah
        // pada nilai yang benar — mengirim pembaca ke arah yang keliru dengan penuh keyakinan.
        $daftar = TersangkaPemotongan::daftar(
            'insert into "aset" select * from "aset_impor" where "kode" = ?',
            ['AST-999'],
        );

        $this->assertSame('#1', $daftar[0]['kolom']);
    }

    public function test_nilai_bukan_string_dilewati(): void
    {
        $daftar = TersangkaPemotongan::daftar(
            'insert into "aset" ("kode", "jumlah", "aktif") values (?, ?, ?)',
            ['AST-1', 42, true],
        );

        $this->assertCount(1, $daftar);
        $this->assertSame('kode', $daftar[0]['kolom']);
    }

    public function test_cuplikan_nilai_panjang_dipotong(): void
    {
        $daftar = TersangkaPemotongan::daftar(
            'insert into "aset" ("catatan") values (?)',
            [str_repeat('a', 500)],
        );

        $this->assertSame(500, $daftar[0]['panjang']);
        $this->assertStringEndsWith('…', $daftar[0]['cuplikan']);
        $this->assertLessThan(100, mb_strlen($daftar[0]['cuplikan']));
    }

    public function test_pengenalan_lewat_sqlstate_maupun_pesan(): void
    {
        $this->assertTrue(TersangkaPemotongan::cocok('22001', null));
        // SQL Server lewat ODBC sering datang tanpa SQLSTATE yang rapi.
        $this->assertTrue(TersangkaPemotongan::cocok(null, 'String or binary data would be truncated.'));
        $this->assertTrue(TersangkaPemotongan::cocok(null, 'value too long for type character varying(10)'));
        $this->assertFalse(TersangkaPemotongan::cocok('23505', 'duplicate key value'));
    }

    public function test_batas_kolom_dari_pesan_driver_menaikkan_penyebab_sebenarnya(): void
    {
        // Kasus nyata yang ditemukan saat uji ujung-ke-ujung: `catatan` bertipe `text` berisi
        // 320 karakter kalah panjang dari apa pun, tetapi sama sekali sehat. Yang gagal adalah
        // `kode_satuan`, sebuah `varchar(8)` berisi 20 karakter. Mengurutkan sekadar dari yang
        // terpanjang menunjuk kolom yang salah dengan penuh keyakinan.
        $batas = TersangkaPemotongan::batasDariPesan('value too long for type character varying(8)');
        $this->assertSame(8, $batas);

        $daftar = TersangkaPemotongan::daftar(
            'insert into "uji" ("kode", "kode_satuan", "catatan") values (?, ?, ?)',
            ['AST-1', 'Vial + Ampul Pelarut', str_repeat('x', 320)],
            $batas,
        );

        // Keduanya melebihi batas 8 — itu sebabnya menyaring dengan batas saja tidak cukup.
        // Yang menentukan adalah kedekatannya: 20 pada batas 8 jauh lebih mungkin berasal dari
        // kolom yang memang sesempit itu daripada 320, yang bentuknya khas isi kolom `text`.
        $this->assertSame('kode_satuan', $daftar[0]['kolom'], 'yang paling dekat di atas batas harus teratas, bukan yang terpanjang');
        $this->assertTrue($daftar[0]['melebihi']);
        $this->assertSame('catatan', $daftar[1]['kolom']);
        $this->assertTrue($daftar[1]['melebihi']);

        // Yang tidak melebihi batas selalu di bawah keduanya.
        $this->assertSame('kode', $daftar[2]['kolom']);
        $this->assertFalse($daftar[2]['melebihi']);
    }

    public function test_tanpa_batas_di_pesan_jatuh_ke_urutan_panjang(): void
    {
        // SQL Server lewat ODBC tidak menyebut batas kolomnya, jadi yang tersisa hanya
        // petunjuk — dan laporan harus tetap memberi petunjuk itu, bukan diam.
        $this->assertNull(TersangkaPemotongan::batasDariPesan('String or binary data would be truncated.'));

        $daftar = TersangkaPemotongan::daftar(
            'insert into "uji" ("a", "b") values (?, ?)',
            ['pendek', str_repeat('y', 99)],
            null,
        );

        $this->assertSame('b', $daftar[0]['kolom']);
        $this->assertFalse($daftar[0]['melebihi']);
    }
}
