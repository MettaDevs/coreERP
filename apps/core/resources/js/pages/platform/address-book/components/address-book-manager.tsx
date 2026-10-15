import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Button } from '@apperp/ui/button';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import {
    PageDialog as Dialog,
    PageDialogContent as DialogContent,
    PageDialogDescription as DialogDescription,
    PageDialogHeader as DialogHeader,
    PageDialogTitle as DialogTitle,
} from '@apperp/ui/page-dialog';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { apiJson, apiRequest, errorText } from '@/lib/core-api';
import type { AddressBook, DirectoryMetadata } from '../types';
import { AddressBookHint } from './address-book-hint';

export function AddressBookManager({
    metadata,
    onClose,
}: {
    metadata: DirectoryMetadata;
    onClose: () => void;
}) {
    const [items, setItems] = useState(metadata.addressBooks);
    const [editing, setEditing] = useState<AddressBook | null>(null);
    const [archiving, setArchiving] = useState<AddressBook | null>(null);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    async function save(event: React.FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError('');

        try {
            const result = await apiJson<{ data: AddressBook }>(
                editing
                    ? `/api/v1/address-books/${editing.id}`
                    : '/api/v1/address-books',
                {
                    method: editing ? 'PATCH' : 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name,
                        ...(editing ? { version: editing.version } : {}),
                    }),
                },
            );
            setItems((current) => [
                ...current.filter((item) => item.id !== result.data.id),
                result.data,
            ]);
            setEditing(null);
            setName('');
            router.reload({ only: ['addressBooks'] });
        } catch (caught) {
            setError(errorText(caught, 'Buku alamat belum dapat disimpan.'));
        } finally {
            setBusy(false);
        }
    }
    async function archive() {
        if (!archiving) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            await apiRequest(`/api/v1/address-books/${archiving.id}`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ version: archiving.version }),
            });
            setItems((current) =>
                current.filter((item) => item.id !== archiving.id),
            );
            setArchiving(null);

            if (editing?.id === archiving.id) {
                setEditing(null);
                setName('');
            }

            router.reload({ only: ['addressBooks'] });
        } catch (caught) {
            setError(errorText(caught, 'Buku alamat belum dapat diarsipkan.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        <span className="inline-flex items-center gap-2">
                            Buku alamat
                            <AddressBookHint label="Buku alamat">
                                Kelompok pihak untuk memudahkan pencarian.
                                Mengarsipkan kelompok tidak mengarsipkan pihak
                                di dalamnya.
                            </AddressBookHint>
                        </span>
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Kelompok pihak untuk memudahkan pencarian. Mengarsipkan
                        kelompok tidak mengarsipkan pihak di dalamnya.
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <form
                    onSubmit={(event) => void save(event)}
                    className="flex flex-wrap items-end gap-3"
                >
                    <Input
                        label="Nama buku alamat"
                        required
                        value={name}
                        disabled={busy}
                        onChange={(event) => setName(event.target.value)}
                    />
                    <Button type="submit" disabled={busy}>
                        {editing ? 'Simpan perubahan' : 'Tambah'}
                    </Button>
                    {editing && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                setEditing(null);
                                setName('');
                            }}
                        >
                            Batal ubah
                        </Button>
                    )}
                </form>
                <div className="min-h-0 flex-1 overflow-hidden">
                    <DataTable
                        scrollMode="viewport"
                        data={items}
                        getRowKey={(book) => book.id}
                        columns={[
                            {
                                id: 'name',
                                header: 'Nama',
                                cell: (book) => (
                                    <DataTableText>{book.name}</DataTableText>
                                ),
                            },
                        ]}
                        actions={[
                            { id: 'edit', label: 'Ubah' },
                            { id: 'archive', label: 'Arsipkan' },
                        ]}
                        actionsPlacement="inline"
                        onRowAction={(action, book) => {
                            if (busy) {
                                return;
                            }

                            if (action === 'edit') {
                                setEditing(book);
                                setName(book.name);
                            } else {
                                setArchiving(book);
                            }
                        }}
                    />
                </div>
                <AlertDialog
                    open={archiving !== null}
                    onOpenChange={(open) => {
                        if (!open && !busy) {
                            setArchiving(null);
                        }
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Arsipkan buku alamat {archiving?.name}?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Pihak di dalamnya tetap tersimpan. Pengelompokan
                                ini akan dilepas dari pihak.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={busy}>
                                Batal
                            </AlertDialogCancel>
                            <AlertDialogAction
                                disabled={busy}
                                onClick={(event) => {
                                    event.preventDefault();
                                    void archive();
                                }}
                            >
                                Arsipkan
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DialogContent>
        </Dialog>
    );
}
