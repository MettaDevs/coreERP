import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
    Dialog,
    DialogAction,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@apperp/ui/dialog";
import { Input } from "@apperp/ui/input";
import { Label } from "@apperp/ui/label";
import { Textarea } from "@apperp/ui/textarea";
import { api, toastSaveError } from "../../api";

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
    const [kodePengadaan, setKodePengadaan] = useState("");
    const [kategoriPengadaan, setKategoriPengadaan] = useState("");
    const [keterangan, setKeterangan] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (editingItem) {
                setKodePengadaan(editingItem.kode_pengadaan);
                setKategoriPengadaan(editingItem.kategori_pengadaan);
                setKeterangan(editingItem.keterangan || "");
            } else {
                setKodePengadaan("");
                setKategoriPengadaan("");
                setKeterangan("");
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!kodePengadaan.trim()) {
            toast.error("Kode pengadaan wajib diisi.");

            return;
        }

        if (!kategoriPengadaan.trim()) {
            toast.error("Kategori pengadaan wajib diisi.");

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
                    method: "PUT",
                    body: JSON.stringify({
                        kode_pengadaan: kodePengadaan.trim(),
                        kategori_pengadaan: kategoriPengadaan.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success("Kategori pengadaan berhasil diperbarui.");
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: KategoriPengadaanItem;
                }>(`/kategori-pengadaan`, {
                    method: "POST",
                    body: JSON.stringify({
                        kode_pengadaan: kodePengadaan.trim(),
                        kategori_pengadaan: kategoriPengadaan.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success("Kategori pengadaan berhasil ditambahkan.");
                onSuccess(res.data, true);
            }

            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, "Gagal menyimpan kategori pengadaan.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="compact">
                <form onSubmit={handleSubmit} className="flex flex-col">
                    <DialogHeader>
                        <DialogTitle>
                            {editingItem ? "Ubah Kategori" : "Tambah Kategori"}
                        </DialogTitle>
                        <DialogDescription>
                            Isi kode dan nama kategori pengadaan yang digunakan
                            dalam transaksi.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 px-6 py-4">
                        {/* 1. Kode Pengadaan */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Kode Pengadaan
                            </Label>
                            <Input
                                placeholder="Contoh: Pengadaan.001"
                                value={kodePengadaan}
                                onChange={(e) =>
                                    setKodePengadaan(e.target.value)
                                }
                                required
                                className="h-8 font-mono text-xs"
                            />
                        </div>

                        {/* 2. Kategori Pengadaan */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Kategori Pengadaan
                            </Label>
                            <Input
                                placeholder="Contoh: Pengadaan Bahan"
                                value={kategoriPengadaan}
                                onChange={(e) =>
                                    setKategoriPengadaan(e.target.value)
                                }
                                required
                                className="h-8 text-xs font-medium"
                            />
                        </div>

                        {/* 3. Keterangan */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Keterangan
                            </Label>
                            <Textarea
                                placeholder="Kategori pengadaan untuk item bahan"
                                value={keterangan}
                                onChange={(e) => setKeterangan(e.target.value)}
                                rows={2}
                                className="resize-none text-xs"
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <DialogCancel
                            disabled={saving}
                            onClick={() => onOpenChange(false)}
                        >
                            Batal
                        </DialogCancel>
                        <DialogAction type="submit" disabled={saving}>
                            {saving
                                ? "Menyimpan…"
                                : editingItem
                                  ? "Simpan"
                                  : "Tambah"}
                        </DialogAction>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
