import { FormErrorSummary, createSaveFeedback } from '@apperp/ui/form-feedback';
import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import { toast } from 'sonner';

export default function FlashToasts() {
    const { flash, errors } = usePage<{
        flash?: { status?: string | null; error?: string | null };
        errors?: Record<string, string | string[]>;
    }>().props;
    const lastSuccess = useRef('');
    const feedback = useMemo(() => {
        if (!flash?.error && !Object.keys(errors ?? {}).length) {
            return null;
        }

        return createSaveFeedback({
            operation: 'Tindakan belum berhasil',
            message:
                flash?.error || 'Periksa isian yang ditandai pada halaman ini.',
            errors,
            code: errors?.version ? 'stale_version' : null,
        });
    }, [errors, flash]);

    useEffect(() => {
        const message = flash?.status ?? '';

        if (!message) {
            lastSuccess.current = '';

            return;
        }

        if (message !== lastSuccess.current) {
            toast.success(message);
            lastSuccess.current = message;
        }
    }, [flash]);

    return feedback ? (
        <div className="shrink-0 p-4">
            <FormErrorSummary feedback={feedback} />
        </div>
    ) : null;
}
