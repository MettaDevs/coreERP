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

export type KategoriPengadaanItem = {
    id: string;
    kode_pengadaan: string;
    kategori_pengadaan: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: KategoriPengadaanItem | null;
    onSuccess: (saved: KategoriPengadaanItem, isNew: boolean) => void;
};

export function KategoriPengadaanModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodePengadaan, setKodePengadaan] = useState('');
    const [kategoriPengadaan, setKategoriPengadaan] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (editingItem) {
            setKodePengadaan(editingItem.kode_pengadaan);
            setKategoriPengadaan(editingItem.kategori_pengadaan);
            setKeterangan(editingItem.keterangan || '');
        } else {
            setKodePengadaan('');
            setKategoriPengadaan('');
            setKeterangan('');
        }
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!kodePengadaan.trim()) {
            toast.error('Kode pengadaan wajib diisi.');
            return;
        }
        if (!kategoriPengadaan.trim()) {
            toast.error('Kategori pengadaan wajib diisi.');
            return;
        }

        setSaving(true);
        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: KategoriPengadaanItem;
                }>(`/kategori-pengadaan/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_pengadaan: kodePengadaan.trim(),
                        kategori_pengadaan: kategoriPengadaan.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Kategori pengadaan berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: KategoriPengadaanItem;
                }>(`/kategori-pengadaan`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_pengadaan: kodePengadaan.trim(),
                        kategori_pengadaan: kategoriPengadaan.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Kategori pengadaan berhasil ditambahkan.');
                onSuccess(res.data, true);
            }
            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan kategori pengadaan.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[440px] p-0 overflow-hidden border shadow-lg">
                <div className="px-5 py-3.5 bg-muted/20 border-b">
                    <DialogTitle className="text-sm font-semibold text-foreground">
                        {editingItem ? 'Edit Kategori Pengadaan' : 'Tambah Kategori Pengadaan'}
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="p-5 space-y-3">
                    {/* 1. Kode Pengadaan */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Kode Pengadaan
                        </Label>
                        <Input
                            placeholder="Contoh: Pengadaan.001"
                            value={kodePengadaan}
                            onChange={(e) => setKodePengadaan(e.target.value)}
                            required
                            className="h-8 text-xs font-mono bg-background border-input"
                        />
                    </div>

                    {/* 2. Kategori Pengadaan */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Kategori Pengadaan
                        </Label>
                        <Input
                            placeholder="Contoh: Pengadaan Bahan"
                            value={kategoriPengadaan}
                            onChange={(e) => setKategoriPengadaan(e.target.value)}
                            required
                            className="h-8 text-xs font-medium bg-background border-input"
                        />
                    </div>

                    {/* 3. Keterangan */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Keterangan
                        </Label>
                        <Textarea
                            placeholder="Kategori pengadaan untuk item bahan"
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
                                'Tambah Kategori'
                            )}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
