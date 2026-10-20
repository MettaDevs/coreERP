import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { api, toastSaveError } from '../../api';

export type ItemJasaRecord = {
    id: string;
    kode_upah: string;
    nama_upah: string;
    satuan: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: ItemJasaRecord | null;
    onSuccess: (saved: ItemJasaRecord, isNew: boolean) => void;
};

export function ItemJasaModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeUpah, setKodeUpah] = useState('');
    const [namaUpah, setNamaUpah] = useState('');
    const [satuan, setSatuan] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (editingItem) {
            setKodeUpah(editingItem.kode_upah);
            setNamaUpah(editingItem.nama_upah);
            setSatuan(editingItem.satuan);
            setKeterangan(editingItem.keterangan || '');
        } else {
            setKodeUpah('');
            setNamaUpah('');
            setSatuan('');
            setKeterangan('');
        }
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!kodeUpah.trim()) {
            toast.error('Kode upah wajib diisi.');
            return;
        }
        if (!namaUpah.trim()) {
            toast.error('Nama upah wajib diisi.');
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
                    data: ItemJasaRecord;
                }>(`/item-jasa/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_upah: kodeUpah.trim(),
                        nama_upah: namaUpah.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Item upah berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: ItemJasaRecord;
                }>(`/item-jasa`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_upah: kodeUpah.trim(),
                        nama_upah: namaUpah.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Item upah berhasil ditambahkan.');
                onSuccess(res.data, true);
            }
            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan item upah.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[460px] p-0 overflow-hidden border shadow-lg">
                <div className="px-5 py-3.5 bg-muted/20 border-b">
                    <DialogTitle className="text-sm font-semibold text-foreground">
                        {editingItem ? 'Edit Item Upah / Jasa' : 'Tambah Item Upah / Jasa'}
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="p-5 space-y-3">
                    {/* 1. Kode Upah */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Kode Upah
                        </Label>
                        <Input
                            placeholder="Contoh: Upah.0001"
                            value={kodeUpah}
                            onChange={(e) => setKodeUpah(e.target.value)}
                            required
                            className="h-8 text-xs font-mono bg-background border-input"
                        />
                    </div>

                    {/* 2. Nama Upah */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Nama Upah
                        </Label>
                        <Input
                            placeholder="Contoh: Upah Pembersihan Alat & Mesin"
                            value={namaUpah}
                            onChange={(e) => setNamaUpah(e.target.value)}
                            required
                            className="h-8 text-xs font-medium bg-background border-input"
                        />
                    </div>

                    {/* 3. Satuan */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Satuan
                        </Label>
                        <Input
                            placeholder="Contoh: Unit, Jam, Hari, Titik"
                            value={satuan}
                            onChange={(e) => setSatuan(e.target.value)}
                            required
                            className="h-8 text-xs bg-background border-input"
                        />
                    </div>

                    {/* 4. Keterangan */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Keterangan
                        </Label>
                        <Textarea
                            placeholder="Deskripsi ruang lingkup pekerjaan atau upah"
                            value={keterangan}
                            onChange={(e) => setKeterangan(e.target.value)}
                            rows={2}
                            className="text-xs bg-background border-input resize-none"
                        />
                    </div>

                    {/* Tombol Aksi */}
                    <div className="pt-3 flex items-center justify-end gap-2 border-t mt-4">
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
