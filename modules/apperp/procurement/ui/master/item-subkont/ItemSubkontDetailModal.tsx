import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@apperp/ui/dialog';
import { CheckCircle2, HardHat, Pencil, XCircle } from 'lucide-react';
import type { ItemSubkontRecord } from './ItemSubkontModal';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: ItemSubkontRecord | null;
    onEdit?: (item: ItemSubkontRecord) => void;
};

export function ItemSubkontDetailModal({
    open,
    onOpenChange,
    item,
    onEdit,
}: Props) {
    if (!item) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md p-0 overflow-hidden border shadow-lg">
                <div className="px-6 pt-5 pb-4 bg-muted/30 border-b">
                    <div className="flex items-center gap-2">
                        <HardHat className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                        <DialogTitle className="text-base font-bold text-foreground">
                            Detail Item Subkontraktor
                        </DialogTitle>
                    </div>
                </div>

                <div className="p-6 space-y-3.5 text-xs">
                    <div className="flex justify-between py-1.5 border-b">
                        <span className="text-muted-foreground font-medium">
                            Kode Subkont:
                        </span>
                        <span className="font-mono font-bold text-foreground">
                            {item.kode_subkont}
                        </span>
                    </div>

                    <div className="flex justify-between py-1.5 border-b">
                        <span className="text-muted-foreground font-medium">
                            Nama Subkont:
                        </span>
                        <span className="font-bold text-foreground text-right max-w-[220px]">
                            {item.nama_subkont}
                        </span>
                    </div>

                    <div className="flex justify-between py-1.5 border-b">
                        <span className="text-muted-foreground font-medium">
                            Satuan:
                        </span>
                        <span className="font-semibold text-foreground">
                            {item.satuan}
                        </span>
                    </div>

                    <div className="flex justify-between py-1.5 border-b">
                        <span className="text-muted-foreground font-medium">
                            Status:
                        </span>
                        <span>
                            {item.aktif ? (
                                <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                    Aktif
                                </span>
                            ) : (
                                <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                                    <XCircle className="mr-1 h-3 w-3" />
                                    Non Aktif
                                </span>
                            )}
                        </span>
                    </div>

                    <div className="pt-2">
                        <span className="text-muted-foreground font-medium block mb-1">
                            Keterangan:
                        </span>
                        <div className="p-2.5 rounded-md bg-muted/40 border text-foreground">
                            {item.keterangan || '-'}
                        </div>
                    </div>
                </div>

                <DialogFooter className="px-6 py-3 bg-muted/20 border-t flex justify-end gap-2">
                    {onEdit && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                onOpenChange(false);
                                onEdit(item);
                            }}
                            className="h-8 text-xs font-semibold text-blue-600 border-blue-200 hover:bg-blue-50 dark:hover:bg-blue-950/50"
                        >
                            <Pencil className="mr-1.5 h-3.5 w-3.5" />
                            Ubah Data
                        </Button>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                        className="h-8 text-xs bg-muted text-muted-foreground hover:bg-muted/80"
                    >
                        Tutup
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
