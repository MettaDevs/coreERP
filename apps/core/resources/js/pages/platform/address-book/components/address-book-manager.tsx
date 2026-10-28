import { ActionButton } from '@apperp/ui/action-button';
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

import { DataTable, DataTableText } from '@apperp/ui/data-table';
import { FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import {
    PageDialog as Dialog,
    PageDialogContent as DialogContent,
} from '@apperp/ui/page-dialog';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { apiJson, apiRequest, CoreApiError, errorText } from '@/lib/core-api';
import type { AddressBook } from '../types';
import { AddressBookHint } from './address-book-hint';
import { useAddressBooks } from './use-address-books';

export function AddressBookManager({ onClose }: { onClose: () => void }) {
    const { list, query, setQuery } = useAddressBooks();
    const [editing, setEditing] = useState<AddressBook | null>(null);
    const [archiving, setArchiving] = useState<AddressBook | null>(null);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const transition = useRecordTransition(
        name !== (editing?.name ?? ''),
        busy,
    );
    async function save(event: React.FormEvent) {
        setFieldErrors({});
        event.preventDefault();
        setBusy(true);
        setError('');

        try {
            await apiJson<{ data: AddressBook }>(
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
            list.reload();
            setEditing(null);
            setName('');
            router.reload({ only: ['selectedAddressBook'] });
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setFieldErrors(caught.errors);
            }

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
            list.reload();
            setArchiving(null);

            if (editing?.id === archiving.id) {
                setEditing(null);
                setName('');
            }

            router.reload({ only: ['selectedAddressBook'] });
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
                    transition.request(onClose);
                }
            }}
        >
            <DialogContent
                title={
                    <>
                        <span className="inline-flex items-center gap-2">
                            Buku alamat
                            <AddressBookHint label="Buku alamat">
                                Kelompok pihak untuk memudahkan pencarian.
                                Mengarsipkan kelompok tidak mengarsipkan pihak
                                di dalamnya.
                            </AddressBookHint>
                        </span>
                    </>
                }
                description={
                    'Kelompok pihak untuk memudahkan pencarian. Mengarsipkan\n                        kelompok tidak mengarsipkan pihak di dalamnya.'
                }
            >
                <FormErrorHeader
                    message={archiving ? null : error || list.error}
                    errors={archiving ? undefined : fieldErrors}
                />

                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <form
                    noValidate
                    onSubmit={(event) => void save(event)}
                    className="flex flex-wrap items-end gap-3"
                >
                    <Input
                        data-error-field="name"
                        aria-invalid={!!fieldErrors.name}
                        label="Nama buku alamat"
                        required
                        value={name}
                        disabled={busy}
                        onChange={(event) => setName(event.target.value)}
                    />
                    <FieldError>{fieldErrors.name?.join(' ')}</FieldError>
                    <ActionButton action="save" type="submit" disabled={busy} />
                    {editing && (
                        <ActionButton
                            action="cancel"
                            disabled={busy}
                            onClick={() => {
                                transition.request(() => {
                                    setEditing(null);
                                    setName('');
                                });
                            }}
                            type="button"
                        />
                    )}
                </form>
                <Input
                    label="Cari buku alamat"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
                <div className="min-h-0 flex-1 overflow-hidden">
                    <DataTable
                        scrollMode="viewport"
                        data={list.items}
                        incremental={list}
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
                            {
                                id: 'archive',
                                label: 'Delete',
                                icon: <Trash2 aria-hidden="true" />,
                            },
                        ]}
                        actionsPlacement="inline"
                        onRowAction={(action, book) => {
                            if (busy) {
                                return;
                            }

                            if (action === 'edit') {
                                transition.request(() => {
                                    setEditing(book);
                                    setName(book.name);
                                });
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
                                Delete buku alamat {archiving?.name}?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Pihak di dalamnya tetap tersimpan. Pengelompokan
                                ini akan dilepas dari pihak.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <FormErrorHeader message={error} />

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
                                Delete
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
                {transition.confirmation}
            </DialogContent>
        </Dialog>
    );
}
