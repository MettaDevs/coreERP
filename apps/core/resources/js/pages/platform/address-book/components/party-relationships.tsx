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
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    PageDialogToolbar,
    PageDialog as Dialog,
    PageDialogContent as DialogContent,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { useToday } from '@/hooks/use-work-date';
import { apiJson, apiRequest, CoreApiError, errorText } from '@/lib/core-api';
import type {
    DirectoryMetadata,
    PartyDetail,
    PartyRelationship,
} from '../types';

export function PartyRelationships({
    party,
    metadata,
    onChanged,
}: {
    party: PartyDetail;
    metadata: DirectoryMetadata;
    onChanged: () => void;
}) {
    const today = useToday();
    const [editing, setEditing] = useState<PartyRelationship | 'new' | null>(
        null,
    );
    const [archiving, setArchiving] = useState<PartyRelationship | null>(null);
    const [form, setForm] = useState({
        other_id: '',
        relationship_type_code: '',
        direction: 'from',
        valid_from: today,
        valid_to: '',
    });
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<
        { id: string; name: string; type: string }[]
    >([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const contentRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!editing) {
            return;
        }

        const controller = new AbortController();
        apiJson<{ data: typeof options }>(
            `/api/v1/parties/options?q=${encodeURIComponent(query)}`,
            { signal: controller.signal },
        )
            .then((result) => {
                if (controller.signal.aborted) {
                    return;
                }

                setOptions(result.data.filter((item) => item.id !== party.id));
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setError(
                        errorText(caught, 'Daftar pihak belum dapat dimuat.'),
                    );
                }
            });

        return () => controller.abort();
    }, [editing, query, party.id]);
    const open = (row: PartyRelationship | 'new') => {
        setFieldErrors({});
        setError('');
        setQuery('');
        setForm(
            row === 'new'
                ? {
                      other_id: '',
                      relationship_type_code: '',
                      direction: 'from',
                      valid_from: today,
                      valid_to: '',
                  }
                : {
                      other_id:
                          row.from_party_id === party.id
                              ? row.to_party_id
                              : row.from_party_id,
                      relationship_type_code: row.relationship_type_code,
                      direction: row.from_party_id === party.id ? 'from' : 'to',
                      valid_from: row.valid_from,
                      valid_to: row.valid_to ?? '',
                  },
        );
        setEditing(row);
    };
    async function save(event: React.FormEvent) {
        setFieldErrors({});
        event.preventDefault();

        if (!editing) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            await apiJson(
                `/api/v1/parties/${party.id}/relationships${editing === 'new' ? '' : `/${editing.id}`}`,
                {
                    method: editing === 'new' ? 'POST' : 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        from_party_id:
                            form.direction === 'from'
                                ? party.id
                                : form.other_id,
                        to_party_id:
                            form.direction === 'from'
                                ? form.other_id
                                : party.id,
                        relationship_type_code: form.relationship_type_code,
                        valid_from: form.valid_from,
                        valid_to: form.valid_to || null,
                        ...(editing === 'new'
                            ? {}
                            : { version: editing.version }),
                    }),
                },
            );
            setEditing(null);
            onChanged();
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setFieldErrors(caught.errors);
            }

            setError(errorText(caught, 'Hubungan belum dapat disimpan.'));
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
            await apiRequest(
                `/api/v1/parties/${party.id}/relationships/${archiving.id}`,
                {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ version: archiving.version }),
                },
            );
            setArchiving(null);
            onChanged();
        } catch (caught) {
            setError(errorText(caught, 'Hubungan belum dapat diarsipkan.'));
        } finally {
            setBusy(false);
        }
    }
    const currentOther =
        editing && editing !== 'new'
            ? {
                  value: form.other_id,
                  label:
                      editing.from_party_id === party.id
                          ? editing.to_name
                          : editing.from_name,
              }
            : null;
    const choices = options.map((item) => ({
        value: item.id,
        label: item.name,
    }));

    if (
        currentOther &&
        !choices.some((choice) => choice.value === currentOther.value)
    ) {
        choices.unshift(currentOther);
    }

    return (
        <div className="space-y-3">
            <RecordActionBar>
                {metadata.canManage && (
                    <>
                        <ActionButton
                            action="create"
                            onClick={() => open('new')}
                        />
                    </>
                )}
            </RecordActionBar>
            <div className="space-y-3">
                {error && !editing && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <DataTable
                    scrollMode="inline"
                    data={party.relationships}
                    getRowKey={(row) => row.id}
                    actions={
                        metadata.canManage
                            ? [
                                  { id: 'edit', label: 'Ubah' },
                                  {
                                      id: 'archive',
                                      label: 'Delete',
                                      icon: <Trash2 aria-hidden="true" />,
                                  },
                              ]
                            : []
                    }
                    actionsPlacement="inline"
                    onRowAction={(action, row) =>
                        action === 'edit' ? open(row) : setArchiving(row)
                    }
                    columns={[
                        {
                            id: 'other',
                            header: 'Pihak terkait',
                            cell: (row) => (
                                <DataTableText>
                                    {row.from_party_id === party.id
                                        ? row.to_name
                                        : row.from_name}
                                </DataTableText>
                            ),
                        },
                        {
                            id: 'type',
                            header: 'Hubungan',
                            cell: (row) => {
                                const type = metadata.relationshipTypes.find(
                                    (type) =>
                                        type.code ===
                                        row.relationship_type_code,
                                );

                                return (
                                    <DataTableText>
                                        {row.from_party_id === party.id
                                            ? type?.name
                                            : type?.inverse_name}
                                    </DataTableText>
                                );
                            },
                        },
                        {
                            id: 'from',
                            header: 'Berlaku sejak',
                            cell: (row) => (
                                <DataTableText>{row.valid_from}</DataTableText>
                            ),
                        },
                        {
                            id: 'to',
                            header: 'Berlaku sampai',
                            cell: (row) => (
                                <DataTableText>
                                    {row.valid_to ?? 'Tidak dibatasi'}
                                </DataTableText>
                            ),
                        },
                        {
                            id: 'status',
                            header: 'Status',
                            cell: (row) => (
                                <DataTableText>
                                    {row.valid_from > today
                                        ? 'Belum berlaku'
                                        : row.valid_to && row.valid_to < today
                                          ? 'Berakhir'
                                          : 'Berlaku'}
                                </DataTableText>
                            ),
                        },
                    ]}
                    emptyMessage="Belum ada hubungan antar pihak."
                />
                <Dialog
                    open={editing !== null}
                    onOpenChange={(open) => {
                        if (!open && !busy) {
                            setEditing(null);
                        }
                    }}
                >
                    <DialogContent
                        ref={contentRef}
                        title="Hubungan pihak"
                        recordTitle={
                            editing && editing !== 'new'
                                ? [editing.from_name, editing.to_name].join(
                                      ' · ',
                                  )
                                : [
                                      party.name,
                                      options.find(
                                          (item) => item.id === form.other_id,
                                      )?.name,
                                  ]
                                      .filter(Boolean)
                                      .join(' · ') || 'Hubungan baru'
                        }
                        editing
                        busy={busy}
                        description={
                            'Hubungan mencatat keterkaitan pihak. Akun vendor\n                                dan peran bisnis dikelola pada halaman\n                                masing-masing.'
                        }
                    >
                        <PageDialogToolbar>
                            <ActionButton
                                action="save"
                                type="submit"
                                form="party-relationship"
                                disabled={busy}
                                pending={busy}
                            />
                            <ActionButton
                                action="cancel"
                                disabled={busy}
                                onClick={() => setEditing(null)}
                            />
                        </PageDialogToolbar>
                        <FormErrorHeader message={error} errors={fieldErrors} />

                        <form
                            noValidate
                            id="party-relationship"
                            onSubmit={(event) => void save(event)}
                            data-page-dialog-body=""
                            className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4"
                        >
                            {error && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                            <Input
                                label="Cari pihak terkait"
                                value={query}
                                disabled={busy}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                            <div
                                data-error-field={
                                    form.direction === 'from'
                                        ? 'to_party_id'
                                        : 'from_party_id'
                                }
                            >
                                <Select
                                    data-error-field={
                                        form.direction === 'from'
                                            ? 'to_party_id'
                                            : 'from_party_id'
                                    }
                                    label="Pihak terkait"
                                    required
                                    portalContainer={contentRef}
                                    items={choices}
                                    value={form.other_id || null}
                                    onValueChange={(value) =>
                                        setForm((current) => ({
                                            ...current,
                                            other_id: value ?? '',
                                        }))
                                    }
                                />
                                <FieldError>
                                    {(form.direction === 'from'
                                        ? fieldErrors.to_party_id
                                        : fieldErrors.from_party_id
                                    )?.join(' ')}
                                </FieldError>
                            </div>
                            <NativeSelect
                                data-error-field="relationship_type_code"
                                label="Jenis hubungan"
                                required
                                value={form.relationship_type_code}
                                onChange={(event) =>
                                    setForm((current) => ({
                                        ...current,
                                        relationship_type_code:
                                            event.target.value,
                                    }))
                                }
                            >
                                <NativeSelectOption value="">
                                    Pilih hubungan
                                </NativeSelectOption>
                                {metadata.relationshipTypes.map((type) => (
                                    <NativeSelectOption
                                        key={type.code}
                                        value={type.code}
                                    >
                                        {type.name}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <FieldError>
                                {fieldErrors.relationship_type_code?.join(' ')}
                            </FieldError>
                            <NativeSelect
                                label="Arah hubungan"
                                value={form.direction}
                                onChange={(event) =>
                                    setForm((current) => ({
                                        ...current,
                                        direction: event.target.value,
                                    }))
                                }
                            >
                                <NativeSelectOption value="from">
                                    Pihak ini terhadap pihak terkait
                                </NativeSelectOption>
                                <NativeSelectOption value="to">
                                    Pihak terkait terhadap pihak ini
                                </NativeSelectOption>
                            </NativeSelect>
                            <Input
                                label="Berlaku sejak"
                                data-error-field="valid_from"
                                aria-invalid={!!fieldErrors.valid_from}
                                type="date"
                                required
                                value={form.valid_from}
                                onChange={(event) =>
                                    setForm((current) => ({
                                        ...current,
                                        valid_from: event.target.value,
                                    }))
                                }
                            />
                            <FieldError>
                                {fieldErrors.valid_from?.join(' ')}
                            </FieldError>
                            <Input
                                label="Berlaku sampai"
                                data-error-field="valid_to"
                                aria-invalid={!!fieldErrors.valid_to}
                                type="date"
                                value={form.valid_to}
                                onChange={(event) =>
                                    setForm((current) => ({
                                        ...current,
                                        valid_to: event.target.value,
                                    }))
                                }
                            />
                            <FieldError>
                                {fieldErrors.valid_to?.join(' ')}
                            </FieldError>
                        </form>
                    </DialogContent>
                </Dialog>
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
                                Delete hubungan?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Hubungan disembunyikan dari daftar. Identitas
                                kedua pihak tetap tersimpan.
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
            </div>
        </div>
    );
}
