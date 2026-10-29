import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import type { VendorTypeRecord } from './VendorTypeFormDialog';

type Props = {
    open: boolean;
    onClose: () => void;
    data: VendorTypeRecord | null;
    loading?: boolean;
    error?: string | null;
    onRetry?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
};

export function VendorTypeDetailDialog({
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
                title="Rincian Jenis Vendor/Supplier"
                recordTitle={
                    data
                        ? `${data.kode_jenis} · ${data.nama_jenis_vendor}`
                        : 'Memuat…'
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
                            Memuat rincian jenis vendor…
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
                                    Kode Jenis Vendor
                                </span>
                                <div className="font-mono text-sm font-semibold">
                                    {data.kode_jenis}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <span className="text-muted-foreground text-[11px]">
                                    Status
                                </span>
                                <div>
                                    <Badge
                                        variant={
                                            data.aktif ? 'default' : 'secondary'
                                        }
                                        className="text-xs"
                                    >
                                        {data.aktif ? 'Aktif' : 'Nonaktif'}
                                    </Badge>
                                </div>
                            </div>

                            <div className="space-y-1 sm:col-span-2">
                                <span className="text-muted-foreground text-[11px]">
                                    Nama Jenis Vendor/Supplier
                                </span>
                                <div className="text-sm font-semibold">
                                    {data.nama_jenis_vendor}
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
