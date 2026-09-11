import { Badge } from '@apperp/ui/badge';
import { namaJenis, namaStatus, sebut } from '@/lib/tampilan';

/**
 * Warna di sini membawa arti, bukan hiasan.
 *
 * Produksi merah supaya operator berhenti sejenak sebelum menyentuhnya; sandbox dan demo biru dan
 * abu supaya keduanya jelas bukan tempat pelanggan bekerja. Satu-satunya alasan seluruh fitur ini
 * ada adalah supaya salinan produksi tidak diperlakukan sebagai produksi — dan itu dimulai dari
 * apakah operator dapat membedakannya dalam sekali lihat.
 */
export function LencanaJenis({ jenis }: { jenis: string }) {
    const gaya =
        jenis === 'production'
            ? 'border-red-200 bg-red-50 text-red-700'
            : jenis === 'sandbox'
              ? 'border-blue-200 bg-blue-50 text-blue-700'
              : 'border-slate-200 bg-slate-100 text-slate-700';

    return (
        <Badge variant="outline" className={gaya}>
            {sebut(namaJenis, jenis)}
        </Badge>
    );
}

export function LencanaStatus({ status }: { status: string }) {
    const gaya =
        status === 'active'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
            : status === 'degraded' || status === 'suspended'
              ? 'border-amber-200 bg-amber-50 text-amber-800'
              : 'border-slate-200 bg-slate-100 text-slate-600';

    return (
        <Badge variant="outline" className={gaya}>
            {sebut(namaStatus, status)}
        </Badge>
    );
}
