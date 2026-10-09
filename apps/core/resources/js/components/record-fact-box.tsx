import { FactBox } from '@apperp/ui/fact-box';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { RecordAttachments } from '@/components/record-attachments';

/** Menghubungkan FactBox SDK dengan lampiran Core. Isi Detail tetap milik halaman pemanggil. */
export function RecordFactBox({
    children,
    details,
    recordType,
    recordId,
    className,
}: {
    children: ReactNode;
    details: ReactNode;
    recordType: string;
    recordId?: string;
    className?: string;
}) {
    const [count, setCount] = useState<number | null>(null);

    if (!recordId) {
        return <div className={className}>{children}</div>;
    }

    return (
        <FactBox
            className={className}
            details={details}
            attachmentCount={count}
            attachments={
                <RecordAttachments
                    recordType={recordType}
                    recordId={recordId}
                    onCountChange={setCount}
                />
            }
        >
            {children}
        </FactBox>
    );
}
