import { useEffect, useId, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DataTable } from '@apperp/ui/data-table';

import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';

import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import type { ApiValidationErrors } from '../../api';
import type { MasterDetailsPayload } from '../DraftMasterDetails';

type Variant = {
    creation_key?: string;
    id: string;
    kode: string;
    nama: string;
    keterangan: string | null;
    aktif: boolean;
    version: number;
};
type Choice = { id: string; kode: string; nama: string };

function choiceItem(item: Choice): TransferListItem {
    return { id: item.id, label: item.nama, description: item.kode };
}

export default function MaintenanceJobTypeDetails({
    jobTypeId,
    canEdit,
    onChange,
    validationErrors,
}: {
    jobTypeId: string;
    canEdit: boolean;
    onChange: (details: MasterDetailsPayload) => void;
    validationErrors?: ApiValidationErrors;
}) {
    const { confirm: confirmAction, confirmation: actionConfirmation } =
        useConfirmation();

    const [variants, setVariants] = useState<Variant[]>([]);
    const [remaining, setRemaining] = useState<TransferListItem[]>([]);
    const [selected, setSelected] = useState<TransferListItem[]>([]);
    const [variantName, setVariantName] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [variantError, setVariantError] = useState('');
    const variantErrorId = useId();
    const [changedVariants, setChangedVariants] = useState<Variant[]>([]);
    const [deletedVariants, setDeletedVariants] = useState<
        { id: string; version: number }[]
    >([]);
    function publishVariants(
        changes: Variant[],
        deleted: { id: string; version: number }[],
    ) {
        setChangedVariants(changes);
        setDeletedVariants(deleted);
        onChange({
            variants: {
                rows: changes.map((row) => ({
                    ...(row.creation_key
                        ? { creation_key: row.creation_key }
                        : { id: row.id, version: row.version }),
                    nama: row.nama,
                    keterangan: row.keterangan,
                    aktif: row.aktif,
                })),
                deleted,
            },
        });
    }

    useEffect(() => {
        let dilepas = false;

        // Pengambilan data lahir di dalam effect: state baru disetel setelah jawaban
        // server tiba, bukan pada commit render yang sama, dan jawaban yang telat datang
        // setelah panel dilepas dibuang lewat `dilepas`.
        const muat = async () => {
            try {
                const [variantResult, asetTypeResult] = await Promise.all([
                    api<{ data: Variant[] }>(
                        `/maintenance-job-types/${jobTypeId}/variants`,
                    ),
                    api<{ data: { remaining: Choice[]; selected: Choice[] } }>(
                        `/maintenance-job-types/${jobTypeId}/jenis-aset`,
                    ),
                ]);

                if (dilepas) {
                    return;
                }

                setVariants(variantResult.data);
                setRemaining(asetTypeResult.data.remaining.map(choiceItem));
                setSelected(asetTypeResult.data.selected.map(choiceItem));
                setError('');
            } catch (caught) {
                if (dilepas) {
                    return;
                }

                setError(
                    errorMessage(
                        caught,
                        'Rincian maintenance belum dapat dimuat.',
                    ),
                );
            } finally {
                if (!dilepas) {
                    setLoading(false);
                }
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [jobTypeId]);

    function addVariant() {
        if (!variantName.trim()) {
            setVariantError('Isi nama varian.');

            return;
        }

        setVariantError('');
        const creationKey = newIdempotencyKey();
        const row: Variant = {
            id: `draft:${creationKey}`,
            creation_key: creationKey,
            kode: '',
            nama: variantName.trim(),
            keterangan: null,
            aktif: true,
            version: 0,
        };
        setVariants([...variants, row]);
        publishVariants([...changedVariants, row], deletedVariants);
        setVariantName('');
    }

    async function removeVariant(variant: Variant) {
        if (
            !(await confirmAction({
                title: 'Delete varian ini?',
                description:
                    'Data yang dihapus tidak lagi tampil pada daftar pilihan.',
                confirmLabel: 'Delete',
                destructive: true,
            }))
        ) {
            return;
        }

        setVariants(variants.filter((row) => row.id !== variant.id));
        publishVariants(
            changedVariants.filter((row) => row.id !== variant.id),
            variant.creation_key
                ? deletedVariants
                : [
                      ...deletedVariants,
                      { id: variant.id, version: variant.version },
                  ],
        );
    }

    if (loading) {
        return <p className="text-muted-foreground text-sm">Memuat rincian�</p>;
    }

    if (
        error &&
        variants.length === 0 &&
        remaining.length === 0 &&
        selected.length === 0
    ) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    return (
        <div className="space-y-7">
            {actionConfirmation}
            {Object.entries(validationErrors ?? {})
                .filter(
                    ([field]) =>
                        field.startsWith('details.variants.') ||
                        field.startsWith('details.asset_types.'),
                )
                .map(([field, messages]) => (
                    <p key={field} className="text-destructive text-sm">
                        {messages[0]}
                    </p>
                ))}

            <section className="space-y-3">
                <div className="flex items-center justify-between">
                    <h3 className="font-semibold">
                        Varian jenis pekerjaan maintenance
                    </h3>
                    {canEdit && (
                        <div className="flex gap-2">
                            <div className="flex flex-col gap-1">
                                <Input
                                    aria-label="Nama varian baru"
                                    name="nama"
                                    aria-invalid={!!variantError}
                                    aria-describedby={
                                        variantError
                                            ? variantErrorId
                                            : undefined
                                    }
                                    placeholder="Nama varian"
                                    value={variantName}
                                    onChange={(event) =>
                                        setVariantName(event.target.value)
                                    }
                                />
                                {variantError && (
                                    <p
                                        id={variantErrorId}
                                        role="alert"
                                        className="text-destructive text-sm"
                                    >
                                        {variantError}
                                    </p>
                                )}
                            </div>
                            <ActionButton
                                action="add"
                                type="button"
                                onClick={() => void addVariant()}
                            />
                        </div>
                    )}
                </div>
                {variants.length === 0 ? (
                    <Empty>
                        <EmptyDescription>
                            Belum ada varian. Tambahkan interval seperti
                            mingguan atau tahunan.
                        </EmptyDescription>
                    </Empty>
                ) : (
                    (() => {
                        const sdkRows = variants.map((item, rowIndex) => {
                            return { item, rowIndex };
                        });
                        const sdkColumns: DataTableColumn<
                            (typeof sdkRows)[number]
                        >[] = [
                            {
                                id: 'column-0',
                                header: 'Kode',
                                cell: ({ item }) => <>{item.kode}</>,
                            } satisfies DataTableColumn<
                                (typeof sdkRows)[number]
                            >,
                            {
                                id: 'column-1',
                                header: 'Nama',
                                cell: ({ item }) => <>{item.nama}</>,
                            } satisfies DataTableColumn<
                                (typeof sdkRows)[number]
                            >,
                            ...(canEdit
                                ? [
                                      {
                                          id: 'column-2',
                                          header: 'Aksi',
                                          width: 112,
                                          cell: ({ item }) => (
                                              <>
                                                  <ActionButton
                                                      action="archive"
                                                      type="button"
                                                      onClick={() =>
                                                          void removeVariant(
                                                              item,
                                                          )
                                                      }
                                                  />
                                              </>
                                          ),
                                      } satisfies DataTableColumn<
                                          (typeof sdkRows)[number]
                                      >,
                                  ]
                                : []),
                        ];

                        return (
                            <DataTable
                                scrollMode="inline"
                                showRowNumbers={false}
                                serverSort
                                data={sdkRows}
                                columns={sdkColumns}
                                getRowKey={({ item }) => item.id}
                            />
                        );
                    })()
                )}
            </section>

            <section className="space-y-3">
                <h3 className="font-semibold">
                    Jenis aset yang menggunakan pekerjaan ini
                </h3>
                <p className="text-muted-foreground text-sm">
                    Pilih jenis aset yang boleh memakai job type ini saat
                    maintenance dibuat.
                </p>
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        onChange({
                            asset_types: {
                                jenis_aset_ids: next.selected.map(
                                    (item) => item.id,
                                ),
                            },
                        });
                    }}
                    remainingTitle="Jenis aset tersedia"
                    selectedTitle="Jenis aset terpilih"
                    disabled={!canEdit}
                    remainingEmptyLabel="Semua jenis aset sudah terpilih."
                    selectedEmptyLabel="Belum ada jenis aset yang dipilih."
                />
            </section>
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
