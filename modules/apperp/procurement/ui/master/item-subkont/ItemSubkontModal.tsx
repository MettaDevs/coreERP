import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { api, toastSaveError } from '../../api';

export type ItemSubkontRecord = {
    id: string;
    kode_subkont: string;
    nama_subkont: string;
    satuan: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: ItemSubkontRecord | null;
    onSuccess: (saved: ItemSubkontRecord, isNew: boolean) => void;
};

export function ItemSubkontModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeSubkont, setKodeSubkont] = useState('');
    const [namaSubkont, setNamaSubkont] = useState('');
    const [satuan, setSatuan] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (editingItem) {
            setKodeSubkont(editingItem.kode_subkont);
            setNamaSubkont(editingItem.nama_subkont);
            setSatuan(editingItem.satuan);
            setKeterangan(editingItem.keterangan || '');
        } else {
            setKodeSubkont('');
            setNamaSubkont('');
            setSatuan('');
            setKeterangan('');
        }
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!kodeSubkont.trim()) {
            toast.error('Kode subkont wajib diisi.');
            return;
        }
        if (!namaSubkont.trim()) {
            toast.error('Nama subkont wajib diisi.');
            return;
        }
        if (!satuan.trim()) {
            toast.error('Satuan wajib diisi.');
            return;
        }

        setSaving(true);
        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: ItemSubkontRecord;
                }>(`/item-subkont/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_subkont: kodeSubkont.trim(),
                        nama_subkont: namaSubkont.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Item subkontraktor berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: ItemSubkontRecord;
                }>(`/item-subkont`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_subkont: kodeSubkont.trim(),
                        nama_subkont: namaSubkont.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Item subkontraktor berhasil ditambahkan.');
                onSuccess(res.data, true);
            }
            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan item subkontraktor.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-hidden border p-0 shadow-lg sm:max-w-[460px]">
                <div className="bg-muted/20 border-b px-5 py-3.5">
                    <DialogTitle className="text-foreground text-sm font-semibold">
                        {editingItem
                            ? 'Edit Item Subkontraktor'
                            : 'Tambah Item Subkontraktor'}
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="space-y-3 p-5">
                    {/* 1. Kode Subkont */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Kode Subkont
                        </Label>
                        <Input
                            placeholder="Contoh: Subkon.001"
                            value={kodeSubkont}
                            onChange={(e) => setKodeSubkont(e.target.value)}
                            required
                            className="bg-background border-input h-8 font-mono text-xs"
                        />
                    </div>

                    {/* 2. Nama Subkont */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Nama Subkont
                        </Label>
                        <Input
                            placeholder="Contoh: Pembangunan Rangkaian Struktur IPAL"
                            value={namaSubkont}
                            onChange={(e) => setNamaSubkont(e.target.value)}
                            required
                            className="bg-background border-input h-8 text-xs font-medium"
                        />
                    </div>

                    {/* 3. Satuan */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Satuan
                        </Label>
                        <Input
                            placeholder="Contoh: Unit"
                            value={satuan}
                            onChange={(e) => setSatuan(e.target.value)}
                            required
                            className="bg-background border-input h-8 text-xs"
                        />
                    </div>

                    {/* 4. Keterangan */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Keterangan
                        </Label>
                        <Textarea
                            placeholder="-"
                            value={keterangan}
                            onChange={(e) => setKeterangan(e.target.value)}
                            rows={2}
                            className="bg-background border-input resize-none text-xs"
                        />
                    </div>

                    {/* Tombol Aksi */}
                    <div className="mt-4 flex items-center justify-end gap-2 border-t pt-3">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={saving}
                            onClick={() => onOpenChange(false)}
                            className="h-8 text-xs"
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={saving}
                            className="h-8 px-4 text-xs font-medium"
                        >
                            {saving ? (
                                <>
                                    <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                    Menyimpan...
                                </>
                            ) : editingItem ? (
                                'Simpan Perubahan'
                            ) : (
                                'Tambah Item'
                            )}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
