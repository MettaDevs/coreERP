import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@apperp/ui/popover';
import { Progress } from '@apperp/ui/progress';
import { Spinner } from '@apperp/ui/spinner';
import { Link } from '@inertiajs/react';
import { Download, FileOutput } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { toast } from 'sonner';

import {
    getExports,
    refreshExports,
    subscribeExports,
} from '@/lib/export-watch';
import {
    downloadExport,
    FORMAT_LABEL,
    formatTime,
    isActive,
    STATUS_LABEL,
} from '@/lib/reports';
import type { ReportExport } from '@/lib/reports';

/**
 * Ikon Ekspor di header: jumlah yang sedang dikerjakan pada ikonnya, lima ekspor
 * terbaru pada popover. Pengguna meminta cetak dari app mana pun, lalu bebas pindah;
 * hasilnya menyusul di sini, di lonceng, dan di halaman Ekspor laporan.
 */
export function ExportTray() {
    const exports = useSyncExternalStore(
        subscribeExports,
        getExports,
        () => [],
    );
    const [manualOpen, setManualOpen] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const active = exports.filter(isActive).length;
    // Tray terbuka sendiri selama ada ekspor yang dikerjakan, sampai pengguna menutupnya;
    // diturunkan dari data, bukan disetel dari effect.
    const open = manualOpen || (active > 0 && !dismissed);
    const setOpen = (next: boolean) => {
        setManualOpen(next);

        if (!next) {
            setDismissed(true);
        }
    };

    useEffect(() => {
        void refreshExports();
    }, []);

    if (exports.length === 0) {
        return null;
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        active > 0
                            ? `Ekspor, ${active} sedang dikerjakan`
                            : 'Ekspor'
                    }
                >
                    {active > 0 ? <Spinner /> : <FileOutput />}
                    {active > 0 && (
                        <span
                            aria-hidden
                            className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-none font-semibold text-primary-foreground"
                        >
                            {active}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-96 p-0">
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <p className="text-sm font-medium">Ekspor terbaru</p>
                        <p className="text-xs text-muted-foreground">
                            Anda dapat terus bekerja; hasil siap diunduh di
                            sini.
                        </p>
                    </div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href="/reports/exports"
                            onClick={() => setOpen(false)}
                        >
                            Semua
                        </Link>
                    </Button>
                </div>
                <ul className="divide-y">
                    {exports.slice(0, 5).map((item) => (
                        <li key={item.id} className="space-y-1.5 px-4 py-3">
                            <ExportRow item={item} />
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}

function ExportRow({ item }: { item: ReportExport }) {
    const download = () =>
        void downloadExport(item).catch((caught: Error) =>
            toast.error(caught.message || 'Berkas belum dapat diunduh.'),
        );

    return (
        <>
            <div className="flex items-center justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-medium">
                        {item.report_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {FORMAT_LABEL[item.format]} · {item.layout_name} ·{' '}
                        {formatTime(item.created_at)}
                    </p>
                </div>
                {item.status === 'done' ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={download}
                    >
                        <Download />
                        Unduh
                    </Button>
                ) : (
                    <Badge
                        variant={
                            item.status === 'failed'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {STATUS_LABEL[item.status]}
                    </Badge>
                )}
            </div>
            {isActive(item) && (
                <Progress value={item.progress} aria-label="Kemajuan ekspor" />
            )}
            {item.status === 'failed' && item.failure_message && (
                <p className="text-xs text-destructive">
                    {item.failure_message}
                </p>
            )}
        </>
    );
}
