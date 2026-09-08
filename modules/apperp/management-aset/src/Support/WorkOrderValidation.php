<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Aturan validasi status work order: apa yang diperiksa, dan seberapa keras.
 *
 * Padanan FastTab `Validate` pada Work order lifecycle state di Dynamics 365 F&O, dikecilkan
 * ke tiga pemeriksaan yang datanya benar-benar kita punya. `Maintenance downtime` dan
 * `Committed cost` sengaja belum ada di sini karena fiturnya belum ada; menambahkannya
 * sekarang hanya akan menghasilkan aturan yang tidak pernah dapat dilanggar.
 */
final class WorkOrderValidation
{
    public const CHECKLIST = 'checklist_wajib';

    public const SEBAB = 'sebab_kerusakan';

    public const TINDAKAN = 'tindakan_perbaikan';

    public const ATURAN = [self::CHECKLIST, self::SEBAB, self::TINDAKAN];

    /** Hanya dicatat; tidak menahan dan tidak memberi peringatan. */
    public const INFORMASI = 'informasi';

    /** Transisi tetap berjalan, tetapi kekurangannya tersimpan pada jejak status. */
    public const PERINGATAN = 'peringatan';

    /** Transisi ditolak. */
    public const ERROR = 'error';

    public const KEPARAHAN = [self::INFORMASI, self::PERINGATAN, self::ERROR];

    /**
     * Status yang dapat menjadi tujuan transisi, jadi hanya status inilah yang masuk akal
     * memiliki aturan. `draft` tidak termasuk: ia titik awal, bukan tujuan.
     *
     * @return list<string>
     */
    public static function statusTervalidasi(): array
    {
        return [
            WorkOrderStatus::DIJADWALKAN,
            WorkOrderStatus::DIKERJAKAN,
            WorkOrderStatus::SELESAI,
            WorkOrderStatus::DITUTUP,
        ];
    }

    /** Kalimat yang dibaca pengguna ketika satu aturan tidak terpenuhi. */
    public static function pesan(string $aturan, int $jumlah): string
    {
        return match ($aturan) {
            self::CHECKLIST => 'Masih ada '.$jumlah.' pemeriksaan wajib yang belum diisi atau ditandai tidak berlaku.',
            self::SEBAB => 'Sebab kerusakan belum diisi pada '.$jumlah.' baris pekerjaan.',
            self::TINDAKAN => 'Tindakan perbaikan belum diisi pada '.$jumlah.' baris pekerjaan.',
            default => 'Aturan '.$aturan.' belum terpenuhi pada '.$jumlah.' baris.',
        };
    }
}
