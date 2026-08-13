import { KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    Dialog,
    DialogAction,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    onDelete: (id: number, onError: () => void) => void;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);
        onDelete(passkey.id, () => setIsDeleting(false));
    };

    return (
        <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 p-4 last:border-b-0 hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors rounded-xl">
            <div className="flex items-center gap-3.5 min-w-0">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#EAFBFC] dark:bg-cyan-950/60 text-[#00AFC0] dark:text-cyan-400 border border-[#00AFC0]/20">
                    <KeyRound className="h-5 w-5 text-[#00AFC0]" />
                </div>
                <div className="space-y-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <p className="text-sm font-bold text-slate-900 dark:text-slate-100 tracking-tight truncate">
                            {passkey.name}
                        </p>
                        {passkey.authenticator && (
                            <span className="inline-flex items-center gap-1 rounded-md bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-[10px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider border border-slate-200 dark:border-slate-700">
                                {passkey.authenticator}
                            </span>
                        )}
                    </div>
                    <p className="text-xs text-slate-500 dark:text-slate-400 truncate">
                        Ditambahkan {passkey.created_at_diff}
                        {passkey.last_used_at_diff && (
                            <>
                                <span className="mx-1.5 text-slate-300 dark:text-slate-600">
                                    /
                                </span>
                                Terakhir digunakan {passkey.last_used_at_diff}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <Dialog>
                <DialogTrigger asChild>
                    <button
                        type="button"
                        className="p-2 text-[#FF4D4F] hover:bg-red-50 dark:hover:bg-red-950/40 rounded-full transition-all cursor-pointer shrink-0 border border-transparent hover:border-[#FF4D4F]/30"
                        title="Hapus Passkey"
                    >
                        <Trash2 className="size-4 text-[#FF4D4F]" />
                    </button>
                </DialogTrigger>
                <DialogContent size="compact" className="rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
                    <DialogHeader className="flex flex-row items-center gap-3 space-y-0 text-left border-b-0 p-0">
                        <div className="p-2.5 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200/60 dark:border-red-900/60 text-[#FF4D4F] shrink-0">
                            <Trash2 className="size-5" />
                        </div>
                        <DialogTitle className="text-base font-bold text-slate-900 dark:text-slate-100">
                            Hapus Passkey?
                        </DialogTitle>
                    </DialogHeader>
                    <DialogDescription className="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                        Apakah Anda yakin ingin menghapus passkey "{passkey.name}"? Anda tidak akan dapat lagi menggunakannya untuk masuk.
                    </DialogDescription>
                    <DialogFooter className="gap-2 pt-3 border-t border-slate-100 dark:border-slate-800 bg-transparent px-0 pb-0">
                        <DialogCancel className="border border-[#00AFC0] text-[#00AFC0] hover:bg-[#EAFBFC] rounded-full px-4 py-2 text-xs font-bold bg-transparent transition-all cursor-pointer">
                            Batal
                        </DialogCancel>
                        <DialogAction
                            onClick={handleDelete}
                            disabled={isDeleting}
                            className="bg-[#FF4D4F] hover:bg-[#DC2626] disabled:bg-[#FFECEC] disabled:text-[#FFA39E] text-white rounded-full px-5 py-2 text-xs font-bold cursor-pointer border-none transition-all shadow-xs"
                        >
                            {isDeleting ? 'Menghapus...' : 'Hapus Passkey'}
                        </DialogAction>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
