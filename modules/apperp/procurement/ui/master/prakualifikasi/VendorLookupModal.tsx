import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Search } from 'lucide-react';
import { api } from '../../api';

export type VendorLookupItem = {
    vendor_id: string;
    kode_vendor: string;
    nama_vendor: string;
    alamat: string;
    telepon?: string;
    email?: string;
    nama_pic?: string;
    jenis_vendor?: string;
    status_badan_hukum?: string;
    items?: Array<{
        urutan?: number;
        jenis_item: string;
        kode_item: string;
        nama_item: string;
        satuan: string;
        kesediaan: string;
        keterangan?: string;
    }>;
};

type VendorLookupModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSelect: (vendor: VendorLookupItem) => void;
};

export function VendorLookupModal({
    open,
    onOpenChange,
    onSelect,
}: VendorLookupModalProps) {
    const [vendors, setVendors] = useState<VendorLookupItem[]>([]);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [selectedCode, setSelectedCode] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setSelectedCode(null);
            return;
        }

        let isMounted = true;
        setLoading(true);

        api<{ status: string; data: VendorLookupItem[] }>(
            `/prakualifikasi/vendors/lookup?search=${encodeURIComponent(search)}`,
        )
            .then((res) => {
                if (isMounted) {
                    setVendors(res.data ?? []);
                }
            })
            .catch(() => {
                if (isMounted) {
                    setVendors([]);
                }
            })
            .finally(() => {
                if (isMounted) {
                    setLoading(false);
                }
            });

        return () => {
            isMounted = false;
        };
    }, [open, search]);

    const handleConfirm = () => {
        const found = vendors.find((v) => v.kode_vendor === selectedCode);
        if (found) {
            onSelect(found);
            onOpenChange(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogHeader className="border-b pb-3">
                    <DialogTitle className="text-base font-semibold">
                        Cari Data Vendor/Supplier
                    </DialogTitle>
                    <DialogDescription className="text-muted-foreground text-xs">
                        Pilih data rekanan vendor dari master untuk mengisi
                        identitas formulir secara otomatis.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3 py-2">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute left-2.5 top-2.5 h-3.5 w-3.5" />
                        <Input
                            placeholder="Cari kode, nama, atau alamat vendor..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="h-8 pl-8 text-xs"
                            autoFocus
                        />
                    </div>

                    <div className="overflow-hidden rounded-md border">
                        <Table>
                            <TableHeader className="bg-muted/40 border-b">
                                <TableRow className="h-8">
                                    <TableHead className="w-12 text-center text-xs"></TableHead>
                                    <TableHead className="w-36 text-xs font-semibold">
                                        Kode Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="text-xs font-semibold">
                                        Nama Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="text-xs font-semibold">
                                        Alamat Vendor/Supplier
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="text-muted-foreground py-8 text-center text-xs"
                                        >
                                            Memuat data vendor...
                                        </TableCell>
                                    </TableRow>
                                ) : vendors.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="text-muted-foreground py-8 text-center text-xs"
                                        >
                                            Tidak ada vendor ditemukan.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    vendors.map((v) => {
                                        const isSelected =
                                            selectedCode === v.kode_vendor;
                                        return (
                                            <TableRow
                                                key={v.kode_vendor}
                                                onClick={() =>
                                                    setSelectedCode(
                                                        v.kode_vendor,
                                                    )
                                                }
                                                className={`h-9 cursor-pointer text-xs transition-colors ${
                                                    isSelected
                                                        ? 'bg-primary/10 font-medium'
                                                        : 'hover:bg-muted/50'
                                                }`}
                                            >
                                                <TableCell className="py-1 text-center">
                                                    <input
                                                        type="radio"
                                                        name="selected_vendor"
                                                        checked={isSelected}
                                                        onChange={() =>
                                                            setSelectedCode(
                                                                v.kode_vendor,
                                                            )
                                                        }
                                                        className="text-primary h-3.5 w-3.5 cursor-pointer"
                                                    />
                                                </TableCell>
                                                <TableCell className="py-1 font-mono text-xs font-medium">
                                                    {v.kode_vendor}
                                                </TableCell>
                                                <TableCell className="text-foreground py-1 text-xs font-semibold">
                                                    {v.nama_vendor}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground py-1 text-xs">
                                                    {v.alamat || '-'}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <DialogFooter className="flex items-center justify-end gap-2 border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        className="h-8 text-xs"
                    >
                        Batal
                    </Button>
                    <Button
                        type="button"
                        disabled={!selectedCode}
                        onClick={handleConfirm}
                        className="bg-primary hover:bg-primary/90 text-primary-foreground h-8 min-w-[80px] text-xs font-medium"
                    >
                        Pilih Rekanan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
