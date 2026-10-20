import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Badge } from '@apperp/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import {
    Building2,
    Check,
    CheckCircle2,
    Clock,
    FileText,
    MapPin,
    Package,
    Printer,
    User,
    X,
    XCircle,
} from 'lucide-react';
import type { PrakualifikasiFormData } from './PrakualifikasiFormModal';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    data: (PrakualifikasiFormData & { status_vendor?: string }) | null;
    onEdit?: () => void;
    onDelete?: () => void;
};

function formatDisplayDate(dateStr: string): string {
    if (!dateStr) return '';
    if (dateStr.includes('/')) return dateStr;
    const parts = dateStr.split('-');
    if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }
    return dateStr;
}

export function PrakualifikasiDetailModal({
    open,
    onOpenChange,
    data,
    onEdit,
    onDelete,
}: Props) {
    if (!data) return null;

    const handlePrint = () => {
        window.print();
    };

    const getStatusBadge = (status: string) => {
        if (status === 'Lolos' || status === 'Memenuhi Syarat' || status === '1. Lolos') {
            return (
                <Badge className="bg-emerald-600 hover:bg-emerald-600/90 text-white font-medium">
                    <CheckCircle2 className="mr-1 h-3 w-3" />
                    Lolos
                </Badge>
            );
        }
        if (status === 'Batal' || status === 'Tidak Memenuhi Syarat' || status === '2. Batal') {
            return (
                <Badge variant="destructive" className="font-medium">
                    <XCircle className="mr-1 h-3 w-3" />
                    Batal
                </Badge>
            );
        }
        return (
            <Badge
                variant="secondary"
                className="bg-amber-500/15 text-amber-700 dark:text-amber-400 border-amber-500/30 font-medium"
            >
                <Clock className="mr-1 h-3 w-3" />
                {status || 'Dalam Proses'}
            </Badge>
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                <DialogHeader className="border-b pb-3">
                    <div className="flex items-center justify-between">
                        <DialogTitle className="flex items-center gap-2 text-xl font-bold">
                            <Building2 className="h-5 w-5 text-primary" />
                            Data Prakualifikasi Vendor/Supplier
                        </DialogTitle>
                        <div>{getStatusBadge(data.status_kualifikasi)}</div>
                    </div>
                    <DialogDescription className="font-mono text-xs text-muted-foreground">
                        Nomor Dokumen: <strong className="text-foreground">{data.nomor_prakualifikasi}</strong> · Tanggal: {formatDisplayDate(data.tanggal_prakualifikasi)}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-6 py-2 text-sm">
                    {/* Profil Vendor */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 rounded-lg border bg-muted/20 p-4">
                        <div className="space-y-2">
                            <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Identitas Vendor/Supplier
                            </div>
                            <div className="text-base font-bold text-foreground">
                                {data.nama_vendor}
                            </div>
                            <div className="text-xs font-mono text-muted-foreground">
                                Kode: {data.kode_vendor} · {data.jenis_vendor || 'Supplier'}
                            </div>
                            <div className="flex items-center gap-2 text-xs text-muted-foreground mt-2">
                                <MapPin className="h-3.5 w-3.5 shrink-0" />
                                <span>{data.alamat || '-'}</span>
                            </div>
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <User className="h-3.5 w-3.5 shrink-0" />
                                <span>Nama PIC Vendor/Supp: {data.nama_pic || '-'}</span>
                            </div>
                        </div>

                        <div className="space-y-2 border-t md:border-t-0 md:border-l md:pl-4 pt-2 md:pt-0">
                            <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Legalitas & Status Rekanan
                            </div>
                            <div className="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <span className="text-muted-foreground">Status Vendor:</span>
                                    <div className="font-semibold text-foreground">{data.status_vendor || 'Vendor Resmi'}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Badan Hukum:</span>
                                    <div className="font-medium text-foreground">{data.status_badan_hukum}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">NPWP/KTP:</span>
                                    <div className="font-mono text-foreground">{data.npwp_ktp || '-'}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Telepon/HP:</span>
                                    <div className="font-mono text-foreground">{data.telepon || '-'}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Email:</span>
                                    <div className="text-foreground">{data.email || '-'}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Waktu Kesediaan Item:</span>
                                    <div className="font-medium text-foreground">{data.waktu_kesediaan_item || '-'}</div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Jumlah Karyawan yg dimiliki:</span>
                                    <div className="font-medium text-foreground">{data.jumlah_karyawan ? `${data.jumlah_karyawan} Orang` : '-'}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Kelengkapan Dokumen */}
                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h4 className="font-semibold text-sm flex items-center gap-2">
                                <FileText className="h-4 w-4 text-primary" />
                                Kelengkapan Dokumen
                            </h4>
                            <span className="text-xs text-muted-foreground">
                                {data.dokumen?.filter((d) => d.ada).length || 0} dari {data.dokumen?.length || 0} Dokumen Terpenuhi
                            </span>
                        </div>

                        <div className="rounded-md border overflow-hidden">
                            <Table>
                                <TableHeader className="bg-sky-50 dark:bg-sky-950/40">
                                    <TableRow>
                                        <TableHead className="w-12 text-center text-xs">No.</TableHead>
                                        <TableHead className="text-xs">Uraian Dokumen</TableHead>
                                        <TableHead className="w-28 text-center text-xs">Status</TableHead>
                                        <TableHead className="text-xs">Keterangan</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(!data.dokumen || data.dokumen.length === 0) ? (
                                        <TableRow>
                                            <TableCell colSpan={4} className="py-4 text-center text-muted-foreground text-xs">
                                                Tidak ada catatan dokumen.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        data.dokumen.map((doc, idx) => (
                                            <TableRow key={idx} className="h-8 text-xs">
                                                <TableCell className="text-center font-medium font-mono">
                                                    {idx + 1}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {doc.nama_dokumen}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {doc.ada ? (
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                            <Check className="mr-1 h-3 w-3" /> Ada
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                                                            <X className="mr-1 h-3 w-3" /> Tidak Ada
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {doc.keterangan || '-'}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>

                    {/* Catatan Khusus */}
                    {data.catatan && (
                        <div className="rounded-md border p-3 bg-muted/10">
                            <div className="text-xs font-semibold text-muted-foreground mb-1">
                                Catatan Evaluasi:
                            </div>
                            <p className="text-xs text-foreground whitespace-pre-wrap">{data.catatan}</p>
                        </div>
                    )}

                    {/* Daftar List Item yang tersedia */}
                    <div className="space-y-3">
                        <h4 className="font-semibold text-sm flex items-center gap-2">
                            <Package className="h-4 w-4 text-primary" />
                            Daftar List Item yang tersedia
                        </h4>
                        <div className="rounded-md border overflow-hidden">
                            <Table>
                                <TableHeader className="bg-sky-50 dark:bg-sky-950/40">
                                    <TableRow>
                                        <TableHead className="w-12 text-center text-xs">No.</TableHead>
                                        <TableHead className="w-32 text-center text-xs">Jenis Item</TableHead>
                                        <TableHead className="w-28 text-center text-xs">Kode Item</TableHead>
                                        <TableHead className="text-center text-xs">Nama Item</TableHead>
                                        <TableHead className="w-24 text-center text-xs">Satuan</TableHead>
                                        <TableHead className="w-28 text-center text-xs">Kesediaan</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(!data.items || data.items.length === 0) ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="py-4 text-center text-muted-foreground text-xs">
                                                Tidak ada data item barang/jasa.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        data.items.map((item, idx) => (
                                            <TableRow key={idx} className="h-8 text-xs">
                                                <TableCell className="text-center font-medium font-mono">
                                                    {idx + 1}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {item.jenis_item || '-'}
                                                </TableCell>
                                                <TableCell className="text-center font-mono text-muted-foreground">
                                                    {item.kode_item || '-'}
                                                </TableCell>
                                                <TableCell className="text-center font-semibold text-foreground">
                                                    {item.nama_item}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {item.satuan || '-'}
                                                </TableCell>
                                                <TableCell className="text-center font-mono">
                                                    {item.kesediaan || 'Tersedia'}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>

                <DialogFooter className="flex items-center justify-between border-t pt-4 sm:justify-between">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={handlePrint}
                        className="gap-1.5 h-8 text-xs"
                    >
                        <Printer className="h-4 w-4" />
                        Cetak
                    </Button>
                    <div className="flex gap-2">
                        {onDelete && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    onOpenChange(false);
                                    onDelete();
                                }}
                                className="h-8 text-xs text-destructive hover:bg-destructive/10 border-destructive/30"
                            >
                                Arsipkan
                            </Button>
                        )}
                        {onEdit && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => {
                                    onOpenChange(false);
                                    onEdit();
                                }}
                                className="h-8 text-xs"
                            >
                                Ubah
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="default"
                            onClick={() => onOpenChange(false)}
                            className="h-8 text-xs"
                        >
                            Tutup
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
