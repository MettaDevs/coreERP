import { ActionButton } from '@apperp/ui/action-button';
import { Alert, AlertDescription } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn, DataTableSort } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import {
    PageDialogToolbar,
    PageDialog,
    PageDialogAction,
    PageDialogBody,
    PageDialogContent,
    PageDialogFooter,
} from '@apperp/ui/page-dialog';
import {
    lookupComparator,
    useInlineLookupData,
} from '@apperp/ui/use-inline-lookup-data';
import { useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';

export type LookupOption = {
    code: string;
    description: string;
    category: string;
};
export type LookupActions = {
    fullList: boolean;
    details: boolean;
    create: boolean;
    edit: boolean;
    editList: boolean;
};

export function LookupListDialog({
    items,
    initialSelection,
    actions,
    onChoose,
    onClose,
    onDetails,
    onNew,
    onUpdate,
}: {
    items: LookupOption[];
    initialSelection: LookupOption | null;
    actions: LookupActions;
    onChoose: (item: LookupOption) => void;
    onClose: () => void;
    onDetails: (item: LookupOption) => void;
    onNew: () => void;
    onUpdate: (item: LookupOption) => void;
}) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<DataTableSort | null>(null);
    const [draft, setDraft] = useState<LookupOption[] | null>(null);
    const [error, setError] = useState('');
    const updateDraft = (
        code: string,
        key: 'description' | 'category',
        text: string,
    ) =>
        setDraft(
            (current) =>
                current?.map((item) =>
                    item.code === code ? { ...item, [key]: text } : item,
                ) ?? null,
        );
    const draftField = (
        item: LookupOption,
        key: 'description' | 'category',
    ) => (
        <Input
            aria-label={`${key === 'description' ? 'Deskripsi' : 'Kategori'} ${item.code}`}
            value={
                draft?.find((row) => row.code === item.code)?.[key] ?? item[key]
            }
            onChange={(event) =>
                updateDraft(item.code, key, event.target.value)
            }
        />
    );

    const [candidateCode, setCandidateCode] = useState(initialSelection?.code);
    const candidate = items.find((item) => item.code === candidateCode) ?? null;
    const setCandidate = (item: LookupOption) => setCandidateCode(item.code);
    const columns: DataTableColumn<LookupOption>[] = [
        {
            id: 'code',
            header: 'Kode',
            cell: (item) => (
                <Button
                    variant="link"
                    size="sm"
                    onClick={() => setCandidate(item)}
                >
                    {item.code}
                </Button>
            ),
            sortValue: (item) => item.code,
            width: 160,
        },
        {
            id: 'description',
            header: 'Deskripsi',
            cell: (item) =>
                draft ? draftField(item, 'description') : item.description,
            sortValue: (item) => item.description,
            width: 320,
        },
        {
            id: 'category',
            header: 'Kategori',
            cell: (item) =>
                draft
                    ? draftField(item, 'category')
                    : item.category || 'Belum diisi',
            sortValue: (item) => item.category,
            width: 220,
        },
    ];

    const data = useInlineLookupData({
        items,
        query: search,
        active: true,
        getItemValue: (item) => item.code,
        getSearchText: (item) =>
            `${item.code} ${item.description} ${item.category}`,
        compareItems: lookupComparator(columns, sort),
    });
    const filtered = data.items;
    const candidateVisible =
        candidate && filtered.some((item) => item.code === candidate.code);

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <PageDialogContent
                title={'Pilih kelompok pencatatan produk'}
                description={
                    'Pilih satu baris, lalu tekan Pilih untuk mengisi field.'
                }
            >
                <PageDialogBody
                    className="flex min-h-0 flex-col"
                    onScrollCapture={data.onScrollCapture}
                >
                    <div className="flex min-h-0 flex-1 flex-col gap-4">
                        <div className="flex flex-wrap gap-2">
                            {actions.create && !draft && (
                                <ActionButton action="create" onClick={onNew} />
                            )}
                            {actions.details && !draft && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!candidateVisible || !!draft}
                                    onClick={() => {
                                        if (candidateVisible && candidate) {
                                            onDetails(candidate);
                                        }
                                    }}
                                >
                                    Detail
                                </Button>
                            )}
                            {actions.editList && !draft && (
                                <ActionButton
                                    action="edit"
                                    onClick={() =>
                                        setDraft(
                                            items.map((item) => ({ ...item })),
                                        )
                                    }
                                />
                            )}
                            {draft && (
                                <>
                                    <ActionButton
                                        action="save"
                                        onClick={() => {
                                            if (!actions.edit) {
                                                return;
                                            }

                                            const changed = draft.map(
                                                (item) => ({
                                                    ...item,
                                                    description:
                                                        item.description.trim(),
                                                    category:
                                                        item.category.trim(),
                                                }),
                                            );

                                            if (
                                                changed.some(
                                                    (item) => !item.description,
                                                )
                                            ) {
                                                setError(
                                                    'Isi deskripsi untuk setiap kelompok.',
                                                );

                                                return;
                                            }

                                            changed.forEach(onUpdate);
                                            setDraft(null);
                                            setError('');
                                        }}
                                        type="button"
                                    />
                                    <ActionButton
                                        action="cancel"
                                        onClick={() => {
                                            setDraft(null);
                                            setError('');
                                        }}
                                    />
                                </>
                            )}
                        </div>
                        {error && (
                            <Alert variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        <Input
                            label="Cari daftar"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <DataTable
                            columns={columns}
                            data={filtered}
                            sort={sort}
                            onSortChange={setSort}
                            getRowKey={(item) => item.code}
                            getRowLabel={(item) => item.code}
                            rowActivation="single-click"
                            onRowClick={setCandidate}
                            activeRowKey={
                                candidateVisible ? candidate?.code : undefined
                            }
                            scrollMode="viewport"
                            emptyMessage="Tidak ada pilihan yang cocok."
                        />
                    </div>
                </PageDialogBody>
                <PageDialogFooter>
                    <PageDialogAction
                        disabled={!candidateVisible || !!draft}
                        onClick={() => {
                            if (candidateVisible && candidate) {
                                onChoose(candidate);
                            }
                        }}
                    >
                        Pilih
                    </PageDialogAction>
                    <ActionButton action="cancel" onClick={onClose} />
                </PageDialogFooter>
            </PageDialogContent>
        </PageDialog>
    );
}

export function LookupDetailsDialog({
    item,
    canEdit,
    onSave,
    onClose,
}: {
    item: LookupOption;
    canEdit: boolean;
    onSave: (item: LookupOption) => void;
    onClose: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [description, setDescription] = useState(item.description);
    const [category, setCategory] = useState(item.category);
    const [error, setError] = useState('');

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <PageDialogContent
                title="Kelompok pencatatan produk"
                recordTitle={[item.code, description]
                    .filter(Boolean)
                    .join(' · ')}
                editing={editing}
                onEdit={canEdit ? () => setEditing(true) : undefined}
                description={
                    'Perubahan tersimpan sementara di playground. Kode\n                            tetap menjadi identitas data.'
                }
            >
                <form
                    noValidate
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (!canEdit || !editing) {
                            return;
                        }

                        if (!description.trim()) {
                            setError('Isi deskripsi terlebih dahulu.');

                            return;
                        }

                        onSave({
                            ...item,
                            description: description.trim(),
                            category: category.trim(),
                        });
                        setEditing(false);
                        setError('');
                    }}
                >
                    <PageDialogToolbar>
                        {editing ? (
                            <>
                                <ActionButton action="save" type="submit" />
                                <ActionButton
                                    action="cancel"
                                    type="button"
                                    onClick={() => {
                                        setDescription(item.description);
                                        setCategory(item.category);
                                        setError('');
                                        setEditing(false);
                                    }}
                                />
                            </>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Tutup
                            </Button>
                        )}
                    </PageDialogToolbar>
                    <FormErrorHeader
                        message={error}
                        operation="Perubahan contoh belum disimpan"
                    />
                    <PageDialogBody>
                        <div className="space-y-4">
                            {error && (
                                <Alert variant="destructive">
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            )}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input
                                    label="Kode"
                                    value={item.code}
                                    readOnly
                                />
                                <Input
                                    label="Deskripsi"
                                    required={editing}
                                    value={
                                        editing ? description : item.description
                                    }
                                    readOnly={!editing}
                                    onChange={(event) =>
                                        setDescription(event.target.value)
                                    }
                                />
                                <Input
                                    label="Kategori"
                                    value={editing ? category : item.category}
                                    readOnly={!editing}
                                    onChange={(event) =>
                                        setCategory(event.target.value)
                                    }
                                />
                            </div>
                        </div>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

export function LookupCreateDialog({
    items,
    onSave,
    onClose,
}: {
    items: LookupOption[];
    onSave: (item: LookupOption) => void;
    onClose: () => void;
}) {
    const [code, setCode] = useState('');
    const [description, setDescription] = useState('');
    const [category, setCategory] = useState('');
    const [error, setError] = useState('');

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <PageDialogContent
                title="Kelompok pencatatan produk"
                recordTitle={description.trim() || 'Kelompok baru'}
                editing
                description={
                    'Data contoh tersimpan sementara di playground dan\n                            langsung dipilih setelah disimpan.'
                }
            >
                <form
                    noValidate
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const next = {
                            code: code.trim().toUpperCase(),
                            description: description.trim(),
                            category: category.trim(),
                        };

                        if (!next.code || !next.description) {
                            setError('Isi kode dan deskripsi terlebih dahulu.');

                            return;
                        }

                        if (
                            items.some(
                                (item) =>
                                    item.code.toLocaleLowerCase('id-ID') ===
                                    next.code.toLocaleLowerCase('id-ID'),
                            )
                        ) {
                            setError(
                                'Kode ini sudah digunakan. Gunakan kode lain.',
                            );

                            return;
                        }

                        onSave(next);
                    }}
                >
                    <PageDialogToolbar>
                        <ActionButton action="save" type="submit" />
                        <ActionButton
                            action="cancel"
                            type="button"
                            onClick={onClose}
                        />
                    </PageDialogToolbar>
                    <FormErrorHeader
                        message={error}
                        operation="Perubahan contoh belum disimpan"
                    />
                    <PageDialogBody>
                        <div className="space-y-4">
                            {error && (
                                <Alert variant="destructive">
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            )}
                            <Input
                                label="Kode"
                                required
                                value={code}
                                onChange={(event) =>
                                    setCode(event.target.value)
                                }
                            />
                            <Input
                                label="Deskripsi"
                                required
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                            />
                            <Input
                                label="Kategori"
                                value={category}
                                onChange={(event) =>
                                    setCategory(event.target.value)
                                }
                            />
                        </div>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}
