import { Head, router } from '@inertiajs/react';
import { 
    Plus, 
    Search, 
    Pencil, 
    Trash2, 
    ToggleLeft, 
    ToggleRight, 
    Building2,
    MoreVertical
} from 'lucide-react';
import { useState, useMemo } from 'react';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@apperp/ui/card';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
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
    Dialog,
    DialogAction,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';

export type AssetEntity = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    status: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    entities: AssetEntity[];
    filters: {
        search: string;
    };
    canManage: boolean;
};

export default function EntitasAsetPage({ entities = [], filters, canManage = true }: Props) {
    const [searchQuery, setSearchQuery] = useState(filters?.search || '');
    
    // Modal state
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    
    const [selectedEntity, setSelectedEntity] = useState<AssetEntity | null>(null);

    // Form state
    const [formData, setFormData] = useState({
        code: '',
        name: '',
        description: '',
        status: true,
    });
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});

    const filteredEntities = useMemo(() => {
        if (!searchQuery.trim()) return entities;
        const q = searchQuery.toLowerCase();
        return entities.filter(
            (item) =>
                item.code.toLowerCase().includes(q) ||
                item.name.toLowerCase().includes(q) ||
                (item.description && item.description.toLowerCase().includes(q))
        );
    }, [entities, searchQuery]);

    const handleOpenAdd = () => {
        setFormData({
            code: '',
            name: '',
            description: '',
            status: true,
        });
        setFormErrors({});
        setIsAddOpen(true);
    };

    const handleOpenEdit = (entity: AssetEntity) => {
        setSelectedEntity(entity);
        setFormData({
            code: entity.code,
            name: entity.name,
            description: entity.description || '',
            status: entity.status,
        });
        setFormErrors({});
        setIsEditOpen(true);
    };

    const handleOpenDelete = (entity: AssetEntity) => {
        setSelectedEntity(entity);
        setIsDeleteOpen(true);
    };

    const handleSubmitAdd = (e: React.FormEvent) => {
        e.preventDefault();
        setFormErrors({});

        if (!formData.code.trim()) {
            setFormErrors((prev) => ({ ...prev, code: 'Kode Entitas Aset wajib diisi' }));
            return;
        }
        if (!formData.name.trim()) {
            setFormErrors((prev) => ({ ...prev, name: 'Nama Entitas Aset wajib diisi' }));
            return;
        }

        router.post('/master-data/entitas-aset', formData, {
            onSuccess: () => {
                setIsAddOpen(false);
            },
            onError: (errors) => {
                setFormErrors(errors as Record<string, string>);
            },
        });
    };

    const handleSubmitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedEntity) return;
        setFormErrors({});

        if (!formData.code.trim()) {
            setFormErrors((prev) => ({ ...prev, code: 'Kode Entitas Aset wajib diisi' }));
            return;
        }
        if (!formData.name.trim()) {
            setFormErrors((prev) => ({ ...prev, name: 'Nama Entitas Aset wajib diisi' }));
            return;
        }

        router.patch(`/master-data/entitas-aset/${selectedEntity.id}`, formData, {
            onSuccess: () => {
                setIsEditOpen(false);
                setSelectedEntity(null);
            },
            onError: (errors) => {
                setFormErrors(errors as Record<string, string>);
            },
        });
    };

    const handleToggleStatus = (entity: AssetEntity) => {
        router.post(`/master-data/entitas-aset/${entity.id}/toggle-status`, {}, {
            preserveScroll: true,
        });
    };

    const handleDelete = () => {
        if (!selectedEntity) return;
        router.delete(`/master-data/entitas-aset/${selectedEntity.id}`, {
            onSuccess: () => {
                setIsDeleteOpen(false);
                setSelectedEntity(null);
            },
        });
    };

    return (
        <>
            <Head title="Master Data - Entitas Aset" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6 max-w-7xl mx-auto w-full">
                {/* Header Title */}
                <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <Building2 className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                            <h1 className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">
                                ENTITAS ASET
                            </h1>
                        </div>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                            Kelola daftar entitas aset utama dalam hirarki Master Data Asset Management.
                        </p>
                    </div>
                </div>

                {/* Main Card Content */}
                <Card className="shadow-sm border-neutral-200 dark:border-neutral-800">
                    <CardHeader className="pb-4">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <CardTitle className="text-lg font-semibold">
                                    Daftar Entitas Aset
                                </CardTitle>
                                <CardDescription>
                                    Total {filteredEntities.length} entitas terdaftar.
                                </CardDescription>
                            </div>

                            <div className="flex items-center gap-3">
                                {/* Search Bar */}
                                <div className="relative w-full sm:w-64">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-neutral-500" />
                                    <Input
                                        type="search"
                                        placeholder="Cari kode/nama entitas..."
                                        className="pl-9 text-sm"
                                        value={searchQuery}
                                        onChange={(e) => setSearchQuery(e.target.value)}
                                    />
                                </div>

                                {/* Tambah Button */}
                                {canManage && (
                                    <Button
                                        onClick={handleOpenAdd}
                                        className="bg-indigo-600 hover:bg-indigo-700 text-white font-medium flex items-center gap-2"
                                    >
                                        <Plus className="h-4 w-4" />
                                        TAMBAH
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader className="bg-neutral-50 dark:bg-neutral-900/50">
                                    <TableRow>
                                        <TableHead className="w-12 text-center font-bold">No</TableHead>
                                        <TableHead className="w-32 text-center font-bold">Aksi</TableHead>
                                        <TableHead className="font-bold">Kode Entitas Aset</TableHead>
                                        <TableHead className="font-bold">Nama Entitas Asset</TableHead>
                                        <TableHead className="font-bold">Keterangan</TableHead>
                                        <TableHead className="w-28 text-center font-bold">Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredEntities.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="h-32 text-center text-neutral-500">
                                                Belum ada data Entitas Aset. Klik tombol <strong>+ TAMBAH</strong> untuk membuat entitas baru.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        filteredEntities.map((item, index) => (
                                            <TableRow key={item.id} className="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50">
                                                <TableCell className="text-center font-medium">
                                                    {index + 1}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                                                <MoreVertical className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="center" className="w-40">
                                                            <DropdownMenuItem
                                                                onClick={() => handleOpenEdit(item)}
                                                                className="cursor-pointer"
                                                            >
                                                                <Pencil className="mr-2 h-4 w-4 text-amber-600" />
                                                                Edit
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                onClick={() => handleToggleStatus(item)}
                                                                className="cursor-pointer"
                                                            >
                                                                {item.status ? (
                                                                    <>
                                                                        <ToggleLeft className="mr-2 h-4 w-4 text-neutral-500" />
                                                                        Non Aktifkan
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <ToggleRight className="mr-2 h-4 w-4 text-emerald-600" />
                                                                        Aktifkan
                                                                    </>
                                                                )}
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                onClick={() => handleOpenDelete(item)}
                                                                className="cursor-pointer text-red-600 focus:text-red-600"
                                                            >
                                                                <Trash2 className="mr-2 h-4 w-4" />
                                                                Hapus
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </TableCell>
                                                <TableCell className="font-semibold text-indigo-700 dark:text-indigo-300">
                                                    {item.code}
                                                </TableCell>
                                                <TableCell className="font-medium text-neutral-900 dark:text-neutral-100">
                                                    {item.name}
                                                </TableCell>
                                                <TableCell className="text-neutral-600 dark:text-neutral-400">
                                                    {item.description || '-'}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {item.status ? (
                                                        <Badge className="bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 hover:bg-emerald-100">
                                                            Aktif
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-neutral-500 border-neutral-300">
                                                            Non-Aktif
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* MODAL TAMBAH ENTITAS ASET */}
            <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                <DialogContent className="sm:max-w-[480px]">
                    <DialogHeader>
                        <DialogTitle className="text-lg font-bold">Tambah Entitas Aset</DialogTitle>
                        <DialogDescription>
                            Isi formulir di bawah ini untuk menambahkan entitas aset baru.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmitAdd} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="code" className="text-sm font-semibold">
                                Kode Entitas Aset <span className="text-red-500">*</span>
                            </Label>
                            <Input
                                id="code"
                                placeholder="Contoh: E001"
                                value={formData.code}
                                onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                                className={formErrors.code ? 'border-red-500' : ''}
                            />
                            {formErrors.code && (
                                <p className="text-xs text-red-500 mt-1">{formErrors.code}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="name" className="text-sm font-semibold">
                                Nama Entitas Aset <span className="text-red-500">*</span>
                            </Label>
                            <Input
                                id="name"
                                placeholder="Contoh: PT. Sanata System"
                                value={formData.name}
                                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                className={formErrors.name ? 'border-red-500' : ''}
                            />
                            {formErrors.name && (
                                <p className="text-xs text-red-500 mt-1">{formErrors.name}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="description" className="text-sm font-semibold">
                                Keterangan
                            </Label>
                            <Textarea
                                id="description"
                                placeholder="Contoh: Entitas Induk"
                                rows={3}
                                value={formData.description}
                                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                            />
                        </div>

                        <DialogFooter className="pt-4">
                            <Button type="button" variant="outline" onClick={() => setIsAddOpen(false)}>
                                Batal
                            </Button>
                            <Button type="submit" className="bg-indigo-600 hover:bg-indigo-700 text-white">
                                Tambah
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL EDIT ENTITAS ASET */}
            <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
                <DialogContent className="sm:max-w-[480px]">
                    <DialogHeader>
                        <DialogTitle className="text-lg font-bold">Edit Entitas Aset</DialogTitle>
                        <DialogDescription>
                            Ubah informasi data entitas aset.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmitEdit} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-code" className="text-sm font-semibold">
                                Kode Entitas Aset <span className="text-red-500">*</span>
                            </Label>
                            <Input
                                id="edit-code"
                                value={formData.code}
                                onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                                className={formErrors.code ? 'border-red-500' : ''}
                            />
                            {formErrors.code && (
                                <p className="text-xs text-red-500 mt-1">{formErrors.code}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="edit-name" className="text-sm font-semibold">
                                Nama Entitas Aset <span className="text-red-500">*</span>
                            </Label>
                            <Input
                                id="edit-name"
                                value={formData.name}
                                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                className={formErrors.name ? 'border-red-500' : ''}
                            />
                            {formErrors.name && (
                                <p className="text-xs text-red-500 mt-1">{formErrors.name}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="edit-description" className="text-sm font-semibold">
                                Keterangan
                            </Label>
                            <Textarea
                                id="edit-description"
                                rows={3}
                                value={formData.description}
                                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                            />
                        </div>

                        <DialogFooter className="pt-4">
                            <Button type="button" variant="outline" onClick={() => setIsEditOpen(false)}>
                                Batal
                            </Button>
                            <Button type="submit" className="bg-indigo-600 hover:bg-indigo-700 text-white">
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL HAPUS KONFIRMASI */}
            <Dialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen}>
                <DialogContent size="compact" className="rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
                    <DialogHeader className="flex flex-row items-center gap-3 space-y-0 text-left border-b-0 p-0">
                        <div className="p-2.5 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200/60 dark:border-red-900/60 text-[#FF4D4F] shrink-0">
                            <Trash2 className="size-5" />
                        </div>
                        <DialogTitle className="text-base font-bold text-slate-900 dark:text-slate-100">
                            Hapus Entitas Aset?
                        </DialogTitle>
                    </DialogHeader>
                    <DialogDescription className="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                        Apakah Anda yakin ingin menghapus Entitas Aset <strong>{selectedEntity?.name}</strong> ({selectedEntity?.code})? Tindakan ini tidak dapat dibatalkan.
                    </DialogDescription>
                    <DialogFooter className="gap-2 pt-3 border-t border-slate-100 dark:border-slate-800 bg-transparent px-0 pb-0">
                        <DialogCancel onClick={() => setIsDeleteOpen(false)} className="border border-[#00AFC0] text-[#00AFC0] hover:bg-[#EAFBFC] rounded-full px-4 py-2 text-xs font-bold bg-transparent transition-all cursor-pointer">
                            Batal
                        </DialogCancel>
                        <DialogAction type="button" onClick={handleDelete} className="bg-[#FF4D4F] hover:bg-[#DC2626] text-white rounded-full px-5 py-2 text-xs font-bold border-none transition-all cursor-pointer shadow-xs">
                            Ya, Hapus
                        </DialogAction>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

EntitasAsetPage.layout = {
    breadcrumbs: [
        {
            title: 'Master Data',
            href: '/master-data/entitas-aset',
        },
        {
            title: 'Entitas Aset',
            href: '/master-data/entitas-aset',
        },
    ],
};
