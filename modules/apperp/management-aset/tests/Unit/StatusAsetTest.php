<?php

namespace Modules\Apperp\ManagementAset\Tests\Unit;

use Modules\Apperp\ManagementAset\Support\StatusAset;
use PHPUnit\Framework\TestCase;

/**
 * Arti tiap status siklus hidup aset, diuji tanpa database.
 *
 * Yang dijaga di sini bukan sekadar isi tiap method, melainkan bahwa **tidak ada satu
 * status pun yang menyamar sebagai status lain**. Nilai `in_use` yang dulu ada gugur
 * justru di pertanyaan itu: ia ditulis satu jalur dan tidak pernah membuat satu pun
 * keputusan berbeda, sehingga keberadaannya hanya menambah label.
 */
class StatusAsetTest extends TestCase
{
    public function test_hanya_tiga_status_yang_dikenal(): void
    {
        $this->assertSame(['received', 'decommissioned', 'disposed'], StatusAset::semua());
    }

    public function test_aset_yang_diterima_boleh_dikerjakan_dan_dipindahkan(): void
    {
        $status = StatusAset::DITERIMA;

        $this->assertTrue(StatusAset::bolehDibuatkanWorkOrder($status));
        $this->assertTrue(StatusAset::bolehDimutasi($status));
        $this->assertTrue(StatusAset::bolehDidekomisioning($status));
        $this->assertTrue(StatusAset::bolehDikoreksi($status));
        // Belum boleh dijual: dekomisioning harus disetujui lebih dahulu.
        $this->assertFalse(StatusAset::bolehDilepas($status));
    }

    public function test_aset_yang_dihentikan_berhenti_menerima_pekerjaan_tetapi_masih_boleh_dikoreksi(): void
    {
        $status = StatusAset::DIHENTIKAN;

        $this->assertFalse(StatusAset::bolehDibuatkanWorkOrder($status));
        $this->assertFalse(StatusAset::bolehDimutasi($status));
        $this->assertFalse(StatusAset::bolehDidekomisioning($status));
        // Justru sekarang boleh dijual atau dimusnahkan.
        $this->assertTrue(StatusAset::bolehDilepas($status));
        // Dan salah ketik namanya masih boleh dibetulkan.
        $this->assertTrue(StatusAset::bolehDikoreksi($status));
    }

    public function test_aset_yang_dilepas_tertutup_untuk_segalanya(): void
    {
        $status = StatusAset::DILEPAS;

        $this->assertFalse(StatusAset::bolehDibuatkanWorkOrder($status));
        $this->assertFalse(StatusAset::bolehDimutasi($status));
        $this->assertFalse(StatusAset::bolehDidekomisioning($status));
        $this->assertFalse(StatusAset::bolehDilepas($status));
        $this->assertFalse(StatusAset::bolehDikoreksi($status));
    }

    public function test_dua_status_yang_tidak_lagi_beredar(): void
    {
        $this->assertSame(
            [StatusAset::DIHENTIKAN, StatusAset::DILEPAS],
            StatusAset::tidakLagiBeredar(),
        );
    }

    /**
     * Status yang tidak dikenal diperlakukan seperti aset hidup, bukan seperti aset mati.
     *
     * Baris lama masih dapat menyimpan `in_use` — nilai yang tidak ditulis lagi sejak 17
     * September 2026 tetapi tidak ikut dihapus dari data yang sudah ada. Memperlakukannya
     * sebagai "tidak boleh apa-apa" akan membekukan aset yang sebenarnya masih dipakai,
     * dan pembekuan itu tidak akan berbunyi sebagai kesalahan di mana pun.
     */
    public function test_nilai_lama_yang_tidak_dikenal_tidak_membekukan_asetnya(): void
    {
        foreach (['in_use', null, ''] as $status) {
            $this->assertTrue(StatusAset::bolehDibuatkanWorkOrder($status));
            $this->assertTrue(StatusAset::bolehDimutasi($status));
            $this->assertTrue(StatusAset::bolehDikoreksi($status));
            // Tetapi ia juga bukan izin untuk melepas: itu menuntut status yang tepat.
            $this->assertFalse(StatusAset::bolehDilepas($status));
        }
    }
}
