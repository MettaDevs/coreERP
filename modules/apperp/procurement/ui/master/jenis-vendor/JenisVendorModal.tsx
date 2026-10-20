import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@apperp/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
import { api, toastSaveError } from '../../api';

export type JenisVendorItem = {
    id: string;
    kode_jenis: string;
    nama_jenis_vendor: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: JenisVendorItem | null;
    onSuccess: (saved: JenisVendorItem, isNew: boolean) => void;
};

export function JenisVendorModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeJenis, setKodeJenis] = useState('');
    const [namaJenisVendor, setNamaJenisVendor] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (editingItem) {
                setKodeJenis(editingItem.kode_jenis);
                setNamaJenisVendor(editingItem.nama_jenis_vendor);
                setKeterangan(editingItem.keterangan || '');
            } else {
                setKodeJenis('');
                setNamaJenisVendor('');
                setKeterangan('');
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!kodeJenis.trim()) {
            toast.error('Kode jenis wajib diisi.');

            return;
        }

        if (!namaJenisVendor.trim()) {
            toast.error('Nama jenis vendor wajib diisi.');

            return;
        }

        setSaving(true);

        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: JenisVendorItem;
                }>(`/jenis-vendor/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_jenis: kodeJenis.trim(),
                        nama_jenis_vendor: namaJenisVendor.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Jenis vendor berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: JenisVendorItem;
                }>(`/jenis-vendor`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_jenis: kodeJenis.trim(),
                        nama_jenis_vendor: namaJenisVendor.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Jenis vendor berhasil ditambahkan.');
                onSuccess(res.data, true);
            }

            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan jenis vendor.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-hidden border p-0 shadow-lg sm:max-w-[440px]">
                <div className="bg-muted/20 border-b px-5 py-3.5">
                    <DialogTitle className="text-foreground text-sm font-semibold">
                        {editingItem
                            ? 'Edit Jenis Vendor'
                            : 'Tambah Jenis Vendor'}
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="space-y-3 p-5">
                    {/* 1. Kode Jenis */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Kode Jenis
                        </Label>
                        <Input
                            placeholder="Contoh: Jenis.001"
                            value={kodeJenis}
                            onChange={(e) => setKodeJenis(e.target.value)}
                            required
                            className="bg-background border-input h-8 font-mono text-xs"
                        />
                    </div>

                    {/* 2. Nama Jenis Vendor */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Nama Jenis Vendor
                        </Label>
                        <Input
                            placeholder="Contoh: Supplier"
                            value={namaJenisVendor}
                            onChange={(e) => setNamaJenisVendor(e.target.value)}
                            required
                            className="bg-background border-input h-8 text-xs font-medium"
                        />
                    </div>

                    {/* 3. Keterangan */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Keterangan
                        </Label>
                        <Textarea
                            placeholder="Supplier obat dan alat medis"
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
                                'Tambah Jenis'
                            )}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
