import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Spinner } from '@apperp/ui/spinner';
import { router, usePage } from '@inertiajs/react';
import { ExternalLink, RefreshCw } from 'lucide-react';
import { useState } from 'react';

export type DnsInfo = {
    state: 'none' | 'synced' | 'outdated' | string;
    name: string | null;
    target: string | null;
    syncedAt: string | null;
};

/**
 * Alamat aplikasi otomatis dan record DNS yang membawanya ke server klien.
 *
 * Tiga keadaan, dari yang tercatat di admin.erp: belum dibuat (dibuat saat perintah pasang), mengikuti alamat
 * server, atau belum mengikuti — alamat server sudah diganti tetapi Cloudflare menolak saat itu. Hanya keadaan
 * terakhir yang menawarkan "Sinkronkan DNS"; dua yang lain tidak butuh tindakan.
 */
export default function DnsStatus({
    siteId,
    appUrl,
    dns,
    serverAddress,
    revoked = false,
}: {
    siteId: string;
    appUrl: string | null;
    dns: DnsInfo;
    serverAddress: string | null;
    revoked?: boolean;
}) {
    const [syncing, setSyncing] = useState(false);
    const error = usePage().props.errors.dns;

    const badge =
        dns.state === 'synced'
            ? {
                  label: 'Menunjuk server ini',
                  className:
                      'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200',
              }
            : dns.state === 'outdated'
              ? {
                    label: 'Belum mengikuti alamat server',
                    className:
                        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200',
                }
              : {
                    label: 'Dibuat saat perintah pasang',
                    className:
                        'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
                };

    function sync() {
        router.post(
            `/situs/${siteId}/dns`,
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncing(true),
                onFinish: () => setSyncing(false),
            },
        );
    }

    return (
        <div className="space-y-2" data-test="dns-status">
            {appUrl && (
                <a
                    href={appUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 font-mono text-sm break-all underline underline-offset-4"
                >
                    {appUrl}
                    <ExternalLink className="size-3.5 shrink-0" />
                </a>
            )}
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <Badge variant="outline" className={badge.className}>
                    {badge.label}
                </Badge>
                {dns.name && (
                    <span className="font-mono">
                        {dns.name} →{' '}
                        {dns.target ?? serverAddress ?? 'alamat server'}
                    </span>
                )}
                {dns.state === 'outdated' && serverAddress && (
                    <span>(sekarang {serverAddress})</span>
                )}
            </div>
            {dns.state === 'outdated' && !revoked && (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={sync}
                    disabled={syncing}
                >
                    {syncing ? <Spinner /> : <RefreshCw />}
                    Sinkronkan DNS
                </Button>
            )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
