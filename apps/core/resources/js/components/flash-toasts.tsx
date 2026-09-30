import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

export default function FlashToasts() {
    const { flash, errors } = usePage<{
        flash?: { status?: string | null; error?: string | null };
        errors?: Record<string, string | string[]>;
    }>().props;
    const lastMessage = useRef('');

    useEffect(() => {
        const validationError = Object.values(errors ?? {})[0];
        const message =
            flash?.error ??
            (Array.isArray(validationError)
                ? validationError[0]
                : validationError) ??
            flash?.status ??
            '';

        if (!message || message === lastMessage.current) {
            return;
        }

        lastMessage.current = message;

        // Galat pada `version` berarti data sudah diubah sejak halaman dibuka. Muat ulang
        // penuh, bukan `router.reload()`: isian form harus kembali ke data terbaru supaya
        // pengguna melihat perubahan orang lain sebelum mengulang perubahannya sendiri.
        if (errors?.version) {
            toast.error(message, {
                duration: Infinity,
                action: {
                    label: 'Muat ulang',
                    onClick: () => window.location.reload(),
                },
            });
        } else if (flash?.error || validationError) {
            toast.error(message);
        } else {
            toast.success(message);
        }
    }, [errors, flash]);

    return null;
}
