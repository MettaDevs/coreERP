import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import type { ProcurementCategoryRecord } from './ProcurementCategoryFormDialog';

type Props = {
    open: boolean;
    onClose: () => void;
    data: ProcurementCategoryRecord | null;
    loading?: boolean;
    error?: string | null;
    onRetry?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
};

export function ProcurementCategoryDetailDialog({
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
                title="Rincian Kategori Pengadaan"
                recordTitle={
                    data
                        ? `${data.kode_pengadaan} · ${data.kategori_pengadaan}`
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
                            Memuat rincian kategori pengadaan…
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
                                    Kode Pengadaan
                                </span>
                                <div className="font-mono text-sm font-semibold">
                                    {data.kode_pengadaan}
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
                                    Nama Kategori Pengadaan
                                </span>
                                <div className="text-sm font-semibold">
                                    {data.kategori_pengadaan}
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
