import { Badge } from '@apperp/ui/badge';
import { namaJenis, namaStatus, namaStatusModul, sebut } from '@/lib/tampilan';

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
            ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200'
            : jenis === 'sandbox'
              ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/40 dark:text-blue-200'
              : 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200';

    return (
        <Badge variant="outline" className={gaya}>
            {sebut(namaJenis, jenis)}
        </Badge>
    );
}

export function LencanaStatus({ status }: { status: string }) {
    const gaya =
        status === 'active'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
            : status === 'degraded' || status === 'suspended'
              ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
              : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';

    return (
        <Badge variant="outline" className={gaya}>
            {sebut(namaStatus, status)}
        </Badge>
    );
}

/**
 * `uninstalled` tetap abu, bukan merah.
 *
 * Module yang dicopot bukan kegagalan — ia keputusan, dan barisnya tetap ada justru supaya operator
 * tahu module itu pernah terpasang di sini. Yang kuning hanya `disabled`, karena module yang
 * dimatikan masih memegang tabelnya: ia beban yang tidak terpakai, dan itu keadaan yang layak
 * ditanyakan.
 */
export function LencanaStatusModul({ status }: { status: string }) {
    const gaya =
        status === 'installed'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
            : status === 'disabled'
              ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
              : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';

    return (
        <Badge variant="outline" className={gaya}>
            {sebut(namaStatusModul, status)}
        </Badge>
    );
}
