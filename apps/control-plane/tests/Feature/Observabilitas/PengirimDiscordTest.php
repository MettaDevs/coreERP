<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Support\Observabilitas\LaporanKesalahan;
use App\Support\Observabilitas\PengirimDiscord;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Penjaga untuk pengiriman laporan ke Discord.
 *
 * Tiga sifat yang dijaga di sini, dan ketiganya pernah menjadi kesalahan orang lain sebelum
 * menjadi test: sebutan harus berada di tempat yang benar-benar membunyikan notifikasi, isi
 * pesan tidak boleh bisa menyulut sebutan yang tidak diniatkan, dan satu kesalahan yang
 * berulang tidak boleh berubah menjadi banjir.
 */
class PengirimDiscordTest extends TestCase
{
    private const WEBHOOK = 'https://discord.test/api/webhooks/1/rahasia';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bersihkanPenjeda();

        config()->set('coreerp.discord.webhook_url', self::WEBHOOK);
        config()->set('coreerp.discord.mention', '@everyone');
        config()->set('coreerp.discord.jeda_detik', 0);
        config()->set('coreerp.signoz_url', 'http://signoz.test:3301');
    }

    protected function tearDown(): void
    {
        $this->bersihkanPenjeda();

        parent::tearDown();
    }

    /**
     * Penanda penjeda hidup di volume yang dibagi, jadi sisa dari jalan sebelumnya akan
     * menahan kiriman yang seharusnya lolos. Hanya berkas test ini yang menyentuh folder itu:
     * di lingkungan test webhooknya kosong, sehingga pengirim berhenti sebelum menyentuh
     * penjeda.
     */
    private function bersihkanPenjeda(): void
    {
        foreach (glob(storage_path('logs/.penjeda-kiriman').'/*') ?: [] as $berkas) {
            @unlink($berkas);
        }
    }

    private function laporan(string $pesan = 'gagal'): LaporanKesalahan
    {
        return LaporanKesalahan::dari(new RuntimeException($pesan), null);
    }

    public function test_tidak_mengirim_apa_pun_ketika_webhook_kosong(): void
    {
        // Ini keadaan bawaan setiap pemasangan on-prem: channel Discord-nya bukan milik kita,
        // jadi diam adalah jawaban yang benar — bukan kegagalan yang ditelan diam-diam.
        config()->set('coreerp.discord.webhook_url', '');
        Http::fake();

        PengirimDiscord::kirim($this->laporan());

        Http::assertNothingSent();
    }

    public function test_sebutan_berada_di_content_dan_dinyatakan_pada_allowed_mentions(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        PengirimDiscord::kirim($this->laporan());

        Http::assertSent(function (PermintaanHttp $permintaan): bool {
            $isi = $permintaan->data();

            $this->assertStringStartsWith('@everyone', (string) $isi['content']);

            // Tanpa pernyataan ini Discord mencetak sebutannya tetapi tidak membunyikan apa
            // pun — kegagalan yang paling mahal karena pesannya tetap terlihat benar.
            $this->assertSame(['parse' => ['everyone']], $isi['allowed_mentions']);

            return true;
        });
    }

    public function test_laporan_utuh_dikirim_sebagai_embed(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        PengirimDiscord::kirim($this->laporan('kolom tidak ditemukan'));

        Http::assertSent(function (PermintaanHttp $permintaan): bool {
            $embed = $permintaan->data()['embeds'][0];

            $this->assertSame(RuntimeException::class, $embed['title']);
            $this->assertStringContainsString('kolom tidak ditemukan', (string) $embed['description']);

            // Blok kode, supaya `#` dan `*` di dalam pesan driver tidak dibaca sebagai
            // markdown dan SQL yang gagal tetap terbaca apa adanya.
            $this->assertStringStartsWith("```\n", (string) $embed['description']);

            return true;
        });
    }

    public function test_garis_pemisah_tidak_ikut_terkirim(): void
    {
        // Garis itu menandai batas antar laporan pada berkas yang ditulis sambung-menyambung.
        // Satu pesan Discord sudah menjadi batasnya sendiri, jadi di sana ia hanya menghabiskan
        // tempat pada layar yang sempit.
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        PengirimDiscord::kirim($this->laporan());

        Http::assertSent(function (PermintaanHttp $permintaan): bool {
            $this->assertStringNotContainsString('─', (string) $permintaan->data()['embeds'][0]['description']);

            return true;
        });
    }

    public function test_tautan_signoz_menyaring_tepat_laporan_ini(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        $laporan = $this->laporan();
        $id = (string) $laporan->keAtribut()['coreerp.laporan_id'];

        PengirimDiscord::kirim($laporan);

        Http::assertSent(function (PermintaanHttp $permintaan) use ($id): bool {
            $embed = $permintaan->data()['embeds'][0];
            $tautan = urldecode((string) $embed['url']);

            // Bukan penjelajah kosong. Saringan atas `coreerp.laporan_id` menyisakan tepat
            // satu catatan, dan itu berlaku juga untuk perintah artisan dan pekerja antrean
            // yang tidak punya `trace_id` — dua tempat yang tanpa ini tidak punya jalan
            // apa pun untuk ditemukan.
            $this->assertStringStartsWith('http://signoz.test:3301/logs/logs-explorer?relativeTime=1d', $tautan);
            $this->assertStringContainsString("coreerp.laporan_id = '".$id."'", $tautan);
            $this->assertStringContainsString('[Buka catatannya di SigNoz]', (string) $embed['description']);

            return true;
        });
    }

    public function test_id_laporan_ikut_tercetak_supaya_bisa_dicocokkan_dengan_mata(): void
    {
        // Id yang hanya ada di tautan tidak menolong orang yang memegang berkas lognya.
        $laporan = $this->laporan();

        $this->assertStringContainsString(
            (string) $laporan->keAtribut()['coreerp.laporan_id'],
            $laporan->keTeks(),
        );
    }

    public function test_tanpa_alamat_signoz_tidak_ada_tautan_kosong(): void
    {
        config()->set('coreerp.signoz_url', '');
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        PengirimDiscord::kirim($this->laporan());

        Http::assertSent(function (PermintaanHttp $permintaan): bool {
            $embed = $permintaan->data()['embeds'][0];

            $this->assertArrayNotHasKey('url', $embed);
            $this->assertStringNotContainsString('SigNoz', (string) $embed['description']);

            return true;
        });
    }

    public function test_laporan_kepanjangan_dipotong_tanpa_penanda_dan_tetap_muat(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        PengirimDiscord::kirim($this->laporan(str_repeat('nilai yang sangat panjang ', 500)));

        Http::assertSent(function (PermintaanHttp $permintaan): bool {
            $isi = (string) $permintaan->data()['embeds'][0]['description'];

            // Batas Discord untuk description adalah 4096; melewatinya berarti seluruh pesan
            // ditolak, bukan dipotong — jadi yang hilang bukan ekornya melainkan semuanya.
            $this->assertLessThanOrEqual(4096, mb_strlen($isi));

            // Yang dibutuhkan pembaca bukan pemberitahuan bahwa ada yang hilang, melainkan
            // jalan menuju yang utuh.
            $this->assertStringNotContainsString('(dipotong)', $isi);
            $this->assertStringContainsString('[Buka catatannya di SigNoz]', $isi);

            return true;
        });
    }

    public function test_sebutan_di_dalam_pesan_kesalahan_tidak_ikut_berbunyi(): void
    {
        // Pesan kesalahan bisa memuat data pengguna. Sebuah nilai yang kebetulan berbunyi
        // "@everyone" tidak boleh berubah menjadi sebutan sungguhan hanya karena ia gagal
        // divalidasi.
        config()->set('coreerp.discord.mention', '<@&99>');
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        PengirimDiscord::kirim($this->laporan('nilai ditolak: @everyone @here'));

        Http::assertSent(function (PermintaanHttp $permintaan): bool {
            $izin = $permintaan->data()['allowed_mentions'];

            $this->assertSame(['roles' => ['99']], $izin);
            $this->assertArrayNotHasKey('parse', $izin);

            return true;
        });
    }

    public function test_kesalahan_yang_sama_hanya_dikirim_sekali_dalam_satu_jeda(): void
    {
        config()->set('coreerp.discord.jeda_detik', 300);
        Http::fake([self::WEBHOOK => Http::response('', 204)]);

        $laporan = $this->laporan();

        PengirimDiscord::kirim($laporan);
        PengirimDiscord::kirim($laporan);
        PengirimDiscord::kirim($laporan);

        // Satu kali membuka halaman daftar sudah menghasilkan dua permintaan yang gagal
        // dengan sebab yang sama; database yang mati menghasilkan ratusan.
        Http::assertSentCount(1);
    }

    public function test_discord_yang_mati_tidak_menjadi_kesalahan_kedua(): void
    {
        Http::fake(fn () => throw new RuntimeException('jaringan diblokir'));

        PengirimDiscord::kirim($this->laporan());

        // Tidak ada assertion selain ketiadaan lemparan: kelas ini berjalan di dalam penangan
        // kesalahan, dan lemparan dari sana menimpa kesalahan asli dengan kesalahan tentang
        // pelaporannya.
        $this->expectNotToPerformAssertions();
    }
}
