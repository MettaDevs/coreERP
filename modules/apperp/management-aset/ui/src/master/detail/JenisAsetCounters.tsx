import { Input } from '@apperp/ui/input';
import { JenisAsetDetail } from './jenisAsetDetail';

/**
 * Kotak yang belum punya tabel maupun relasi ke jenis aset. Masing-masing sudah memiliki
 * seksi sendiri di panel ini yang menjelaskan statusnya; kotaknya tetap ada supaya
 * bentuk halaman tidak berubah begitu fiturnya menyusul.
 */
const BELUM_TERSEDIA = ['Counter', 'Templat kondisi', 'Baris setup', 'Pabrikan'];

/**
 * Kotak angka bawaan jenis aset, bergaya sama seperti field kode: selalu mati, tidak
 * pernah dapat disunting.
 *
 * Angkanya hanya dimuat untuk record yang sedang dibuka. Ia sengaja bukan bagian dari
 * record: daftar tidak perlu menghitung apa pun, dan server dapat menahan angka milik
 * resource yang belum boleh dibaca pengguna ini.
 *
 * Tidak berhak dan belum tersedia sama-sama tampil sebagai "–". Itu disengaja: layar tidak
 * perlu memberi tahu yang mana yang mana.
 */
export default function JenisAsetCounters({ detail }: { detail: JenisAsetDetail | null }) {
    const boxes: { key: string; label: string; count: number | null }[] = [
        {
            key: 'atribut',
            label: 'Tipe atribut',
            count: detail?.atribut_count ?? null,
        },
        { key: 'model', label: 'Model aset', count: detail?.model_count ?? null },
        { key: 'aset', label: 'Aset', count: detail?.asset_count ?? null },
        {
            key: 'maintenance',
            label: 'Jenis pekerjaan',
            count: detail?.maintenance_job_type_count ?? null,
        },
        ...BELUM_TERSEDIA.map((label) => ({ key: label, label, count: null })),
    ];

    return (
        <div className="grid grid-cols-2 gap-4 pt-1 sm:grid-cols-4">
            {boxes.map((box) => (
                <Input
                    key={box.key}
                    id={`jumlah-${box.key}`}
                    label={box.label}
                    value={box.count ?? '–'}
                    disabled
                />
            ))}
        </div>
    );
}
