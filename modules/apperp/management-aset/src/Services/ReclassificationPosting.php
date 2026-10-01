<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\CurrencyRounding;
use App\Platform\Modules\Contracts\InvalidPosting;
use App\Platform\Modules\Contracts\PostingFeed;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AtributAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassification;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationBook;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationLine;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use RuntimeException;
use stdClass;

/**
 * Reklasifikasi aset: memindah aset ke group aset lain, atau memecah sebagian nilainya ke aset baru, beserta
 * jurnal `asset.reclassification`. Padanan *FA Reclass. Journal* Business Central (`FA Reclass. Transfer
 * Line`, `FA Reclass. Check Line`) dan pemecahan aset Dynamics 365 F&O.
 *
 * **Yang dipindah per buku.** Seperti BC, harga perolehan, akumulasi penyusutan, penurunan nilai, kenaikan
 * nilai, dan nilai sisa dipindah bersama dengan satu perbandingan: seluruhnya pada pindah group, dan bagian
 * yang diminta pada pecah. Bagian pecah ditulis sebagai persentase (`Reclassify Acq. Cost %`) atau nilai
 * perolehan (`Reclassify Acq. Cost Amount`); nilai diubah menjadi perbandingan terhadap harga perolehan aset
 * itu, dan setiap saldo setiap buku dikali perbandingan itu lalu dibulatkan sekali ke presisi mata uang. Buku
 * yang harga perolehannya sama dengan register memindah persis nilai yang diketik. Beberapa baris pecah atas
 * aset yang sama dihitung dari saldo sebelum diposting, seperti BC menyusun semua baris jurnalnya sebelum
 * memposting; jumlahnya harus di bawah 100%.
 *
 * **Umur yang sudah berjalan ikut.** Buku aset baru menyalin aturan buku asalnya — profil, masa manfaat,
 * konvensi, tanggal mulai menyusut — dan jumlah periode yang sudah disusutkan disalin ke
 * `elapsed_periods_offset`, sehingga penyusutan berikutnya kedua aset melanjutkan periode yang sama, seperti
 * pilihan "Use source asset's remaining periods" di F&O.
 *
 * **Jurnalnya hanya bila group berubah**, dan hanya untuk buku yang di-post ke finance (K-26):
 *
 *     Dr harga perolehan            group tujuan    dimensi keuangan aset
 *        Cr harga perolehan            group asal
 *     Dr akumulasi penyusutan       group asal      unit pengguna
 *        Cr akumulasi penyusutan       group tujuan
 *     Dr akumulasi penurunan nilai  group asal      dimensi keuangan aset
 *        Cr akumulasi penurunan nilai  group tujuan
 *     Dr kenaikan nilai aset        group tujuan    dimensi keuangan aset
 *        Cr kenaikan nilai aset        group asal
 *
 * Itu yang terjadi di BC saat *Insert Bal. Account* menyeimbangkan tiap aset dengan akun posting group-nya
 * sendiri: dalam satu posting group pasangan barisnya saling meniadakan, antar posting group saldonya
 * berpindah akun. Pecah di dalam satu group hanya mengubah register. Buku lain, misalnya fiskal, selalu hanya
 * register.
 *
 * Reklasifikasi antar group hanya untuk group yang membawa aset ke finance lewat buku yang sama. Buku yang
 * di-post dipilih dari group (`PembuatAset::bukuDiPostId`), dan aset yang pindah ke group dengan buku lain
 * akan kelak dilepas dari buku yang perolehannya tidak pernah dijurnal.
 *
 * @phpstan-type HasilTerbit array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
 * @phpstan-type Bagian array{book: stdClass, acquisition: BigDecimal, accumulated: BigDecimal, write_down: BigDecimal, appreciation: BigDecimal, residual: BigDecimal}
 * @phpstan-type Rencana array{row: stdClass, from_group: string, to_group: string, journal: bool, asset_acquisition: BigDecimal, books: array<string, Bagian>}
 */
final class ReclassificationPosting
{
    public const POSTING_TYPE = 'asset.reclassification';

    public const SOURCE_TYPE = 'reklasifikasi-aset';

    private const SALDO = ['acquisition' => 'acquisition_value', 'accumulated' => 'accumulated_depreciation', 'write_down' => 'write_down_amount', 'appreciation' => 'appreciation_amount', 'residual' => 'residual_value'];

    public function __construct(
        private readonly PostingFeed $publisher,
        private readonly CurrencyRounding $precision,
        private readonly AssetPostingAccounts $accounts,
        private readonly PembuatAset $assets,
        private readonly BookPeriods $periods,
    ) {}

    public static function postingId(string $reclassificationId): string
    {
        return 'AST-RCL-'.$reclassificationId;
    }

    /**
     * Baris dokumen beserta asetnya dan buku aset aktifnya, urut nomor baris. Dengan `$lock`, buku asetnya
     * dikunci: posting mengubah saldonya.
     *
     * @return list<stdClass> kolom baris, `aset` (baris aset atau `null`), `books` (buku aktif berkunci id buku)
     */
    public function rows(AssetReclassification $header, bool $lock = false): array
    {
        $lines = AssetReclassificationLine::query()
            ->where('reklasifikasi_aset_id', $header->id)
            ->orderBy('line_number')
            ->toBase()
            ->get();
        $asetIds = $lines->pluck('aset_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all();
        $aset = Aset::withTrashed()->whereIn('id', $asetIds)->toBase()->get()->keyBy('id');
        $books = BukuAset::query()->whereIn('aset_id', $asetIds)->where('status', 'active')->orderBy('id');
        if ($lock) {
            $books->lockForUpdate();
        }
        $books = $books->toBase()->get()->groupBy('aset_id');

        return array_values($lines->map(static function (stdClass $line) use ($aset, $books): stdClass {
            $line->aset = $aset[$line->aset_id] ?? null;
            $line->books = ($books[$line->aset_id] ?? collect())->keyBy('buku_id')->all();

            return $line;
        })->all());
    }

    /**
     * Yang menahan posting, berkunci field; kosong berarti boleh. Dipakai posting dan pratinjau, supaya
     * keduanya tidak pernah menjawab berbeda. Masalah mata uang berkunci `currency_code`: dengan masalah itu
     * nilainya tidak dapat dibagi sama persis. Masalah pemetaan akun tidak di sini: postingnya tetap terbit
     * sebagai `held` dan nilainya tetap berpindah (K-18).
     *
     * @param  list<stdClass>  $rows
     * @return array<string, string>
     */
    public function blockers(AssetReclassification $header, array $rows): array
    {
        if ($rows === []) {
            return ['details' => 'Tambahkan sedikitnya satu aset sebelum memposting.'];
        }
        $mataUang = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) ($row->aset->currency_code ?? ''), $rows)));
        if (count($mataUang) > 1) {
            return ['currency_code' => sprintf('Aset di dokumen ini memakai lebih dari satu mata uang (%s). Satu reklasifikasi hanya untuk satu mata uang.', implode(', ', $mataUang))];
        }
        try {
            $desimal = $this->decimals((string) $header->tenant_id, $mataUang[0]);
        } catch (RuntimeException $kegagalan) {
            return ['currency_code' => $kegagalan->getMessage()];
        }

        $tanggal = $header->tanggal->toDateString();
        $pecah = $header->jenis === AssetReclassification::SPLIT;
        $masalah = [];
        $bagian = [];
        foreach ($rows as $indeks => $row) {
            $kode = (string) ($row->aset->kode ?? $row->aset_id);
            $kunci = 'details.'.$indeks;
            $pesan = $this->assetProblem($header, $row, $kode, $tanggal, $desimal);
            if ($pesan !== null) {
                $masalah[$kunci.'.aset_id'] = $pesan;

                continue;
            }
            $asal = (string) $row->aset->group_aset_id;
            $tujuan = $row->group_aset_tujuan_id === null ? $asal : (string) $row->group_aset_tujuan_id;
            if (! $pecah && $row->group_aset_tujuan_id === null) {
                $masalah[$kunci.'.group_aset_tujuan_id'] = sprintf('Pilih group tujuan untuk aset %s.', $kode);

                continue;
            }
            if (! $pecah && $tujuan === $asal) {
                $masalah[$kunci.'.group_aset_tujuan_id'] = sprintf('Aset %s sudah berada di group tujuan.', $kode);

                continue;
            }
            $pesan = $tujuan === $asal ? null : $this->groupProblem($asal, $tujuan);
            if ($pesan !== null) {
                $masalah[$kunci.'.group_aset_tujuan_id'] = $pesan;

                continue;
            }
            if (! $pecah) {
                continue;
            }

            $perolehan = BigDecimal::of((string) $row->aset->acquisition_value);
            $pesan = match (true) {
                $perolehan->isZero() => sprintf('Harga perolehan aset %s nol, jadi tidak ada yang dapat dipecah.', $kode),
                $row->nilai_perolehan !== null && ! BigDecimal::of((string) $row->nilai_perolehan)->isLessThan($perolehan) => sprintf(
                    'Nilai yang dipecah dari aset %s harus lebih kecil dari harga perolehannya (%s). Untuk memindah seluruhnya, pakai pindah group.',
                    $kode,
                    $perolehan->toScale(2),
                ),
                $this->share($row, $perolehan, $perolehan, $desimal)->isZero() => sprintf('Bagian yang dipecah dari aset %s terlalu kecil untuk dicatat.', $kode),
                default => null,
            };
            if ($pesan !== null) {
                $masalah[$kunci.'.'.($row->persen !== null ? 'persen' : 'nilai_perolehan')] = $pesan;

                continue;
            }
            $bagian[(string) $row->aset_id] = ($bagian[(string) $row->aset_id] ?? BigDecimal::zero())
                ->plus($row->persen !== null ? BigDecimal::of((string) $row->persen) : BigDecimal::of((string) $row->nilai_perolehan)->multipliedBy(100)->dividedBy($perolehan, 10, RoundingMode::HalfUp));
            if (! $bagian[(string) $row->aset_id]->isLessThan(100)) {
                $masalah[$kunci.'.'.($row->persen !== null ? 'persen' : 'nilai_perolehan')] = sprintf(
                    'Bagian yang dipecah dari aset %s berjumlah 100%% atau lebih. Sisakan sebagian di aset asal, atau pakai pindah group untuk memindah seluruhnya.',
                    $kode,
                );
            }
        }

        return $masalah;
    }

    /**
     * Rencana pemindahan per baris dan per buku, dihitung dari saldo sebelum diposting. Hanya untuk baris
     * tanpa penghalang.
     *
     * @param  list<stdClass>  $rows
     * @return list<Rencana>
     */
    public function plan(AssetReclassification $header, array $rows): array
    {
        $desimal = $this->decimals((string) $header->tenant_id, (string) $rows[0]->aset->currency_code);
        $pecah = $header->jenis === AssetReclassification::SPLIT;
        $rencana = [];
        foreach ($rows as $row) {
            $asal = (string) $row->aset->group_aset_id;
            $tujuan = $row->group_aset_tujuan_id === null ? $asal : (string) $row->group_aset_tujuan_id;
            $perolehanAset = BigDecimal::of((string) $row->aset->acquisition_value);
            $books = [];
            foreach ($row->books as $bukuId => $book) {
                $bagian = ['book' => $book];
                foreach (self::SALDO as $kunci => $kolom) {
                    $saldo = BigDecimal::of((string) $book->{$kolom});
                    $bagian[$kunci] = $pecah ? $this->share($row, $perolehanAset, $saldo, $desimal) : $saldo;
                }
                $books[(string) $bukuId] = $bagian;
            }
            $diPost = $this->assets->bukuDiPostId($asal);
            $rencana[] = [
                'row' => $row,
                'from_group' => $asal,
                'to_group' => $tujuan,
                // Jurnal hanya bila group berubah dan aset punya buku yang di-post ke finance.
                'journal' => $asal !== $tujuan && $diPost !== null && isset($books[$diPost]),
                'asset_acquisition' => $pecah ? $this->share($row, $perolehanAset, $perolehanAset, $desimal) : $perolehanAset,
                'books' => $books,
            ];
        }

        return $rencana;
    }

    /**
     * Pratinjau jurnal reklasifikasi (K-22) tanpa menyimpan apa pun.
     *
     * @param  list<Rencana>  $plan
     * @return array{note: ?string, posting: HasilTerbit|null}
     *
     * @throws ReclassificationPostingFailed
     */
    public function preview(AssetReclassification $header, array $plan): array
    {
        $susunan = $this->susun($header, $plan, []);

        return ['note' => $susunan['note'], 'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->preview($susunan['input']))];
    }

    /**
     * Memposting reklasifikasi **di dalam transaksi pemanggil**, yang sudah mengunci buku asetnya: jurnalnya
     * terbit dari saldo sebelum berubah, lalu nilainya berpindah, aset baru lahir (pecah), group aset berubah
     * (pindah group), dan pemindahan per buku dicatat.
     *
     * @param  list<Rencana>  $plan
     * @param  array<string, string>  $codes  Nomor aset baru berkunci id baris, untuk pecah.
     * @return array{note: ?string, posting: HasilTerbit|null}
     *
     * @throws ReclassificationPostingFailed
     */
    public function post(AssetReclassification $header, array $plan, array $codes): array
    {
        $tenant = (string) $header->tenant_id;
        $tanggal = $header->tanggal->toDateString();
        $susunan = $this->susun($header, $plan, $codes);
        $hasil = ['note' => $susunan['note'], 'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->publish($susunan['input']))];

        /** @var array<string, array<string, BigDecimal>> $keluar Jumlah yang keluar per buku aset asal. */
        $keluar = [];
        /** @var array<string, BigDecimal> $perolehanKeluar */
        $perolehanKeluar = [];
        foreach ($plan as $rencana) {
            $row = $rencana['row'];
            $asetBaru = null;
            if ($header->jenis === AssetReclassification::SPLIT) {
                $asetBaru = $this->splitAsset($header, $rencana, $codes[(string) $row->id]);
                $perolehanKeluar[(string) $row->aset_id] = ($perolehanKeluar[(string) $row->aset_id] ?? BigDecimal::zero())->plus($rencana['asset_acquisition']);
            } else {
                Aset::query()->whereKey($row->aset_id)->update(['group_aset_id' => $rencana['to_group'], 'updated_at' => now()]);
            }
            $diPost = $this->assets->bukuDiPostId($rencana['from_group']);
            foreach ($rencana['books'] as $bukuId => $bagian) {
                $tujuan = $asetBaru === null ? (string) $bagian['book']->id : $this->splitBook($asetBaru, $bagian, $tanggal);
                if ($asetBaru !== null) {
                    foreach (array_keys(self::SALDO) as $kunci) {
                        $keluar[(string) $bagian['book']->id][$kunci] = ($keluar[(string) $bagian['book']->id][$kunci] ?? BigDecimal::zero())->plus($bagian[$kunci]);
                    }
                }
                (new AssetReclassificationBook)->forceFill([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenant,
                    'reklasifikasi_aset_id' => $header->id,
                    'reklasifikasi_aset_detail_id' => $row->id,
                    'tanggal' => $tanggal,
                    'jenis' => $header->jenis,
                    'buku_id' => $bukuId,
                    'aset_asal_id' => $row->aset_id,
                    'buku_aset_asal_id' => $bagian['book']->id,
                    'group_aset_asal_id' => $rencana['from_group'],
                    'aset_tujuan_id' => $asetBaru ?? $row->aset_id,
                    'buku_aset_tujuan_id' => $tujuan,
                    'group_aset_tujuan_id' => $rencana['to_group'],
                    'nilai_perolehan' => (string) $bagian['acquisition'],
                    'akumulasi_penyusutan' => (string) $bagian['accumulated'],
                    'penurunan_nilai' => (string) $bagian['write_down'],
                    'kenaikan_nilai' => (string) $bagian['appreciation'],
                    'nilai_sisa' => (string) $bagian['residual'],
                    'dijurnal' => $rencana['journal'] && $hasil['posting'] !== null && $bukuId === $diPost,
                ])->save();
            }
            AssetReclassificationLine::query()->whereKey($row->id)->update([
                'group_aset_asal_id' => $rencana['from_group'],
                'aset_baru_id' => $asetBaru,
                'nilai_perolehan_dipindah' => (string) $rencana['asset_acquisition'],
                'updated_at' => now(),
            ]);
        }

        // Buku aset asal pecah dikurangi sekali dengan jumlah semua barisnya, dari saldo yang sudah dikunci.
        foreach ($keluar as $bukuAsetId => $jumlah) {
            $book = BukuAset::query()->whereKey($bukuAsetId)->toBase()->first();
            $baru = [];
            foreach (self::SALDO as $kunci => $kolom) {
                $baru[$kolom] = BigDecimal::of((string) $book->{$kolom})->minus($jumlah[$kunci]);
            }
            BukuAset::query()->whereKey($bukuAsetId)->update([
                ...array_map('strval', $baru),
                'net_book_value' => (string) $baru['acquisition_value']->minus($baru['accumulated_depreciation'])->minus($baru['write_down_amount'])->plus($baru['appreciation_amount']),
                'updated_at' => now(),
            ]);
        }
        foreach ($perolehanKeluar as $asetId => $jumlah) {
            $sebelum = (string) Aset::query()->whereKey($asetId)->value('acquisition_value');
            Aset::query()->whereKey($asetId)->update(['acquisition_value' => (string) BigDecimal::of($sebelum)->minus($jumlah), 'updated_at' => now()]);
        }

        return $hasil;
    }

    /**
     * Masalah yang menahan satu aset, apa pun jenis reklasifikasinya, atau `null`.
     */
    private function assetProblem(AssetReclassification $header, stdClass $row, string $kode, string $tanggal, int $desimal): ?string
    {
        if ($row->aset === null || StatusAset::sudahDilepas($row->aset->lifecycle_state)) {
            return sprintf('Aset %s sudah dilepas, jadi tidak dapat direklasifikasi.', $kode);
        }
        if (StatusAset::sudahDihentikan($row->aset->lifecycle_state)) {
            return sprintf('Aset %s sudah disetujui berhenti dipakai, jadi tidak dapat direklasifikasi.', $kode);
        }
        if ($row->aset->legal_entity_id !== $header->legal_entity_id) {
            return sprintf('Aset %s berada di entitas legal lain.', $kode);
        }
        if ($row->books === []) {
            return sprintf('Aset %s belum punya buku penyusutan, jadi tidak ada nilai yang dapat dipindah.', $kode);
        }
        foreach ($row->books as $book) {
            $pesan = $this->periods->problem((string) $book->id, $tanggal, $kode);
            if ($pesan !== null) {
                return $pesan;
            }
            foreach (self::SALDO as $kolom) {
                if (BigDecimal::of((string) $book->{$kolom})->strippedOfTrailingZeros()->getScale() > $desimal) {
                    return sprintf('Nilai buku aset %s lebih halus dari presisi mata uangnya (%d desimal), jadi tidak dapat dipindah sama persis.', $kode, $desimal);
                }
            }
        }

        return null;
    }

    /** Group tujuan masih aktif dan membawa aset ke finance lewat buku yang sama dengan group asal. */
    private function groupProblem(string $asal, string $tujuan): ?string
    {
        $grup = GroupAset::withTrashed()->whereIn('id', [$asal, $tujuan])->toBase()->get(['id', 'kode', 'nama', 'deleted_at'])->keyBy('id');
        if (($grup[$tujuan] ?? null) === null || $grup[$tujuan]->deleted_at !== null) {
            return 'Group tujuan sudah diarsipkan atau tidak ditemukan.';
        }
        $bukuAsal = $this->assets->bukuDiPostId($asal);
        $bukuTujuan = $this->assets->bukuDiPostId($tujuan);
        if ($bukuAsal === $bukuTujuan) {
            return null;
        }
        $nama = static fn (?string $buku): string => $buku === null ? 'tidak lewat buku mana pun' : 'lewat buku '.(BukuPenyusutan::withTrashed()->whereKey($buku)->value('nama') ?? $buku);

        return sprintf(
            'Group %s membawa aset ke aplikasi finance %s, sedangkan group %s %s. Reklasifikasi antar group hanya untuk group yang memakai buku yang sama.',
            $grup[$asal]->nama ?? $asal,
            $nama($bukuAsal),
            $grup[$tujuan]->nama,
            $nama($bukuTujuan),
        );
    }

    /**
     * Bagian satu saldo yang dipecah, dibulatkan sekali ke presisi mata uang: persentase dibagi 100, atau
     * nilai perolehan yang diketik dibagi harga perolehan register aset.
     */
    private function share(stdClass $row, BigDecimal $perolehanAset, BigDecimal $saldo, int $desimal): BigDecimal
    {
        if ($row->persen !== null) {
            return $saldo->multipliedBy((string) $row->persen)->dividedBy(100, $desimal, RoundingMode::HalfUp);
        }

        return $saldo->multipliedBy((string) $row->nilai_perolehan)->dividedBy($perolehanAset, $desimal, RoundingMode::HalfUp);
    }

    /**
     * Melahirkan aset baru pecahan: salinan identitas dan klasifikasi aset asal, di group tujuan, dengan
     * harga perolehan register sebesar bagiannya, penempatan dan atribut yang sama per tanggal reklasifikasi.
     * Nomor seri tidak disalin: ia milik barang fisik aset asal. Id aset barunya.
     *
     * @param  Rencana  $rencana
     */
    private function splitAsset(AssetReclassification $header, array $rencana, string $kode): string
    {
        $row = $rencana['row'];
        $asal = $row->aset;
        $tanggal = $header->tanggal->toDateString();
        $aset = Aset::query()->create([
            'tenant_id' => $header->tenant_id,
            'creation_key' => 'reklasifikasi:'.$row->id,
            'kode' => $kode,
            'nama' => trim((string) $row->nama_aset_baru) !== '' ? trim((string) $row->nama_aset_baru) : $asal->nama,
            'legal_entity_id' => $asal->legal_entity_id,
            'responsible_org_unit_id' => $asal->responsible_org_unit_id,
            'group_aset_id' => $rencana['to_group'],
            'kelompok_harta_fiskal_id' => $asal->kelompok_harta_fiskal_id,
            'jenis_aset_id' => $asal->jenis_aset_id,
            'kondisi_aset_id' => $asal->kondisi_aset_id,
            'pabrikan_aset_id' => $asal->pabrikan_aset_id,
            'model_aset_id' => $asal->model_aset_id,
            'induk_aset_id' => $asal->induk_aset_id,
            'lokasi_aset_id' => $asal->lokasi_aset_id,
            'financial_dimension_org_unit_id' => $asal->financial_dimension_org_unit_id,
            'model_number' => $asal->model_number,
            // Tanggal perolehan aset asal: pecahan bukan perolehan baru, dan umur bukunya ikut berjalan.
            'acquired_on' => $asal->acquired_on,
            'placed_in_service_on' => $asal->placed_in_service_on,
            'acquisition_value' => (string) $rencana['asset_acquisition'],
            'currency_code' => $asal->currency_code,
            'lifecycle_state' => $asal->lifecycle_state,
            'keterangan' => sprintf('Dipecah dari aset %s lewat reklasifikasi %s.', $asal->kode, $header->kode),
        ]);

        $penempatan = PenempatanAset::query()->where('aset_id', $asal->id)->whereDate('effective_on', '<=', $tanggal)
            ->orderByDesc('effective_on')->orderByDesc('id')->toBase()->first()
            ?? PenempatanAset::query()->where('aset_id', $asal->id)->orderBy('effective_on')->orderBy('id')->toBase()->first();
        if ($penempatan !== null) {
            (new PenempatanAset)->forceFill([
                'id' => (string) Str::ulid(),
                'tenant_id' => $header->tenant_id,
                'aset_id' => $aset->id,
                'receiving_org_unit_id' => $penempatan->receiving_org_unit_id,
                'usage_org_unit_id' => $penempatan->usage_org_unit_id,
                'received_by_user_id' => $penempatan->received_by_user_id,
                'custodian_user_id' => $penempatan->custodian_user_id,
                'lokasi_aset_id' => $penempatan->lokasi_aset_id,
                'effective_on' => $tanggal,
                'reason' => sprintf('Dipecah dari aset %s (%s)', $asal->kode, $header->kode),
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();
        }

        foreach (AtributAset::query()->where('aset_id', $asal->id)->toBase()->get() as $atribut) {
            (new AtributAset)->forceFill([
                'tenant_id' => $header->tenant_id,
                'aset_id' => $aset->id,
                'tipe_atribut_id' => $atribut->tipe_atribut_id,
                'nilai_text' => $atribut->nilai_text,
                'nilai_number' => $atribut->nilai_number,
                'nilai_boolean' => $atribut->nilai_boolean,
                'nilai_date' => $atribut->nilai_date,
                'tipe_atribut_nilai_id' => $atribut->tipe_atribut_nilai_id,
            ])->save();
        }

        return (string) $aset->id;
    }

    /**
     * Buku aset baru pecahan: aturan buku asal disalin, saldonya bagian yang dipindah, dan periode yang sudah
     * disusutkan buku asal sampai tanggal reklasifikasi menjadi `elapsed_periods_offset`. Id buku asetnya.
     *
     * @param  Bagian  $bagian
     */
    private function splitBook(string $asetId, array $bagian, string $tanggal): string
    {
        $asal = $bagian['book'];
        $berjalan = (int) $asal->elapsed_periods_offset + DepreciationPeriod::query()
            ->where('buku_aset_id', $asal->id)
            ->whereNull('reverses_period_id')
            ->whereDate('period_ends_on', '<=', $tanggal)
            ->count();

        return (string) BukuAset::query()->create([
            'tenant_id' => $asal->tenant_id,
            'aset_id' => $asetId,
            'buku_id' => $asal->buku_id,
            'depreciation_profile_id' => $asal->depreciation_profile_id,
            'alternative_profile_id' => $asal->alternative_profile_id,
            'book_code' => $asal->book_code,
            'useful_life_periods' => $asal->useful_life_periods,
            'convention' => $asal->convention,
            'depreciation_start_on' => $asal->depreciation_start_on,
            'depreciate' => (bool) $asal->depreciate,
            'round_off_depreciation' => $asal->round_off_depreciation,
            'acquisition_value' => (string) $bagian['acquisition'],
            'residual_value' => (string) $bagian['residual'],
            'accumulated_depreciation' => (string) $bagian['accumulated'],
            // Akumulasinya datang dari reklasifikasi, bukan dari saldo awal sistem lama.
            'opening_accumulated_depreciation' => '0',
            'elapsed_periods_offset' => $berjalan,
            'write_down_amount' => (string) $bagian['write_down'],
            'appreciation_amount' => (string) $bagian['appreciation'],
            'net_book_value' => (string) $bagian['acquisition']->minus($bagian['accumulated'])->minus($bagian['write_down'])->plus($bagian['appreciation']),
            'status' => 'active',
        ])->id;
    }

    /**
     * @param  list<Rencana>  $plan
     * @param  array<string, string>  $codes
     * @return array{note: ?string, input: array<string, mixed>|null}
     */
    private function susun(AssetReclassification $header, array $plan, array $codes): array
    {
        $dijurnal = array_values(array_filter($plan, static fn (array $rencana): bool => $rencana['journal']));
        if ($dijurnal === []) {
            $berpindah = array_filter($plan, static fn (array $rencana): bool => $rencana['from_group'] !== $rencana['to_group']);

            return ['note' => $berpindah === []
                ? 'Aset tetap di group yang sama, jadi akun buku besarnya tidak berubah dan tidak ada jurnal. Reklasifikasi ini hanya mengubah register aset.'
                : 'Group aset ini tidak punya buku yang di-post ke aplikasi finance, jadi reklasifikasi ini hanya mengubah register aset.', 'input' => null];
        }

        $tenant = (string) $header->tenant_id;
        $tanggal = $header->tanggal->toDateString();
        $mataUang = (string) $dijurnal[0]['row']->aset->currency_code;
        $desimal = $this->decimals($tenant, $mataUang);
        $grupIds = [];
        foreach ($dijurnal as $rencana) {
            $grupIds[] = $rencana['from_group'];
            $grupIds[] = $rencana['to_group'];
        }
        $grup = GroupAset::withTrashed()->whereIn('id', array_values(array_unique($grupIds)))->toBase()->get(['id', 'kode', 'nama'])->keyBy('id');
        $pengguna = $this->usageUnits(array_map(static fn (array $rencana): string => (string) $rencana['row']->aset_id, $dijurnal), $tanggal);

        $potongan = [];
        $rincian = [];
        foreach ($dijurnal as $rencana) {
            $row = $rencana['row'];
            $bagian = $rencana['books'][$this->assets->bukuDiPostId($rencana['from_group'])];
            $neraca = (string) ($row->aset->financial_dimension_org_unit_id ?? $row->aset->responsible_org_unit_id);
            $unitPengguna = $pengguna[(string) $row->aset_id] ?? (string) $row->aset->responsible_org_unit_id;
            [$asal, $tujuan] = [$rencana['from_group'], $rencana['to_group']];
            // [kolom, nilai, group yang didebit, group yang dikredit, unit]
            foreach ([
                ['acquisition_account_id', $bagian['acquisition'], $tujuan, $asal, $neraca],
                ['accumulated_depreciation_account_id', $bagian['accumulated'], $asal, $tujuan, $unitPengguna],
                ['write_down_account_id', $bagian['write_down'], $asal, $tujuan, $neraca],
                ['appreciation_account_id', $bagian['appreciation'], $tujuan, $asal, $neraca],
            ] as [$kolom, $nilai, $groupDebit, $groupKredit, $unit]) {
                if ($nilai->isZero()) {
                    continue;
                }
                foreach ([[true, $groupDebit], [false, $groupKredit]] as [$debit, $group]) {
                    $kunci = ($debit ? 'D' : 'K').'|'.$group.'|'.$kolom.'|'.$unit;
                    $potongan[$kunci] ??= ['debit' => $debit, 'group' => $group, 'column' => $kolom, 'unit' => $unit, 'amount' => BigDecimal::zero()];
                    $potongan[$kunci]['amount'] = $potongan[$kunci]['amount']->plus($nilai);
                }
            }
            $rincian[] = [
                'asset_code' => (string) $row->aset->kode,
                'new_asset_code' => $codes[(string) $row->id] ?? null,
                'from_group' => $grup[$asal]->kode ?? $asal,
                'to_group' => $grup[$tujuan]->kode ?? $tujuan,
                'book' => (string) $bagian['book']->book_code,
                'acquisition_value' => (string) $bagian['acquisition']->toScale($desimal),
                'accumulated_depreciation' => (string) $bagian['accumulated']->toScale($desimal),
                'write_down_amount' => (string) $bagian['write_down']->toScale($desimal),
                'appreciation_amount' => (string) $bagian['appreciation']->toScale($desimal),
            ];
        }
        if ($potongan === []) {
            return ['note' => 'Aset yang berpindah group tidak punya nilai di buku yang di-post ke aplikasi finance, jadi tidak ada jurnal.', 'input' => null];
        }
        // Urutan baris tetap untuk masukan yang sama: debit dulu, lalu menurut group, akun, dan unit.
        $urutanKolom = array_flip(['acquisition_account_id', 'accumulated_depreciation_account_id', 'write_down_account_id', 'appreciation_account_id']);
        uasort($potongan, static fn (array $a, array $b): int => [! $a['debit'], $grup[$a['group']]->kode ?? $a['group'], $urutanKolom[$a['column']], $a['unit']]
            <=> [! $b['debit'], $grup[$b['group']]->kode ?? $b['group'], $urutanKolom[$b['column']], $b['unit']]);

        $label = Carbon::parse($tanggal)->format('d/m/Y');
        $baris = [];
        foreach ($potongan as $potong) {
            $jumlah = (string) $potong['amount']->toScale($desimal);
            $baris[] = $this->accounts->line(
                $potong['group'],
                (string) ($grup[$potong['group']]->kode ?? $potong['group']),
                $potong['column'],
                $potong['debit'] ? $jumlah : '0',
                $potong['debit'] ? '0' : $jumlah,
                sprintf('Reklasifikasi aset %s · %s · %s', $label, $header->kode, $grup[$potong['group']]->nama ?? $potong['group']),
                $potong['unit'],
                $tanggal,
            );
        }

        return ['note' => null, 'input' => [
            'tenant_id' => $tenant,
            'posting_id' => self::postingId((string) $header->id),
            'posting_type' => self::POSTING_TYPE,
            'legal_entity_id' => (string) $header->legal_entity_id,
            'currency_code' => $mataUang,
            'posting_date' => $tanggal,
            'document_date' => $tanggal,
            'occurred_at' => now()->toIso8601String(),
            'source_document' => [
                'module' => AcquisitionPosting::MODULE,
                'type' => self::SOURCE_TYPE,
                'number' => (string) $header->kode,
                'description' => mb_substr(AssetReclassification::KINDS[$header->jenis].' '.$header->kode.': '.trim((string) $header->keterangan), 0, 255),
                'id' => (string) $header->id,
                'url' => '/management-aset/'.self::SOURCE_TYPE.'/'.$header->id,
            ],
            'lines' => $baris,
            'details' => ['assets' => $rincian, 'reason' => (string) $header->keterangan],
        ]];
    }

    /**
     * Unit pengguna tiap aset pada tanggal reklasifikasi, dari penempatan terakhir sampai tanggal itu: unit yang
     * menanggung penyusutannya, tempat akumulasinya dulu dicatat.
     *
     * @param  list<string>  $asetIds
     * @return array<string, string>
     */
    private function usageUnits(array $asetIds, string $tanggal): array
    {
        $units = [];
        foreach (PenempatanAset::query()->whereIn('aset_id', $asetIds)->whereDate('effective_on', '<=', $tanggal)
            ->orderByDesc('effective_on')->orderByDesc('id')->toBase()->get(['aset_id', 'usage_org_unit_id']) as $penempatan) {
            if ($penempatan->usage_org_unit_id !== null) {
                $units[(string) $penempatan->aset_id] ??= (string) $penempatan->usage_org_unit_id;
            }
        }

        return $units;
    }

    /** @return int<0, max> */
    private function decimals(string $tenant, string $mataUang): int
    {
        $desimal = $this->precision->amountDecimals($tenant, $mataUang);
        if ($desimal < 0) {
            throw new InvalidArgumentException('Jumlah desimal tidak boleh negatif.');
        }

        return $desimal;
    }

    /**
     * `InvalidPosting` adalah bug penerbit (K-22): dilaporkan, lalu menjadi kegagalan dokumen yang dapat dibaca
     * orang; pemanggilnya membatalkan transaksinya.
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     *
     * @throws ReclassificationPostingFailed
     */
    private function atauGagal(callable $aksi): mixed
    {
        try {
            return $aksi();
        } catch (InvalidPosting $kegagalan) {
            report($kegagalan);

            throw new ReclassificationPostingFailed($kegagalan);
        }
    }
}
