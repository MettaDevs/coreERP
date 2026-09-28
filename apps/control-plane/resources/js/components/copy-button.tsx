import { Button } from '@apperp/ui/button';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

/**
 * Tombol salin, dengan dua bentuk: berlabel untuk perintah dan kata sandi yang harus disalin utuh, dan
 * ikon saja untuk alamat di dalam baris tabel.
 *
 * Clipboard API tidak ada pada origin yang bukan HTTPS maupun localhost. Kegagalannya disebut di tombolnya
 * sendiri, supaya operator tahu ia harus menyorot teksnya dengan tangan.
 */
export default function CopyButton({
    text,
    label,
    variant = 'outline',
    iconOnly = false,
}: {
    text: string;
    label: string;
    variant?: 'outline' | 'default' | 'ghost';
    iconOnly?: boolean;
}) {
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

    async function copy() {
        try {
            await navigator.clipboard.writeText(text);
            setState('copied');
            window.setTimeout(() => setState('idle'), 2500);
        } catch {
            setState('failed');
        }
    }

    const spoken =
        state === 'copied'
            ? 'Tersalin'
            : state === 'failed'
              ? 'Salin dengan tangan'
              : label;

    if (iconOnly) {
        return (
            <Button
                type="button"
                size="icon-xs"
                variant="ghost"
                onClick={() => void copy()}
                aria-label={spoken}
                title={spoken}
                className="text-muted-foreground hover:text-foreground"
            >
                {state === 'copied' ? (
                    <Check className="text-emerald-600" />
                ) : (
                    <Copy />
                )}
            </Button>
        );
    }

    return (
        <Button
            type="button"
            size="sm"
            variant={variant}
            onClick={() => void copy()}
        >
            {state === 'copied' ? <Check /> : <Copy />}
            {spoken}
        </Button>
    );
}
