<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Status siklus hidup aset, dan satu-satunya tempat yang tahu artinya.
 *
 * Sebelum berkas ini ada, ketujuh pemeriksaannya tersebar sebagai `in_array($status,
 * ['decommissioned', 'disposed'])` yang ditulis ulang di tujuh berkas. Bentuk itu punya
 * dua cacat yang sama-sama tidak berbunyi: daftar yang terlewat saat status bertambah
 * hanya ketahuan lewat perilaku yang salah, dan pembacanya tidak pernah menyebut **kenapa**
 * status itu menghalangi — "bukan decommissioned atau disposed" tidak sama artinya dengan
 * "boleh dibuatkan work order", meskipun hari ini daftarnya kebetulan sama.
 *
 * Karena itu tiap pertanyaan punya methodnya sendiri walau isinya kembar. Keduanya memang
 * akan berpisah: sebuah status "hilang" kelak tidak boleh dibuatkan work order, tetapi
 * tetap harus terhitung sebagai aset yang dimiliki pada layar model dan pabrikan.
 *
 * **Kalau kelak status menjadi master yang dapat diatur tenant** — bentuk `Aset lifecycle
 * state` di Dynamics 365, dengan penanda "boleh dibuatkan work order" sebagai kolom —
 * yang berubah hanya isi kelas ini: badan tiap method berganti dari daftar tetap menjadi
 * pembacaan master. Ketujuh pemanggilnya tidak ikut disentuh, dan itu memang alasan kelas
 * ini dibuat sebelum masternya ada.
 */
final class StatusAset
{
    /** Tercatat sebagai aset; keadaan awal setiap aset yang diterima. */
    public const DITERIMA = 'received';

    /** Disetujui berhenti dipakai lewat workflow Core, tetapi belum dilepas. */
    public const DIHENTIKAN = 'decommissioned';

    /** Sudah dijual atau dimusnahkan; akhir masa hidupnya di subledger ini. */
    public const DILEPAS = 'disposed';

    /** @return list<string> */
    public static function semua(): array
    {
        return [self::DITERIMA, self::DIHENTIKAN, self::DILEPAS];
    }

    /**
     * Status yang membuat aset tidak lagi dihitung sebagai milik yang beredar.
     *
     * Dipakai sebagai daftar, bukan sebagai pemeriksaan, karena pemanggilnya menyaring di
     * database (`whereNotIn`) dan bukan memeriksa satu baris yang sudah dibaca.
     *
     * @return list<string>
     */
    public static function tidakLagiBeredar(): array
    {
        return [self::DIHENTIKAN, self::DILEPAS];
    }

    public static function sudahDilepas(?string $status): bool
    {
        return $status === self::DILEPAS;
    }

    /** Sudah disetujui berhenti pakai; ini syarat sebelum boleh dijual atau dimusnahkan. */
    public static function sudahDihentikan(?string $status): bool
    {
        return $status === self::DIHENTIKAN;
    }

    /**
     * Boleh dibuatkan work order pemeliharaan.
     *
     * Padanan penanda **Active** pada `Asset lifecycle state` di Dynamics 365 Aset
     * Management, dan di sana itulah satu-satunya hal yang benar-benar ditegakkan status
     * siklus hidup. Aset yang sudah dihentikan atau dilepas tidak boleh menerima pekerjaan
     * baru: yang pertama sudah diputuskan berhenti dipakai, yang kedua bahkan sudah tidak
     * ada wujudnya.
     */
    public static function bolehDibuatkanWorkOrder(?string $status): bool
    {
        return ! in_array($status, self::tidakLagiBeredar(), true);
    }

    /** Boleh dipindahkan lewat dokumen mutasi. */
    public static function bolehDimutasi(?string $status): bool
    {
        return ! in_array($status, self::tidakLagiBeredar(), true);
    }

    /** Boleh diajukan dekomisioning; yang sudah dihentikan atau dilepas tidak lagi. */
    public static function bolehDidekomisioning(?string $status): bool
    {
        return ! in_array($status, self::tidakLagiBeredar(), true);
    }

    /**
     * Boleh dijual atau dimusnahkan.
     *
     * Satu-satunya pemeriksaan yang menuntut status **tepat**, bukan sekadar bukan-ini dan
     * bukan-itu: pelepasan hanya sah sesudah dekomisioning disetujui.
     */
    public static function bolehDilepas(?string $status): bool
    {
        return self::sudahDihentikan($status);
    }

    /**
     * Boleh dikoreksi datanya.
     *
     * Lebih longgar daripada yang lain, dan itu disengaja: aset yang sudah dihentikan masih
     * boleh dibetulkan salah ketiknya, sedangkan aset yang sudah dilepas tidak — angkanya
     * sudah masuk dokumen penjualan atau pemusnahan.
     */
    public static function bolehDikoreksi(?string $status): bool
    {
        return ! self::sudahDilepas($status);
    }
}
