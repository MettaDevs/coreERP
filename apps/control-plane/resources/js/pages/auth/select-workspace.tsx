import { Head, useForm, router } from '@inertiajs/react';
import { Building2, Shield, ArrowRight, Loader2, LogOut, CheckCircle2, Plus, Package, Sparkles, Trash2, AlertTriangle } from 'lucide-react';
import { useState, useEffect } from 'react';

import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import { Field, FieldError, FieldGroup, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { cn } from '@/lib/utils';

type Business = {
    id: string;
    tenant_id: string;
    tenant_name: string;
    system_role: string;
    status: string;
};

type AppOption = {
    id: string;
    name: string;
    description: string;
};

type Props = {
    businesses: Business[];
    activeMembershipId: string | null;
    maxBusinesses?: number;
    availableApps?: AppOption[];
};

export default function SelectWorkspace({
    businesses = [],
    activeMembershipId,
    maxBusinesses = 3,
    availableApps = [],
}: Props) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [businessToDelete, setBusinessToDelete] = useState<Business | null>(null);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const selectForm = useForm({
        membership_id: '',
    });

    const createForm = useForm({
        business_name: '',
        app_ids: (availableApps.length === 1 ? [availableApps[0].id] : []) as string[],
    });

    useEffect(() => {
        if (availableApps.length === 1 && createForm.data.app_ids.length === 0) {
            createForm.setData('app_ids', [availableApps[0].id]);
        }
    }, [availableApps]);

    const handleSelectWorkspace = (membershipId: string) => {
        setSelectedId(membershipId);
        selectForm.setData('membership_id', membershipId);
        selectForm.post('/select-workspace', {
            onError: () => setSelectedId(null),
        });
    };

    const handleToggleApp = (appId: string) => {
        const current = createForm.data.app_ids;
        if (current.includes(appId)) {
            createForm.setData('app_ids', current.filter((id) => id !== appId));
        } else {
            createForm.setData('app_ids', [...current, appId]);
        }
    };

    const handleCreateBusiness = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post('/select-workspace/create', {
            onSuccess: () => {
                setIsCreateOpen(false);
                createForm.reset();
            },
        });
    };

    const handleDeleteBusiness = () => {
        if (!businessToDelete) return;
        setIsDeleting(true);
        router.delete(`/select-workspace/${businessToDelete.id}`, {
            onSuccess: () => {
                setIsDeleteOpen(false);
                setBusinessToDelete(null);
                setIsDeleting(false);
            },
            onError: () => {
                setIsDeleting(false);
            },
        });
    };

    const handleLogout = () => {
        router.post('/logout');
    };

    const isQuotaFull = businesses.length >= maxBusinesses;

    return (
        <>
            <Head title="Pilih Workspace" />

            <div className="space-y-4">
                <div className="grid gap-3">
                    {businesses.length === 0 && (
                        <div className="p-5 sm:p-6 rounded-2xl bg-white/80 dark:bg-slate-900/80 border border-slate-200/90 dark:border-slate-800 text-center space-y-2">
                            <div className="size-12 rounded-2xl bg-[#EAFBFC] dark:bg-cyan-950/70 text-[#00AFC0] flex items-center justify-center mx-auto shadow-xs">
                                <Building2 className="size-6 stroke-[2.2]" />
                            </div>
                            <div>
                                <h4 className="text-sm font-bold text-slate-900 dark:text-white">
                                    Belum Ada Bisnis Terdaftar
                                </h4>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-xs mx-auto leading-relaxed">
                                    Akun Anda belum memiliki unit bisnis terdaftar. Daftarkan bisnis pertama Anda di bawah ini.
                                </p>
                            </div>
                        </div>
                    )}

                    {businesses.map((biz) => {
                        const isSelecting = selectForm.processing && selectedId === biz.id;
                        const isActive = activeMembershipId === biz.id;
                        const isOwner = biz.system_role === 'owner';

                        return (
                            <div
                                key={biz.id}
                                onClick={() => !selectForm.processing && !isDeleting && handleSelectWorkspace(biz.id)}
                                className={cn(
                                    'group relative flex items-center justify-between p-3.5 sm:p-4 rounded-2xl border transition-all duration-200 text-left w-full max-w-full overflow-hidden cursor-pointer',
                                    'bg-white dark:bg-slate-900 hover:border-[#00AFC0] dark:hover:border-[#00AFC0] hover:shadow-md hover:shadow-cyan-500/10',
                                    isActive
                                        ? 'border-[#00AFC0] dark:border-[#00AFC0] ring-2 ring-[#00AFC0]/20 bg-[#EAFBFC]/40 dark:bg-cyan-950/20'
                                        : 'border-slate-200/80 dark:border-slate-800'
                                )}
                            >
                                <div className="flex items-center gap-3 min-w-0 flex-1 overflow-hidden mr-2">
                                    <div
                                        className={cn(
                                            'p-2.5 rounded-xl shrink-0 transition-all flex items-center justify-center',
                                            isActive
                                                ? 'bg-[#00AFC0] text-white shadow-md shadow-cyan-600/25'
                                                : 'bg-[#EAFBFC] dark:bg-cyan-950/80 text-[#00AFC0] dark:text-cyan-300 group-hover:bg-[#00AFC0] group-hover:text-white'
                                        )}
                                    >
                                        <Building2 className="size-5 text-current stroke-[2.2]" />
                                    </div>
                                    <div className="min-w-0 flex-1 overflow-hidden">
                                        <div className="flex items-center gap-1.5 min-w-0 w-full">
                                            <h3
                                                className="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 truncate min-w-0 flex-1 block"
                                                title={biz.tenant_name}
                                            >
                                                {biz.tenant_name}
                                            </h3>
                                            {isActive && (
                                                <span className="inline-flex items-center gap-1 text-[9.5px] font-semibold text-[#00AFC0] dark:text-cyan-400 bg-[#EAFBFC] dark:bg-cyan-950/60 px-1.5 py-0.5 rounded-full border border-[#00AFC0]/20 shrink-0">
                                                    <CheckCircle2 className="size-2.5 text-[#00AFC0]" />
                                                    <span>Aktif</span>
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-1.5 mt-0.5">
                                            <span className="inline-flex items-center gap-1 text-[11px] text-slate-500 dark:text-slate-400 capitalize">
                                                <Shield className="size-3 text-slate-400" />
                                                <span>Peran: {biz.system_role}</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-1.5 shrink-0 ml-1">
                                    {isOwner && (
                                        <button
                                            type="button"
                                            title="Hapus Bisnis"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                setBusinessToDelete(biz);
                                                setIsDeleteOpen(true);
                                            }}
                                            className="size-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/60 border border-transparent hover:border-rose-200/80 transition-all cursor-pointer shrink-0 z-10"
                                        >
                                            <Trash2 className="size-3.5" />
                                        </button>
                                    )}

                                    {isSelecting ? (
                                        <Loader2 className="size-5 animate-spin text-[#00AFC0] dark:text-cyan-400 shrink-0" />
                                    ) : (
                                        <div
                                            className={cn(
                                                'size-8 rounded-full transition-all shrink-0 flex items-center justify-center',
                                                isActive
                                                    ? 'bg-gradient-to-r from-[#005F73] via-[#00A8B5] to-[#00C9C8] text-white shadow-md shadow-cyan-600/25'
                                                    : 'bg-slate-100 dark:bg-slate-800 text-slate-400 group-hover:bg-gradient-to-r group-hover:from-[#005F73] group-hover:to-[#00C9C8] group-hover:text-white'
                                            )}
                                        >
                                            <ArrowRight className="size-3.5" />
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    {/* DELETE BUSINESS CONFIRMATION MODAL */}
                    <Dialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen}>
                        <DialogContent className="sm:max-w-md rounded-3xl p-6">
                            <DialogHeader className="space-y-2">
                                <div className="size-12 rounded-2xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 flex items-center justify-center mb-1">
                                    <Trash2 className="size-6 stroke-[2.2]" />
                                </div>
                                <DialogTitle className="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                                    Hapus Bisnis "{businessToDelete?.tenant_name}"?
                                </DialogTitle>
                                <DialogDescription className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                    Apakah Anda yakin ingin menghapus bisnis ini? Semua data, hak akses, dan modul aplikasi yang terkait dengan bisnis ini akan dihapus secara permanen.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100 dark:border-slate-800">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={isDeleting}
                                    onClick={() => {
                                        setIsDeleteOpen(false);
                                        setBusinessToDelete(null);
                                    }}
                                    className="h-9 px-4 rounded-xl text-xs font-semibold"
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={isDeleting}
                                    onClick={handleDeleteBusiness}
                                    className="h-9 px-4 rounded-xl text-xs font-bold gap-1.5 bg-rose-600 hover:bg-rose-700 text-white shadow-md shadow-rose-600/20 cursor-pointer"
                                >
                                    {isDeleting ? (
                                        <Loader2 className="size-3.5 animate-spin" />
                                    ) : (
                                        <Trash2 className="size-3.5" />
                                    )}
                                    <span>Ya, Hapus Bisnis</span>
                                </Button>
                            </div>
                        </DialogContent>
                    </Dialog>

                    {/* ADD BUSINESS ACTION */}
                    {!isQuotaFull ? (
                        <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                            <DialogTrigger asChild>
                                <button
                                    type="button"
                                    className="group relative flex items-center justify-between p-4 rounded-2xl border border-dashed border-cyan-300 dark:border-cyan-800/80 bg-cyan-50/30 dark:bg-cyan-950/20 hover:bg-cyan-50/70 dark:hover:bg-cyan-950/40 hover:border-[#00AFC0] transition-all text-left w-full cursor-pointer"
                                >
                                    <div className="flex items-center gap-3.5 min-w-0">
                                        <div className="size-11 rounded-xl bg-white dark:bg-slate-900 border border-cyan-200 dark:border-cyan-800/60 flex items-center justify-center text-[#00AFC0] shadow-xs group-hover:scale-105 transition-transform">
                                            <Plus className="size-5 stroke-[2.5]" />
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                                <span>Tambah Bisnis Baru</span>
                                                <span className="text-[10px] font-semibold text-[#00AFC0] bg-white dark:bg-slate-800 px-2 py-0.5 rounded-full border border-cyan-200 dark:border-cyan-800">
                                                    {businesses.length}/{maxBusinesses} Bisnis
                                                </span>
                                            </h4>
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                                Daftarkan unit bisnis atau perusahaan baru ke akun ini.
                                            </p>
                                        </div>
                                    </div>
                                    <div className="size-8 rounded-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 flex items-center justify-center text-slate-400 group-hover:text-[#00AFC0] group-hover:border-[#00AFC0] transition-all">
                                        <ArrowRight className="size-4" />
                                    </div>
                                </button>
                            </DialogTrigger>

                            <DialogContent className="sm:max-w-md rounded-3xl p-6">
                                <DialogHeader className="space-y-1">
                                    <DialogTitle className="text-base sm:text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <Building2 className="size-5 text-[#00AFC0]" />
                                        <span>Tambah Bisnis Baru</span>
                                    </DialogTitle>
                                    <DialogDescription className="text-xs text-slate-500 dark:text-slate-400">
                                        Isi nama bisnis baru dan pilih modul aplikasi awal yang ingin diaktifkan.
                                    </DialogDescription>
                                </DialogHeader>

                                <form onSubmit={handleCreateBusiness} className="space-y-4 pt-2">
                                    <FieldGroup>
                                        <Field>
                                            <FieldLabel className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                Nama Bisnis / Perusahaan
                                            </FieldLabel>
                                            <Input
                                                id="business_name"
                                                type="text"
                                                required
                                                autoFocus
                                                maxLength={35}
                                                placeholder="Contoh: PT Sanata Niaga Sukses"
                                                value={createForm.data.business_name}
                                                onChange={(e) => createForm.setData('business_name', e.target.value)}
                                                className="h-10 rounded-xl text-xs sm:text-sm border-slate-200 dark:border-slate-800 focus-visible:ring-[#00AFC0]"
                                            />
                                            <div className="flex items-center justify-between text-[10.5px] text-slate-400 mt-1">
                                                <span>Maksimal 35 karakter</span>
                                                <span className={cn(createForm.data.business_name.length >= 30 ? 'text-amber-500 font-semibold' : '')}>
                                                    {createForm.data.business_name.length}/35
                                                </span>
                                            </div>
                                            {createForm.errors.business_name && (
                                                <FieldError>{createForm.errors.business_name}</FieldError>
                                            )}
                                        </Field>

                                        <Field className="space-y-1.5">
                                            <FieldLabel className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                Pilih Modul Aplikasi ERP
                                            </FieldLabel>
                                            <div className="grid grid-cols-1 gap-2 max-h-48 overflow-y-auto pr-1">
                                                {availableApps.map((app) => {
                                                    const isSelected = createForm.data.app_ids.includes(app.id);
                                                    return (
                                                        <div
                                                            key={app.id}
                                                            onClick={() => handleToggleApp(app.id)}
                                                            className={cn(
                                                                'p-2.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between text-left',
                                                                isSelected
                                                                    ? 'bg-cyan-50/70 dark:bg-cyan-950/40 border-[#00AFC0] ring-1 ring-[#00AFC0]/30'
                                                                    : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 hover:border-slate-300'
                                                            )}
                                                        >
                                                            <div className="flex items-center gap-2.5 min-w-0">
                                                                <div className="size-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[#00AFC0] shrink-0">
                                                                    <Package className="size-4" />
                                                                </div>
                                                                <div className="min-w-0">
                                                                    <h5 className="text-xs font-bold text-slate-900 dark:text-white truncate">
                                                                        {app.name}
                                                                    </h5>
                                                                    <p className="text-[10px] text-slate-500 line-clamp-1">
                                                                        {app.description || 'Modul operasional ERP.'}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <div
                                                                className={cn(
                                                                    'size-4 rounded-full flex items-center justify-center shrink-0 ml-2',
                                                                    isSelected
                                                                        ? 'bg-[#00AFC0] text-white'
                                                                        : 'border border-slate-300 dark:border-slate-700'
                                                                )}
                                                            >
                                                                {isSelected && <CheckCircle2 className="size-3 stroke-[3]" />}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                            {createForm.errors.app_ids && (
                                                <FieldError>{createForm.errors.app_ids}</FieldError>
                                            )}
                                        </Field>
                                    </FieldGroup>

                                    <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setIsCreateOpen(false)}
                                            className="h-9 px-4 rounded-xl text-xs font-semibold"
                                        >
                                            Batal
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={createForm.processing || createForm.data.app_ids.length === 0 || !createForm.data.business_name.trim()}
                                            className="h-9 px-4 rounded-xl btn-gradient-primary text-xs font-bold gap-1.5 shadow-md shadow-cyan-600/20"
                                        >
                                            {createForm.processing ? (
                                                <Loader2 className="size-3.5 animate-spin" />
                                            ) : (
                                                <Sparkles className="size-3.5" />
                                            )}
                                            <span>Buat Bisnis Baru</span>
                                        </Button>
                                    </div>
                                </form>
                            </DialogContent>
                        </Dialog>
                    ) : (
                        <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-center">
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Batas kuota bisnis tercapai ({businesses.length}/{maxBusinesses} bisnis).
                            </p>
                        </div>
                    )}
                </div>

                <div className="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center">
                    <p className="text-xs text-slate-400 dark:text-slate-500">
                        {businesses.length} dari maksimal {maxBusinesses} bisnis terhubung
                    </p>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={handleLogout}
                        className="text-xs text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100 flex items-center gap-1.5 cursor-pointer"
                    >
                        <LogOut className="size-3.5" />
                        <span>Keluar Akun</span>
                    </Button>
                </div>
            </div>
        </>
    );
}

SelectWorkspace.layout = {
    title: 'Pilih Workspace / Bisnis',
    description: 'Kelola dan pilih workspace bisnis yang ingin Anda buka atau daftarkan bisnis baru.',
};

