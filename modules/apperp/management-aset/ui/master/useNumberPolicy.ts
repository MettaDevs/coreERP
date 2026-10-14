import { useEffect, useState } from 'react';
import { api, errorMessage } from '../api';

type NumberPolicy = { allow_manual: boolean; allow_automatic: boolean };

export function useNumberPolicy(resource: string, enabled: boolean) {
    const key = enabled ? resource : null;
    const [result, setResult] = useState<{
        key: string;
        data: NumberPolicy | null;
        error: string;
    } | null>(null);
    const current = result?.key === key ? result : null;
    useEffect(() => {
        if (key === null) {
            return;
        }

        let cancelled = false;
        api<{ data: NumberPolicy }>(`/${key}/number-policy`)
            .then(({ data }) => {
                if (!cancelled) {
                    setResult({ key, data, error: '' });
                }
            })
            .catch((error) => {
                if (!cancelled) {
                    setResult({
                        key,
                        data: null,
                        error: errorMessage(
                            error,
                            'Pengaturan kode belum dapat dimuat.',
                        ),
                    });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [key]);

    return {
        allowManual: current?.data?.allow_manual ?? false,
        allowAutomatic: current?.data?.allow_automatic ?? true,
        loading: key !== null && current === null,
        error: current?.error ?? '',
    };
}
