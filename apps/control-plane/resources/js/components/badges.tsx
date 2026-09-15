import { Badge } from '@apperp/ui/badge';
import {
    fleetStateLabels,
    installStateLabels,
    kindLabels,
    labelFor,
    moduleStatusLabels,
    siteStateLabels,
    statusLabels,
} from '@/lib/display';

/**
 * Warna di sini membawa arti, bukan hiasan.
 *
 * Produksi merah supaya operator berhenti sejenak sebelum menyentuhnya; sandbox dan demo biru dan
 * abu supaya keduanya jelas bukan tempat pelanggan bekerja. Satu-satunya alasan seluruh fitur ini
 * ada adalah supaya salinan produksi tidak diperlakukan sebagai produksi — dan itu dimulai dari
 * apakah operator dapat membedakannya dalam sekali lihat.
 */
export function KindBadge({ kind }: { kind: string }) {
    const classes =
        kind === 'production'
            ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200'
            : kind === 'sandbox'
              ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/40 dark:text-blue-200'
              : 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(kindLabels, kind)}
        </Badge>
    );
}

export function StatusBadge({ status }: { status: string }) {
    const classes =
        status === 'active'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
            : status === 'degraded' || status === 'suspended'
              ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
              : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(statusLabels, status)}
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
export function ModuleStatusBadge({ status }: { status: string }) {
    const classes =
        status === 'installed'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
            : status === 'disabled'
              ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
              : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(moduleStatusLabels, status)}
        </Badge>
    );
}

/**
 * Keadaan terhadap versi image, dan warnanya sengaja tidak sama dengan `StatusBadge`.
 *
 * `behind` kuning, bukan merah. Lingkungan yang tertinggal bukan lingkungan yang rusak — ia bekerja,
 * pelanggannya memakainya, dan yang kurang hanyalah migration terakhir. Merah untuk keadaan yang
 * terjadi pada setiap lingkungan sesudah setiap rilis akan melatih operator mengabaikan merah, dan
 * pada saat itu `failed` kehilangan satu-satunya cara ia menonjol.
 */
export function FleetStateBadge({ state }: { state: string }) {
    const classes =
        state === 'current'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
            : state === 'behind'
              ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
              : state === 'failed'
                ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200'
                : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(fleetStateLabels, state)}
        </Badge>
    );
}

/**
 * Keadaan situs. `stale` merah: situs yang tidak melapor adalah server klien yang tidak dapat kita
 * lihat, dan itu keadaan yang harus ditanyakan hari itu juga — berbeda dari lingkungan yang
 * tertinggal migrasi.
 */
/**
 * Keadaan pemasangan server klien.
 *
 * Hijau hanya `ready`. Merah untuk yang menuntut tindakan hari itu juga — `failed`, dan `stale`
 * karena server terpasang yang berhenti terlihat sama gentingnya dengan situs yang tidak melapor.
 * Kuning untuk yang sedang menunggu seseorang: teknisi, rilis, atau agen. Abu untuk yang belum
 * dimulai atau sudah diakhiri dengan sengaja.
 */
export function InstallStateBadge({ state }: { state: string }) {
    const classes =
        state === 'ready'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
            : state === 'failed' || state === 'stale'
              ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200'
              : [
                      'awaiting_command',
                      'awaiting_release',
                      'connected',
                      'installing',
                  ].includes(state)
                ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
                : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(installStateLabels, state)}
        </Badge>
    );
}

export function SiteStateBadge({ state }: { state: string }) {
    const classes =
        state === 'enrolled'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
            : state === 'stale'
              ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200'
              : state === 'not_enrolled'
                ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
                : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(siteStateLabels, state)}
        </Badge>
    );
}
