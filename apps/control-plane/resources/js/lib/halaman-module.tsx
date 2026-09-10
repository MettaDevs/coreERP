import { Component, lazy, Suspense } from 'react';
import type { ComponentType, ReactNode } from 'react';

/**
 * Satu-satunya halaman Inertia untuk semua module.
 *
 * Halaman module tidak dimuat seperti halaman shell. Berkas shell diselesaikan pemilih
 * halaman di `app.tsx` dan langsung dirender; berkas module dimuat malas, karena kode
 * module yang tidak dipakai tenant ini tidak boleh ikut turun bersama shell. Pemuatan
 * malas berarti dua hal yang wajib ada dan sering lupa dipasang:
 *
 * - **Pembatas penangguhan.** `React.lazy` melempar sebuah promise saat modulnya belum
 *   selesai diunduh. Tanpa `Suspense` di atasnya, React menaikkan lemparan itu sampai ke
 *   akar dan seluruh layar kosong.
 * - **Pembatas kesalahan.** Unduhan potongan kode bisa gagal — jaringan putus, atau berkas
 *   potongan lama sudah tidak ada setelah penyebaran baru. Kegagalan itu terjadi *setelah*
 *   halaman terpasang, jadi ia tidak bisa ditangkap `try`/`catch` di sekitar `render`.
 *   Hanya komponen kelas dengan `componentDidCatch` yang menangkapnya.
 *
 * **Kenapa berkas ini tidak tinggal di `pages/`.** Ia bukan halaman Inertia melainkan
 * pembungkus yang dipakai pemilih halaman, sedangkan pola glob halaman shell di `app.tsx`
 * menyapu seluruh isi folder itu sebagai titik masuk malas. Selama ia di sana, `app.tsx`
 * mengimpornya secara statis sekaligus menyapunya secara dinamis, dan setiap build mencetak
 * `INEFFECTIVE_DYNAMIC_IMPORT`. Peringatan yang selalu muncul adalah peringatan yang berhenti
 * dibaca orang.
 *
 * Ini yang menggantikan halaman tuan rumah beriframe yang dulu memuat UI app. Perbedaan yang
 * paling penting bukan soal gaya: iframe memuat aplikasi React kedua beserta salinan React dan
 * `@apperp/ui`-nya sendiri, sedangkan yang di sini berbagi satu React, satu tema, dan satu
 * riwayat peramban dengan shell.
 */

type PropsHalaman = Record<string, unknown>;
type PemuatHalaman = () => Promise<{ default: ComponentType<PropsHalaman> }>;

/**
 * Komponen malas disimpan per nama halaman.
 *
 * `lazy()` menghasilkan tipe komponen baru setiap kali dipanggil, dan tipe baru berarti
 * React membongkar lalu memasang ulang pohon di bawahnya. Tanpa simpanan ini, setiap
 * kunjungan ulang ke halaman yang sama — termasuk muat ulang sebagian milik Inertia —
 * membuang state halaman module dan menampilkan penangguhan sekali lagi.
 */
const simpanan = new Map<string, ComponentType<PropsHalaman>>();

export default function halamanModule(
    nama: string,
    muat: PemuatHalaman,
): ComponentType<PropsHalaman> {
    let Halaman = simpanan.get(nama);

    if (!Halaman) {
        Halaman = lazy(muat);
        simpanan.set(nama, Halaman);
    }

    const Termuat = Halaman;

    function TuanRumahModule(props: PropsHalaman) {
        return (
            <BatasKesalahan nama={nama}>
                <Suspense fallback={<SedangMemuat />}>
                    <Termuat {...props} />
                </Suspense>
            </BatasKesalahan>
        );
    }

    TuanRumahModule.displayName = `TuanRumahModule(${nama})`;

    return TuanRumahModule;
}

function SedangMemuat() {
    return (
        <div className="p-6 text-sm text-muted-foreground" role="status">
            Memuat halaman module…
        </div>
    );
}

type PropsBatas = { nama: string; children: ReactNode };
type StateBatas = { kesalahan: Error | null };

class BatasKesalahan extends Component<PropsBatas, StateBatas> {
    state: StateBatas = { kesalahan: null };

    static getDerivedStateFromError(kesalahan: Error): StateBatas {
        return { kesalahan };
    }

    componentDidCatch(kesalahan: Error) {
        // Dicatat apa adanya. Pesan aslinya ("Failed to fetch dynamically imported
        // module: …") menyebut berkas potongan yang gagal, dan itu satu-satunya petunjuk
        // yang membedakan penyebaran basi dari kesalahan di dalam halaman module.
        console.error(
            `Halaman module ${this.props.nama} gagal dimuat.`,
            kesalahan,
        );
    }

    render() {
        if (this.state.kesalahan === null) {
            return this.props.children;
        }

        return (
            <div className="max-w-md space-y-2 p-6">
                <p className="font-medium">Halaman ini belum bisa dibuka</p>
                <p className="text-sm text-muted-foreground">
                    Bagian layar milik module <code>{this.props.nama}</code>{' '}
                    gagal dimuat. Biasanya ini berarti versi yang sedang terbuka
                    sudah tertinggal dari versi yang terpasang. Muat ulang
                    halaman; kalau tetap begini, beri tahu tim yang mengelola
                    sistem.
                </p>
                <button
                    type="button"
                    className="text-sm underline underline-offset-4"
                    onClick={() => window.location.reload()}
                >
                    Muat ulang
                </button>
            </div>
        );
    }
}
