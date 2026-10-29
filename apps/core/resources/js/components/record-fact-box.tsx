import { FactBox } from '@apperp/ui/fact-box';
import { Separator } from '@apperp/ui/separator';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { RecordAttachments } from '@/components/record-attachments';
import { RecordNotes } from '@/components/record-notes';

/** Menghubungkan FactBox SDK dengan lampiran Core. Isi Detail tetap milik halaman pemanggil. */
export function RecordFactBox({
    children,
    details,
    recordType,
    recordId,
    className,
    fillHeight = false,
    toolbarContainer,
}: {
    children: ReactNode;
    details: ReactNode;
    recordType: string;
    recordId?: string;
    className?: string;
    fillHeight?: boolean;
    toolbarContainer?: HTMLElement | null;
}) {
    const [documents, setDocuments] = useState<{
        id: string;
        count: number;
    } | null>(null);
    const [notes, setNotes] = useState<{ id: string; count: number } | null>(
        null,
    );
    const count =
        documents && notes && documents.id === recordId && notes.id === recordId
            ? documents.count + notes.count
            : null;

    if (!recordId && !fillHeight) {
        return <div className={className}>{children}</div>;
    }

    return (
        <FactBox
            className={className}
            fillHeight={fillHeight}
            toolbarContainer={toolbarContainer}
            details={details}
            attachmentCount={count}
            attachments={
                recordId ? (
                    <div key={recordId} className="space-y-4">
                        <RecordAttachments
                            recordType={recordType}
                            recordId={recordId}
                            onCountChange={(value) =>
                                setDocuments((current) =>
                                    current?.id === recordId &&
                                    current.count === value
                                        ? current
                                        : { id: recordId, count: value },
                                )
                            }
                        />
                        <Separator />
                        <RecordNotes
                            recordType={recordType}
                            recordId={recordId}
                            onCountChange={(value) =>
                                setNotes((current) =>
                                    current?.id === recordId &&
                                    current.count === value
                                        ? current
                                        : { id: recordId, count: value },
                                )
                            }
                        />
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Pilih aset untuk melihat lampiran dan catatannya.
                    </p>
                )
            }
        >
            {children}
        </FactBox>
    );
}
