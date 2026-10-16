import { Button } from '@apperp/ui/button';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogDescription,
    PageDialogHeader,
    PageDialogTitle,
} from '@apperp/ui/page-dialog';
import { useEffect, useState } from 'react';
import { apiJson, errorText } from '@/lib/core-api';
import { PartyForm } from '../../platform/address-book/components/party-form';
import type {
    DirectoryMetadata,
    Party,
} from '../../platform/address-book/types';

/** Memakai form dan penyimpanan Party yang sama; draft vendor tetap berada di halaman induk. */
export function CreateVendorPartyDialog({
    onClose,
    onCreated,
}: {
    onClose: () => void;
    onCreated: (party: Party) => void;
}) {
    const [metadata, setMetadata] = useState<DirectoryMetadata | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        apiJson<{ data: DirectoryMetadata }>('/api/v1/parties/metadata', {
            signal: controller.signal,
        })
            .then(({ data }) => {
                if (!controller.signal.aborted) {
                    setMetadata(data);
                    setError('');
                }
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setError(
                        errorText(caught, 'Form pihak belum dapat dimuat.'),
                    );
                }
            });
        return () => controller.abort();
    }, [attempt]);

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) {
                    onClose();
                }
            }}
        >
            <PageDialogContent>
                <PageDialogHeader>
                    <PageDialogTitle>Party baru</PageDialogTitle>
                    <PageDialogDescription className="sr-only">
                        Buat identitas vendor di buku alamat. Setelah disimpan,
                        identitas ini dipilih pada vendor yang sedang dibuat.
                    </PageDialogDescription>
                </PageDialogHeader>
                {metadata ? (
                    <PartyForm
                        embedded
                        metadata={metadata}
                        party={null}
                        onBusyChange={setBusy}
                        onClose={onClose}
                        onSaved={onCreated}
                    />
                ) : (
                    <PageDialogBody>
                        {error ? (
                            <div role="alert" className="space-y-3">
                                <p className="text-sm text-destructive">
                                    {error}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        setAttempt((value) => value + 1)
                                    }
                                >
                                    Muat ulang
                                </Button>
                            </div>
                        ) : (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Memuat form pihak…
                            </p>
                        )}
                    </PageDialogBody>
                )}
            </PageDialogContent>
        </PageDialog>
    );
}
