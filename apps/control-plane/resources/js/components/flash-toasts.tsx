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
            (Array.isArray(validationError) ? validationError[0] : validationError) ??
            flash?.status ??
            '';

        if (!message || message === lastMessage.current) {
            return;
        }

        lastMessage.current = message;

        if (flash?.error || validationError) {
            toast.error(message);
        } else {
            toast.success(message);
        }
    }, [errors, flash]);

    return null;
}
