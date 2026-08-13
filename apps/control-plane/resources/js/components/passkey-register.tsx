import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';

type Props = {
    onSuccess: () => void;
};

export default function PasskeyRegistration({ onSuccess }: Props) {
    const [name, setName] = useState(() => {
        const ua = navigator.userAgent;

        const browser = [
            { pattern: /Edg|Edge/, name: 'Edge' },
            { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
            { pattern: /Firefox|FxiOS/, name: 'Firefox' },
            { pattern: /Chrome|CriOS/, name: 'Chrome' },
            { pattern: /Safari/, name: 'Safari' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        const os = [
            { pattern: /iPhone/, name: 'iPhone' },
            { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
            { pattern: /Android/, name: 'Android' },
            { pattern: /Mac/, name: 'Mac' },
            { pattern: /Windows/, name: 'Windows' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        return [browser, os].filter(Boolean).join(' on ') || '';
    });

    const [showForm, setShowForm] = useState(false);
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!name.trim()) {
            return;
        }

        await register(name);
    };

    const handleCancel = () => {
        setShowForm(false);
        setName('');
    };

    if (!isSupported) {
        return (
            <div className="text-xs text-slate-500 dark:text-slate-400">
                Fitur Passkey tidak didukung di browser ini.
            </div>
        );
    }

    if (!showForm) {
        return (
            <Button
                onClick={() => setShowForm(true)}
                className="bg-primary hover:bg-primary/90 text-primary-foreground font-semibold text-xs rounded-md px-4 py-2 cursor-pointer"
            >
                Tambah Passkey
            </Button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="space-y-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 p-4"
        >
            <div className="space-y-1">
                <Input
                    id="passkey-name"
                    label="Nama Passkey"
                    type="text"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className="w-full rounded-xl focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20"
                    placeholder="Contoh: Chrome on Windows"
                    autoFocus
                />
                <p className="text-[11px] text-slate-500 dark:text-slate-400">
                    Nama ini memudahkan Anda mengidentifikasi perangkat passkey ini nanti.
                </p>
            </div>

            {error && <InputError message={error} />}

            <div className="flex gap-2 pt-1">
                <Button
                    type="submit"
                    disabled={isLoading || !name.trim()}
                    className="btn-gradient-primary text-white font-bold text-xs rounded-full px-5 py-2 cursor-pointer"
                >
                    {isLoading ? 'Mendaftarkan...' : 'Daftarkan Passkey'}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={handleCancel}
                    className="rounded-full text-xs text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                >
                    Batal
                </Button>
            </div>
        </form>
    );
}
