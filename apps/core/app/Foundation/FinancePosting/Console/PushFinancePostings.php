<?php

namespace App\Foundation\FinancePosting\Console;

use App\Foundation\FinancePosting\Support\PostingPusher;
use Illuminate\Console\Command;

/**
 * Mengirim posting finance `pending` ke klien integrasi mode `push` (TODO 6.10).
 *
 * Dijalankan penjadwal setiap menit. Satu putaran mengirim paling banyak `--limit` posting per klien;
 * sisanya menunggu putaran berikutnya. Aturan percobaan ulang dan urutan ada di `PostingPusher`.
 */
class PushFinancePostings extends Command
{
    protected $signature = 'finance-postings:push {--limit=100 : Posting paling banyak per klien dalam satu putaran}';

    protected $description = 'Kirim posting finance yang siap ke klien integrasi mode push.';

    public function handle(PostingPusher $pusher): int
    {
        $result = $pusher->run(max(1, (int) $this->option('limit')));

        if ($result['skipped'] !== null) {
            $this->info('Tidak mengirim apa pun. '.$result['skipped']);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d klien: %d terkirim, %d menunggu dicoba lagi, %d gagal.',
            $result['clients'],
            $result['sent'],
            $result['retrying'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
