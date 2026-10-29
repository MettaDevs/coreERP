import { Badge } from '@apperp/ui/badge';
import {
    financeFeedStateLabels,
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
            ? 'border-destructive/30 bg-destructive/10 text-destructive   '
            : kind === 'sandbox'
              ? 'border-primary/30 bg-primary/10 text-primary   '
              : 'border-border bg-muted text-muted-foreground   ';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(kindLabels, kind)}
        </Badge>
    );
}

export function StatusBadge({ status }: { status: string }) {
    const classes =
        status === 'active'
            ? 'border-success/30 bg-success/10 text-success   '
            : status === 'degraded' || status === 'suspended'
              ? 'border-warning/30 bg-warning/10 text-warning   '
              : 'border-border bg-muted text-muted-foreground   ';

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
            ? 'border-success/30 bg-success/10 text-success   '
            : status === 'disabled'
              ? 'border-warning/30 bg-warning/10 text-warning   '
              : 'border-border bg-muted text-muted-foreground   ';

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
            ? 'border-success/30 bg-success/10 text-success   '
            : state === 'behind'
              ? 'border-warning/30 bg-warning/10 text-warning   '
              : state === 'failed'
                ? 'border-destructive/30 bg-destructive/10 text-destructive   '
                : 'border-border bg-muted text-muted-foreground   ';

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
            ? 'border-success/30 bg-success/10 text-success   '
            : state === 'failed' || state === 'stale'
              ? 'border-destructive/30 bg-destructive/10 text-destructive   '
              : [
                      'awaiting_command',
                      'awaiting_release',
                      'connected',
                      'installing',
                  ].includes(state)
                ? 'border-warning/30 bg-warning/10 text-warning   '
                : 'border-border bg-muted text-muted-foreground   ';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(installStateLabels, state)}
        </Badge>
    );
}

/**
 * Kesehatan feed posting finance satu server klien.
 *
 * Merah hanya `attention`: ada posting yang ditolak atau tertahan, atau yang pending terlalu lama — jurnal
 * yang belum dibukukan aplikasi finance klinik, dan yang harus ditanyakan sebelum klinik menelepon. Kuning
 * `unreadable`, karena yang diketahui hanya bahwa Core tidak menjawab. Abu untuk yang belum dilaporkan atau belum
 * dipakai.
 */
export function FinanceFeedBadge({ state }: { state: string }) {
    const classes =
        state === 'healthy'
            ? 'border-success/30 bg-success/10 text-success   '
            : state === 'attention'
              ? 'border-destructive/30 bg-destructive/10 text-destructive   '
              : state === 'unreadable'
                ? 'border-warning/30 bg-warning/10 text-warning   '
                : 'border-border bg-muted text-muted-foreground   ';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(financeFeedStateLabels, state)}
        </Badge>
    );
}

export function SiteStateBadge({ state }: { state: string }) {
    const classes =
        state === 'enrolled'
            ? 'border-success/30 bg-success/10 text-success   '
            : state === 'stale'
              ? 'border-destructive/30 bg-destructive/10 text-destructive   '
              : state === 'not_enrolled'
                ? 'border-warning/30 bg-warning/10 text-warning   '
                : 'border-border bg-muted text-muted-foreground   ';

    return (
        <Badge variant="outline" className={classes}>
            {labelFor(siteStateLabels, state)}
        </Badge>
    );
}
