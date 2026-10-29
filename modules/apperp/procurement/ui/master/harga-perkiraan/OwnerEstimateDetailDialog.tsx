import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { formatIdrCurrency } from './OwnerEstimateFormDialog';
import type { OwnerEstimateRecord } from './OwnerEstimateFormDialog';

type Props = {
    open: boolean;
    onClose: () => void;
    data: OwnerEstimateRecord | null;
    loading?: boolean;
    error?: string | null;
    onRetry?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
};

function formatDisplayDate(dateStr: string | null | undefined): string {
    if (!dateStr) {
        return '—';
    }

    if (dateStr.includes('/')) {
        return dateStr;
    }

    const parts = dateStr.split('-');

    if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    return dateStr;
}

export function OwnerEstimateDetailDialog({
    open,
    onClose,
    data,
    loading = false,
    error = null,
    onRetry,
    onEdit,
    onDelete,
}: Props) {
    if (!open) {
        return null;
    }

    return (
        <PageDialog
            open={open}
            onOpenChange={(nextOpen) => !nextOpen && onClose()}
        >
            <PageDialogContent
                aria-describedby={undefined}
                title="Rincian Harga Perkiraan Sendiri (HPS)"
                recordTitle={
                    data ? `${data.kode_item} · ${data.nama_item}` : 'Memuat…'
                }
                busy={loading}
                onDelete={data ? onDelete : undefined}
            >
                {onEdit && data && (
                    <PageDialogToolbar>
                        <ActionButton action="edit" onClick={onEdit} />
                    </PageDialogToolbar>
                )}

                <PageDialogBody className="space-y-4" scroll-region="true">
                    {loading && !data ? (
                        <div className="text-muted-foreground py-12 text-center text-xs">
                            Memuat rincian harga perkiraan…
                        </div>
                    ) : error && !data ? (
                        <div className="space-y-3 py-10 text-center">
                            <p className="text-destructive text-xs font-medium">
                                {error}
                            </p>
                            {onRetry && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={onRetry}
                                >
                                    Coba lagi
                                </Button>
                            )}
                        </div>
                    ) : data ? (
                        <div className="grid grid-cols-1 gap-4 rounded-lg border p-4 text-xs sm:grid-cols-2">
                            <div className="space-y-1">
                                <span className="text-muted-foreground text-[11px]">
                                    Kode Item
                                </span>
                                <div className="font-mono text-sm font-semibold">
                                    {data.kode_item}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <span className="text-muted-foreground text-[11px]">
                                    Tanggal Update
                                </span>
                                <div className="font-medium">
                                    {formatDisplayDate(data.tanggal_update)}
                                </div>
                            </div>

                            <div className="space-y-1 sm:col-span-2">
                                <span className="text-muted-foreground text-[11px]">
                                    Nama Item
                                </span>
                                <div className="text-sm font-semibold">
                                    {data.nama_item}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <span className="text-muted-foreground text-[11px]">
                                    Group Item
                                </span>
                                <div>
                                    <Badge
                                        variant="secondary"
                                        className="text-xs"
                                    >
                                        {data.group_item}
                                    </Badge>
                                </div>
                            </div>

                            <div className="space-y-1">
                                <span className="text-muted-foreground text-[11px]">
                                    Jenis Item
                                </span>
                                <div className="font-medium">
                                    {data.jenis_item}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <span className="text-muted-foreground text-[11px]">
                                    Satuan
                                </span>
                                <div className="font-medium">{data.satuan}</div>
                            </div>

                            <div className="space-y-1">
                                <span className="text-muted-foreground text-[11px]">
                                    Harga Perkiraan
                                </span>
                                <div className="font-mono text-sm font-semibold">
                                    {formatIdrCurrency(data.harga_perkiraan)}
                                </div>
                            </div>

                            <div className="space-y-1 sm:col-span-2">
                                <span className="text-muted-foreground text-[11px]">
                                    Keterangan
                                </span>
                                <div className="text-muted-foreground whitespace-pre-wrap">
                                    {data.keterangan || '—'}
                                </div>
                            </div>
                        </div>
                    ) : null}
                </PageDialogBody>
            </PageDialogContent>
        </PageDialog>
    );
}

