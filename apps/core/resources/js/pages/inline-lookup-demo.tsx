import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import { Label } from '@apperp/ui/label';
import {
    PageDialog as Dialog,
    PageDialogBody as DialogBody,
    PageDialogContent as DialogContent,
    PageDialogTrigger as DialogTrigger,
} from '@apperp/ui/page-dialog';
import { useId, useRef, useState } from 'react';
import type { RefObject } from 'react';
import {
    LookupCreateDialog,
    LookupDetailsDialog,
    LookupListDialog,
} from './lookup-action-dialogs';
import type { LookupActions, LookupOption } from './lookup-action-dialogs';

const lookupColumns: DataTableColumn<LookupOption>[] = [
    {
        id: 'code',
        header: 'Kode',
        cell: (item) => item.code,
        sortValue: (item) => item.code,
        width: 160,
    },
    {
        id: 'description',
        header: 'Deskripsi',
        cell: (item) => item.description,
        sortValue: (item) => item.description,
        width: 400,
    },
];

const options: LookupOption[] = [
    { code: 'GROUP-01', description: 'Barang umum', category: 'Barang' },
    { code: 'GROUP-02', description: 'Layanan', category: 'Jasa' },
    {
        code: 'GROUP-03',
        description: 'Perlengkapan kantor',
        category: 'Barang',
    },
    { code: 'GROUP-04', description: 'Bahan baku', category: 'Barang' },
    { code: 'GROUP-05', description: 'Biaya pengiriman', category: 'Jasa' },
    { code: 'GROUP-06', description: 'Jasa perawatan', category: 'Jasa' },
];

function InlineLookupField({
    portalContainer,
    actions,
    items,
    onCreate,
    onUpdate,
    useDefaultDialogs,
}: {
    portalContainer?: RefObject<HTMLDivElement | null>;
    actions: LookupActions;
    items: LookupOption[];
    onCreate: (item: LookupOption) => void;
    onUpdate: (item: LookupOption) => void;
    useDefaultDialogs: boolean;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [selectedCode, setSelectedCode] = useState<string | null>(null);
    const value = items.find((item) => item.code === selectedCode) ?? null;
    const [listOpen, setListOpen] = useState(false);
    const [detail, setDetail] = useState<LookupOption | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const choose = (item: LookupOption) => {
        setSelectedCode(item.code);
        setListOpen(false);
        requestAnimationFrame(() => inputRef.current?.focus());
    };

    return (
        <>
            <InlineLookup
                label="Kelompok pencatatan produk"
                items={items}
                columns={lookupColumns}
                value={value}
                getItemValue={(item) => item.code}
                getItemLabel={(item) => item.description}
                getSearchText={(item) => `${item.code} ${item.description}`}
                placeholder="Cari kode atau deskripsi…"
                emptyMessage="Tidak ada pilihan yang cocok."
                inputRef={inputRef}
                portalContainer={portalContainer}
                onValueChange={(item) => setSelectedCode(item.code)}
                onCreate={
                    actions.create ? () => setCreateOpen(true) : undefined
                }
                showDetails={actions.details}
                showFullList={actions.fullList}
                onDetails={
                    actions.details && !useDefaultDialogs
                        ? setDetail
                        : undefined
                }
                onFullList={
                    actions.fullList && !useDefaultDialogs
                        ? () => setListOpen(true)
                        : undefined
                }
            />
            {listOpen && (
                <LookupListDialog
                    items={items}
                    initialSelection={value}
                    actions={actions}
                    onChoose={choose}
                    onClose={() => {
                        setListOpen(false);
                        requestAnimationFrame(() => inputRef.current?.focus());
                    }}
                    onDetails={setDetail}
                    onNew={() => setCreateOpen(true)}
                    onUpdate={(item) => {
                        onUpdate(item);
                    }}
                />
            )}
            {detail && (
                <LookupDetailsDialog
                    item={
                        items.find((item) => item.code === detail.code) ??
                        detail
                    }
                    canEdit={actions.edit}
                    onSave={(item) => {
                        onUpdate(item);
                    }}
                    onClose={() => {
                        setDetail(null);

                        if (!listOpen) {
                            requestAnimationFrame(() =>
                                inputRef.current?.focus(),
                            );
                        }
                    }}
                />
            )}
            {createOpen && (
                <LookupCreateDialog
                    items={items}
                    onSave={(item) => {
                        onCreate(item);
                        setCreateOpen(false);
                        choose(item);
                    }}
                    onClose={() => {
                        setCreateOpen(false);

                        if (!listOpen) {
                            requestAnimationFrame(() =>
                                inputRef.current?.focus(),
                            );
                        }
                    }}
                />
            )}
        </>
    );
}

export function InlineLookupDemo() {
    const dialogRef = useRef<HTMLDivElement>(null);
    const settingsId = useId();
    const [useDefaultDialogs, setUseDefaultDialogs] = useState(false);
    const [items, setItems] = useState(options);
    const addItem = (item: LookupOption) =>
        setItems((current) => [...current, item]);
    const updateItem = (item: LookupOption) =>
        setItems((current) =>
            current.map((existing) =>
                existing.code === item.code ? item : existing,
            ),
        );
    const [actions, setActions] = useState<LookupActions>({
        fullList: true,
        details: true,
        create: true,
        edit: true,
        editList: true,
    });

    return (
        <div className="space-y-5">
            <p className="text-sm text-muted-foreground">
                Label tetap terlihat saat kosong, mengetik, atau sudah memilih.
                Ketik langsung di field untuk mencari berdasarkan kode maupun
                deskripsi.
            </p>
            <fieldset className="space-y-3 rounded-md border p-3">
                <legend className="px-1 text-sm font-medium">
                    Simulasi kemampuan dan izin
                </legend>
                <div className="flex flex-wrap gap-4">
                    {(
                        [
                            ['fullList', 'Daftar lengkap tersedia'],
                            ['details', 'Detail tersedia'],
                            ['create', 'Boleh menambah data'],
                            ['edit', 'Boleh mengubah kartu'],
                            ['editList', 'Boleh mengubah daftar'],
                        ] as const
                    ).map(([key, label]) => (
                        <Label key={key} htmlFor={`${settingsId}-${key}`}>
                            <Checkbox
                                id={`${settingsId}-${key}`}
                                checked={actions[key]}
                                onCheckedChange={(checked) =>
                                    setActions((current) => ({
                                        ...current,
                                        [key]: checked === true,
                                    }))
                                }
                            />
                            {label}
                        </Label>
                    ))}
                </div>
            </fieldset>
            <Label htmlFor={`${settingsId}-default-dialogs`}>
                <Checkbox
                    id={`${settingsId}-default-dialogs`}
                    checked={useDefaultDialogs}
                    onCheckedChange={(checked) =>
                        setUseDefaultDialogs(checked === true)
                    }
                />
                Coba dialog bawaan
            </Label>
            <p className="text-sm text-muted-foreground">
                Data contoh hanya tersimpan selama halaman ini terbuka.
            </p>
            <InlineLookupField
                actions={actions}
                items={items}
                onCreate={addItem}
                onUpdate={updateItem}
                useDefaultDialogs={useDefaultDialogs}
            />
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="outline">Coba di dialog</Button>
                </DialogTrigger>
                <DialogContent
                    ref={dialogRef}
                    onEscapeKeyDown={(event) => {
                        if (
                            dialogRef.current?.querySelector(
                                '[role="combobox"][aria-expanded="true"]',
                            )
                        ) {
                            event.preventDefault();
                        }
                    }}
                    title={'Dropdown dengan pencarian di field'}
                    description={
                        'Coba mengetik, memilih, dan membatalkan pencarian di\n                            dalam dialog.'
                    }
                >
                    <DialogBody>
                        <InlineLookupField
                            portalContainer={dialogRef}
                            actions={actions}
                            items={items}
                            onCreate={addItem}
                            onUpdate={updateItem}
                            useDefaultDialogs={useDefaultDialogs}
                        />
                    </DialogBody>
                </DialogContent>
            </Dialog>
        </div>
    );
}
