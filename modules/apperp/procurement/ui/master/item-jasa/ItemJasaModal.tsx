import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@apperp/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
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
        const timer = setTimeout(() => {
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
        }, 0);

        return () => clearTimeout(timer);
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
            <DialogContent className="overflow-hidden border p-0 shadow-lg sm:max-w-[460px]">
                <div className="bg-muted/20 border-b px-5 py-3.5">
                    <DialogTitle className="text-foreground text-sm font-semibold">
                        {editingItem
                            ? 'Edit Item Upah / Jasa'
                            : 'Tambah Item Upah / Jasa'}
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="space-y-3 p-5">
                    {/* 1. Kode Upah */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Kode Upah
                        </Label>
                        <Input
                            placeholder="Contoh: Upah.0001"
                            value={kodeUpah}
                            onChange={(e) => setKodeUpah(e.target.value)}
                            required
                            className="bg-background border-input h-8 font-mono text-xs"
                        />
                    </div>

                    {/* 2. Nama Upah */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Nama Upah
                        </Label>
                        <Input
                            placeholder="Contoh: Upah Pembersihan Alat & Mesin"
                            value={namaUpah}
                            onChange={(e) => setNamaUpah(e.target.value)}
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
                            placeholder="Contoh: Unit, Jam, Hari, Titik"
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
                            placeholder="Deskripsi ruang lingkup pekerjaan atau upah"
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
