<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Finance\PostingFeedSummary;
use Illuminate\Console\Command;

/**
 * Mencetak ringkasan feed posting finance sebagai satu baris JSON, untuk agen situs (TODO feed posting finance 14.1).
 *
 * Pembacanya `susun_laporan` di `deploy/agent/coreerp-agent`, lewat `docker compose exec core-app php artisan` —
 * jalan yang sama dengan `tenant:bootstrap-site`, tanpa endpoint HTTP dan tanpa kredensial baru. Isinya dijelaskan
 * di `PostingFeedSummary`.
 *
 * Bentuknya janji kepada agen, bukan keluaran untuk dibaca orang: agen membaca seluruh stdout sebagai satu nilai JSON,
 * menyusun ulang `finance_feed` kunci demi kunci, dan melaporkan `null` untuk bentuk lain. Karena itu tidak ada
 * keluaran lain di stdout, dan kunci baru di sini tidak sampai ke admin.erp sebelum agen, `openapi-agent.yaml`, dan
 * validasi laporan di konsol ikut berubah. `FinanceFeedSummaryTest` memaku bentuknya.
 */
final class SummarizeFinanceFeed extends Command
{
    protected $signature = 'finance-postings:summary';

    protected $description = 'Cetak ringkasan feed posting finance — jumlah per status dan waktu, tanpa isi jurnal — sebagai JSON untuk agen situs.';

    public function handle(PostingFeedSummary $ringkasan): int
    {
        $this->line(json_encode($ringkasan->read(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
