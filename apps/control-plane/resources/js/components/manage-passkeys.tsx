import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import Heading from '@/components/heading';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

const EmptyState = () => {
    return (
        <div className="p-6 text-center">
            <KeyRound className="h-6 w-6 text-slate-400 mx-auto mb-3" />
            <p className="text-xs font-bold text-slate-900 dark:text-slate-100">Belum ada passkey</p>
            <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                Tambahkan passkey untuk masuk tanpa menggunakan kata sandi.
            </p>
        </div>
    );
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];

    const handleDelete = (id: number, onError: () => void) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError,
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <div className="flex flex-col justify-between h-full space-y-4">
            <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 flex-1 flex flex-col justify-center">
                {passkeys.length > 0 ? (
                    passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))
                ) : (
                    <EmptyState />
                )}
            </div>

            <div className="pt-2">
                <PasskeyRegistration onSuccess={handleRegisterSuccess} />
            </div>
        </div>
    );
}
