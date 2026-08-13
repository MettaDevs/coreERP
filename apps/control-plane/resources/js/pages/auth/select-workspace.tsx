import { Head, useForm, router } from '@inertiajs/react';
import { Building2, Shield, ArrowRight, Loader2, LogOut, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@apperp/ui/button';
import { cn } from '@/lib/utils';

type Business = {
    id: string;
    tenant_id: string;
    tenant_name: string;
    system_role: string;
    status: string;
};

type Props = {
    businesses: Business[];
    activeMembershipId: string | null;
};

export default function SelectWorkspace({ businesses, activeMembershipId }: Props) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const form = useForm({
        membership_id: '',
    });

    const handleSelectWorkspace = (membershipId: string) => {
        setSelectedId(membershipId);
        form.setData('membership_id', membershipId);
        form.post('/select-workspace', {
            onError: () => setSelectedId(null),
        });
    };

    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <>
            <Head title="Pilih Workspace" />

            <div className="space-y-4">
                <div className="grid gap-3">
                    {businesses.map((biz) => {
                        const isSelecting = form.processing && selectedId === biz.id;
                        const isActive = activeMembershipId === biz.id;

                        return (
                            <button
                                key={biz.id}
                                type="button"
                                disabled={form.processing}
                                onClick={() => handleSelectWorkspace(biz.id)}
                                className={cn(
                                    'group relative flex items-center justify-between p-4 sm:p-5 rounded-2xl border transition-all duration-200 text-left w-full cursor-pointer',
                                    'bg-white dark:bg-slate-900 hover:border-[#00AFC0] dark:hover:border-[#00AFC0] hover:shadow-md hover:shadow-cyan-500/10',
                                    isActive
                                        ? 'border-[#00AFC0] dark:border-[#00AFC0] ring-2 ring-[#00AFC0]/20 bg-[#EAFBFC]/40 dark:bg-cyan-950/20'
                                        : 'border-slate-200/80 dark:border-slate-800'
                                )}
                            >
                                <div className="flex items-center gap-3.5 min-w-0">
                                    <div
                                        className={cn(
                                            'p-3 rounded-xl shrink-0 transition-all flex items-center justify-center',
                                            isActive
                                                ? 'bg-[#00AFC0] text-white shadow-md shadow-cyan-600/25'
                                                : 'bg-[#EAFBFC] dark:bg-cyan-950/80 text-[#00AFC0] dark:text-cyan-300 group-hover:bg-[#00AFC0] group-hover:text-white'
                                        )}
                                    >
                                        <Building2 className="size-5 text-current stroke-[2.2]" />
                                    </div>
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-sm sm:text-base font-bold text-slate-900 dark:text-slate-100 truncate">
                                                {biz.tenant_name}
                                            </h3>
                                            {isActive && (
                                                <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-[#00AFC0] dark:text-cyan-400 bg-[#EAFBFC] dark:bg-cyan-950/60 px-2 py-0.5 rounded-full border border-[#00AFC0]/20">
                                                    <CheckCircle2 className="size-3 text-[#00AFC0]" />
                                                    <span>Aktif</span>
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2 mt-1">
                                            <span className="inline-flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400 capitalize">
                                                <Shield className="size-3 text-slate-400" />
                                                <span>Peran: {biz.system_role}</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 shrink-0 ml-3">
                                    {isSelecting ? (
                                        <Loader2 className="size-5 animate-spin text-[#00AFC0] dark:text-cyan-400" />
                                    ) : (
                                        <div
                                            className={cn(
                                                'p-2.5 rounded-full transition-all shrink-0',
                                                isActive
                                                    ? 'bg-gradient-to-r from-[#005F73] via-[#00A8B5] to-[#00C9C8] text-white shadow-md shadow-cyan-600/25'
                                                    : 'bg-slate-100 dark:bg-slate-800 text-slate-400 group-hover:bg-gradient-to-r group-hover:from-[#005F73] group-hover:to-[#00C9C8] group-hover:text-white'
                                            )}
                                        >
                                            <ArrowRight className="size-4" />
                                        </div>
                                    )}
                                </div>
                            </button>
                        );
                    })}
                </div>

                <div className="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center">
                    <p className="text-xs text-slate-400 dark:text-slate-500">
                        {businesses.length} bisnis terhubung dengan akun Anda
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
    description: 'Akun Anda terhubung dengan beberapa bisnis. Silakan pilih bisnis yang ingin Anda kelola.',
};
