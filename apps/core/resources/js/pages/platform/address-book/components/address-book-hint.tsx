import { FieldHint } from '@apperp/ui/field';
import { CircleAlert } from 'lucide-react';
import type { ReactNode } from 'react';

/** Trigger bantuan buku alamat yang konsisten; perilaku tooltip tetap milik SDK. */
export function AddressBookHint({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <FieldHint
            hint={children}
            side="right"
            className="inline-flex shrink-0"
        >
            <button
                type="button"
                aria-label={`Lihat penjelasan ${label.toLowerCase()}`}
                className="text-muted-foreground hover:text-foreground"
            >
                <CircleAlert className="size-4" />
            </button>
        </FieldHint>
    );
}
