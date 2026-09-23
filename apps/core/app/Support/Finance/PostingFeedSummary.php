<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\FinancePosting;
use App\Models\IntegrationClient;
use Illuminate\Support\Carbon;

/**
 * Ringkasan kesehatan feed posting finance untuk admin.erp (TODO feed posting finance 14.1, K-02).
 *
 * Yang keluar dari sini hanya angka dan waktu: jumlah posting per status, jam terbit posting `pending`
 * tertua, dan jam tarikan terakhir. Tidak ada nomor posting, dokumen sumber, akun, dimensi, vendor, maupun
 * nilai uang. Ringkasan ini dibawa agen situs ke admin.erp, sedangkan data keuangan tenant tidak boleh keluar
 * dari server tempat datanya berada (K-02). Pembacanya perintah `finance-postings:summary`.
 *
 * **Lingkupnya seluruh database ini, dijumlahkan lintas tenant.** admin.erp menampilkannya per server klien,
 * dan satu server klien hari ini melayani satu tenant. Memecahnya per tenant berarti menaruh id tenant di
 * laporan agen tanpa satu layar pun yang membutuhkannya.
 *
 * **Umur `pending` dihitung dari jam terbit**, bukan dari tanggal akuntansi atau jam posting itu menjadi
 * `pending`. Posting tertahan yang divalidasi ulang tetap membawa jam terbitnya (`PostingPublisher::revalidate`),
 * jadi ia langsung terbaca tua — dan memang sudah selama itu jurnalnya belum sampai ke aplikasi finance.
 */
final class PostingFeedSummary
{
    /**
     * Status yang dihitung, dalam urutan kontrak `finance_feed.counts` di `openapi-agent.yaml`.
     *
     * Harus sama dengan CHECK `finance_postings_status_check`; `FinanceFeedSummaryTest` membandingkan keduanya.
     * Status baru di tabel tanpa baris di sini akan hilang diam-diam dari jumlahnya.
     */
    public const STATUSES = [
        FinancePosting::HELD,
        FinancePosting::PENDING,
        FinancePosting::POSTED,
        FinancePosting::REJECTED,
        FinancePosting::MANUAL,
    ];

    /**
     * @return array{counts: array<string, int>, oldest_pending_at: ?string, last_pulled_at: ?string}
     */
    public function read(): array
    {
        $jumlah = FinancePosting::query()
            ->toBase()
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        $counts = [];
        foreach (self::STATUSES as $status) {
            $counts[$status] = (int) ($jumlah[$status] ?? 0);
        }

        return [
            'counts' => $counts,
            'oldest_pending_at' => $this->utc(FinancePosting::query()->where('status', FinancePosting::PENDING)->min('published_at')),
            // Klien mode pull mana pun, termasuk yang sudah dicabut: jam tarikannya tetap tarikan terakhir yang terjadi.
            'last_pulled_at' => $this->utc(IntegrationClient::query()->max('last_pulled_at')),
        ];
    }

    /**
     * Kolom waktu Core disimpan tanpa zona, dalam zona aplikasi. Yang dikirim selalu UTC berakhiran `Z`, satu
     * bentuk yang diterima agen dan admin.erp.
     */
    private function utc(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        return Carbon::parse((string) $nilai, (string) config('app.timezone'))->toIso8601ZuluString();
    }
}
