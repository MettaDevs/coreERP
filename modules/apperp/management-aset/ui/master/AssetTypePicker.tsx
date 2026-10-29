import { useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { DataTable } from '@apperp/ui/data-table';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { MasterOption, useMasterOptions } from './useMasterOptions';

export type AssetType = MasterOption;

export function AssetTypePicker({
    source,
    disabled,
    onClose,
    onChoose,
}: {
    source: ReturnType<typeof useMasterOptions>;
    disabled: boolean;
    onClose: () => void;
    onChoose: (records: AssetType[]) => void;
}) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<AssetType[]>([]);
    const list = useIncrementalList({
        loadItems: source.loadItems,
        query,
        getKey: (record) => record.id,
    });

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <PageDialogContent title="Pilih jenis aset" busy={disabled}>
                <PageDialogToolbar>
                    <ActionButton
                        action="add"
                        type="button"
                        disabled={disabled || selected.length === 0}
                        onClick={() => {
                            source.acceptItems(selected);
                            onChoose(selected);
                        }}
                    />
                    <ActionButton
                        action="cancel"
                        disabled={disabled}
                        onClick={onClose}
                    />
                </PageDialogToolbar>
                <PageDialogBody className="flex min-h-0 flex-1 flex-col gap-3">
                    <FormControl>
                        <Input
                            label="Cari jenis aset"
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </FormControl>
                    <DataTable
                        columns={[
                            {
                                id: 'kode',
                                header: 'Kode',
                                width: 180,
                                cell: (record) => record.kode,
                            },
                            {
                                id: 'nama',
                                header: 'Nama',
                                minWidth: 280,
                                cell: (record) => record.nama,
                            },
                        ]}
                        data={list.items}
                        getRowKey={(record) => record.id}
                        getRowLabel={(record) => record.nama}
                        incremental={list}
                        scrollMode="viewport"
                        selection={{
                            selectedKeys: selected.map((record) => record.id),
                            onSelectedKeysChange: (keys) => {
                                const known = new Map(
                                    [...selected, ...list.items].map(
                                        (record) => [record.id, record],
                                    ),
                                );
                                setSelected(
                                    keys.flatMap((key) => {
                                        const record = known.get(String(key));

                                        return record ? [record] : [];
                                    }),
                                );
                            },
                        }}
                        emptyMessage="Jenis aset tidak ditemukan."
                    />
                    <p className="text-muted-foreground text-sm">
                        {selected.length} jenis aset dipilih.
                    </p>
                </PageDialogBody>
            </PageDialogContent>
        </PageDialog>
    );
}
