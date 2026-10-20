import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Badge } from '@apperp/ui/badge';
import { CheckCircle2, Eye, Wrench, XCircle } from 'lucide-react';
import type { ItemJasaRecord } from './ItemJasaModal';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: ItemJasaRecord | null;
    onEdit?: (item: ItemJasaRecord) => void;
};

export function ItemJasaDetailModal({
    open,
    onOpenChange,
    item,
    onEdit,
}: Props) {
    if (!item) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md overflow-hidden border p-0 shadow-lg">
                <div className="bg-muted/30 border-b px-6 pb-4 pt-5">
                    <div className="flex items-center gap-2">
                        <Wrench className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                        <DialogTitle className="text-foreground text-base font-bold">
                            Detail Item Upah / Jasa
                        </DialogTitle>
                    </div>
                </div>

                <div className="space-y-3.5 p-6 text-xs">
                    <div className="flex justify-between border-b py-1.5">
                        <span className="text-muted-foreground font-medium">
                            Kode Upah:
                        </span>
                        <span className="text-foreground font-mono font-bold">
                            {item.kode_upah}
                        </span>
                    </div>

                    <div className="flex justify-between border-b py-1.5">
                        <span className="text-muted-foreground font-medium">
                            Nama Upah:
                        </span>
                        <span className="text-foreground max-w-[220px] text-right font-bold">
                            {item.nama_upah}
                        </span>
                    </div>

                    <div className="flex justify-between border-b py-1.5">
                        <span className="text-muted-foreground font-medium">
                            Satuan:
                        </span>
                        <span className="text-foreground font-semibold">
                            {item.satuan}
                        </span>
                    </div>

                    <div className="flex justify-between border-b py-1.5">
                        <span className="text-muted-foreground font-medium">
                            Status:
                        </span>
                        <span>
                            {item.aktif ? (
                                <span className="inline-flex items-center rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                    Aktif
                                </span>
                            ) : (
                                <span className="inline-flex items-center rounded bg-zinc-200 px-2 py-0.5 text-[11px] font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                                    <XCircle className="mr-1 h-3 w-3" />
                                    Non Aktif
                                </span>
                            )}
                        </span>
                    </div>

                    <div className="pt-2">
                        <span className="text-muted-foreground mb-1 block font-medium">
                            Keterangan:
                        </span>
                        <div className="bg-muted/40 text-foreground rounded-md border p-2.5">
                            {item.keterangan || '-'}
                        </div>
                    </div>
                </div>

                <DialogFooter className="bg-muted/20 flex justify-end gap-2 border-t px-6 py-3">
                    {onEdit && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                onOpenChange(false);
                                onEdit(item);
                            }}
                            className="h-8 text-xs"
                        >
                            Ubah Data
                        </Button>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                        className="h-8 text-xs"
                    >
                        Tutup
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
