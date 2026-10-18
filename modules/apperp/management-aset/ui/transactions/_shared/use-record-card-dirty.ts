import { useEffect, useRef } from 'react';

/** Baseline setelah pemuatan selesai; perubahan belum disimpan menahan navigasi kartu. */
export function useRecordCardDirty({
    value,
    ready,
    scopeKey,
    onDirtyChange,
}: {
    value: unknown;
    ready: boolean;
    scopeKey: string;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    const baseline = useRef<{ scopeKey: string; signature: string } | null>(
        null,
    );
    const signature = JSON.stringify(value) ?? '';
    useEffect(() => {
        if (!ready) {
            baseline.current = null;
            onDirtyChange?.(false);

            return;
        }

        if (baseline.current?.scopeKey !== scopeKey) {
            baseline.current = { scopeKey, signature };
        }

        onDirtyChange?.(baseline.current.signature !== signature);
    }, [ready, scopeKey, signature, onDirtyChange]);
    useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);
}
