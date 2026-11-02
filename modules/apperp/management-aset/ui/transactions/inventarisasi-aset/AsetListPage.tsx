import { usePage } from '@inertiajs/react';
import { FileSpreadsheet, QrCode } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { RecordFactBox } from '@/components/record-fact-box';
import type { Auth, Workspace } from '@/types';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import RecordListPage from '../../_shared/RecordListPage';
import { api, errorMessage } from '../../api';
import { requestListExport } from '../../listExport';
import { useRecordList } from '../../use-record-list';
import type { RecordCardState } from '../_shared/record-card';
import type { Aset, Context } from './aset';
import { LIFECYCLE, money } from './aset';
import AsetDetailPage from './AsetDetailPage';
import AssetPicture from './AssetPicture';
import type { BudgetedAsset } from './BudgetedAssetCard';
import BudgetedAssetCard from './BudgetedAssetCard';
import { printAssetLabels } from './labels';
import { izin } from './penerimaan';
import PenerimaanDetailPage from './PenerimaanDetailPage';

/**
 * Register aset dengan FactBox untuk aset yang dipilih.
 *
 * Menerima dan mengoreksi aset adalah urusan halaman rincian yang punya alamat sendiri,
 * bentuk yang sama dengan work order dan mutasi. Sebelum 18 September 2026 keduanya
 * dikerjakan di dalam `Sheet` pada halaman ini.
 */
export default function AsetListPage({
    context,
    canUpdate,
    permissions,
}: {
    context: Context;
    canUpdate: boolean;
    permissions: string[];
}) {
    const [budgetedAsset, setBudgetedAsset] = useState<
        BudgetedAsset | null | undefined
    >();
    const openBudgetedAsset = async (id: string) => {
        try {
            const result = await api<{ data: BudgetedAsset }>(`/aset/${id}`);
            setBudgetedAsset(result.data);
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Aset rencana belum dapat dibuka.'),
            );
        }
    };
    const [card, setCard] = useState<
        | { kind: 'asset'; mode: 'view' | 'edit'; id: string }
        | { kind: 'receipt'; record: Exclude<RecordCardState, null> }
        | null
    >(null);
    const [factBoxToolbar, setFactBoxToolbar] = useState<HTMLDivElement | null>(
        null,
    );
    const [cardBusy, setCardBusy] = useState(false);
    const [cardDirty, setCardDirty] = useState(false);
    const {
        request: requestChangesTransition,
        confirmation: changesTransitionConfirmation,
    } = useRecordTransition(cardDirty, cardBusy);
    const closeCard = () => {
        requestChangesTransition(() => {
            setCard(null);
            setCardDirty(false);
            list.reload();
        });
    };
    const [cardRevision, setCardRevision] = useState(0);
    const cardContentRef = useRef<HTMLDivElement>(null);
    const bukaAset = (id: string) => {
        if (list.items.find((asset) => asset.id === id)?.is_budgeted) {
            void openBudgetedAsset(id);

            return;
        }

        requestChangesTransition(() => {
            setCard({ kind: 'asset', mode: 'view', id });
        });
    };
    const bukaAsetUbah = (id: string) => {
        if (list.items.find((asset) => asset.id === id)?.is_budgeted) {
            void openBudgetedAsset(id);

            return;
        }

        requestChangesTransition(() => {
            setCard({ kind: 'asset', mode: 'edit', id });
        });
    };
    const createReceipt = () => {
        requestChangesTransition(() => {
            setCardDirty(false);
            setCard({ kind: 'receipt', record: { mode: 'create' } });
        });
    };

    const { auth, workspace } = usePage<{ auth: Auth; workspace: Workspace }>()
        .props;
    const legalEntityNames = new Map(
        workspace.legal_entities.map((entity) => [entity.id, entity.name]),
    );
    const [search, setSearch] = useState('');
    // Urutan yang sedang tampil, supaya berkas ekspor berurutan sama dengan layar.
    // Aset yang dicentang untuk dicetak labelnya. Tanpa centang, yang dicetak semua yang tampil.
    const [selected, setSelected] = useState<string[]>([]);
    const [activeRowId, setActiveRowId] = useState<string | null>(null);

    const list = useRecordList<Aset>('/aset', search);
    const aset = list.items;
    const visible = aset;
    const memuat = list.loading;
    const sort = list.sort;
    const setSort = list.onSortChange;

    const activeAsset = visible.find((asset) => asset.id === activeRowId);

    const columns: DataTableColumn<Aset>[] = [
        {
            id: 'kode',
            header: 'Kode aset',
            cell: (aset) => (
                <button
                    type="button"
                    className="text-primary font-medium hover:underline"
                    onClick={() => bukaAset(aset.id)}
                >
                    {aset.kode}
                </button>
            ),
            sortValue: (aset) => aset.kode,
            width: 160,
        },
        {
            id: 'nama',
            header: 'Nama aset',
            cell: (aset) => aset.nama,
            sortValue: (aset) => aset.nama,
            width: 240,
        },
        {
            id: 'legal_entity',
            header: 'Perusahaan',
            cell: (asset) =>
                asset.legal_entity_nama ??
                legalEntityNames.get(asset.legal_entity_id) ??
                'Perusahaan tidak dikenal',
            sortValue: (asset) =>
                asset.legal_entity_nama ??
                legalEntityNames.get(asset.legal_entity_id) ??
                '',
            width: 220,
        },
        {
            id: 'serial',
            header: 'Nomor seri',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.serial_number || '—'}
                </span>
            ),
            sortValue: (aset) => aset.serial_number ?? '',
            width: 160,
        },
        {
            id: 'group',
            header: 'Group aset',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.group_nama ?? '—'}
                </span>
            ),
            sortValue: (aset) => aset.group_nama ?? '',
            width: 180,
        },
        {
            id: 'jenis',
            header: 'Jenis aset',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.type_nama ?? '—'}
                </span>
            ),
            sortValue: (aset) => aset.type_nama ?? '',
            width: 180,
        },
        {
            id: 'lokasi',
            header: 'Lokasi',
            cell: (aset) => (
                <span className="text-muted-foreground">
                    {aset.location_nama ?? '—'}
                </span>
            ),
            sortValue: (aset) => aset.location_nama ?? '',
            width: 180,
        },
        {
            id: 'nilai',
            header: 'Nilai perolehan',
            cell: (aset) =>
                aset.is_budgeted
                    ? 'Belum diperoleh'
                    : aset.currency_code
                      ? money(aset.acquisition_value, aset.currency_code)
                      : '—',
            sortValue: (aset) => Number(aset.acquisition_value),
            align: 'right',
            width: 170,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (aset) => {
                const state = (aset.is_budgeted
                    ? { label: 'Rencana', variant: 'secondary' as const }
                    : LIFECYCLE[aset.lifecycle_state]) ?? {
                    label: aset.lifecycle_state,
                    variant: 'secondary' as const,
                };

                return <Badge variant={state.variant}>{state.label}</Badge>;
            },
            sortValue: (aset) =>
                LIFECYCLE[aset.lifecycle_state]?.label ?? aset.lifecycle_state,
            width: 140,
        },
    ];

    /**
     * Ekspor daftar yang sedang tampil (K-27): kolom dan judulnya, urutan, dan pencarian yang sama, untuk
     * semua aset yang cocok. Dikerjakan antrean ekspor Core; hasilnya muncul di ikon Ekspor pada header.
     */
    const exportList = () =>
        requestListExport({
            list: 'aset',
            columns: columns.map((column) => ({
                key: column.id,
                header: column.header,
            })),
            sort: sort
                ? { column: sort.columnId, direction: sort.direction }
                : null,
            filters: search.trim() ? { q: search.trim() } : {},
        });

    const printLabels = () =>
        printAssetLabels({
            ids: selected.length ? selected : visible.map((asset) => asset.id),
        });

    const rowActions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (canUpdate) {
        rowActions.push({ id: 'edit', label: 'Ubah' });
    }

    const cardNavigation = useRecordNavigation({
        items: list.items,
        currentId: card?.kind === 'asset' ? card.id : null,
        getId: (item) => item.id,
        onNavigate: bukaAset,
        scopeKey: JSON.stringify([search, list.sort]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: cardBusy || cardDirty,
    });

    return (
        <>
            {changesTransitionConfirmation}
            <RecordListPage
                toolbar={{
                    title: 'Inventarisasi aset',
                    search: {
                        value: search,
                        onValueChange: setSearch,
                        label: 'Cari aset',
                        placeholder: 'Cari aset...',
                    },
                    preferenceKey: auth.membership
                        ? `management-aset.inventory:${auth.membership.tenant_id}:${auth.user.id}`
                        : undefined,
                    groups: [
                        {
                            id: 'home',
                            label: 'Home',
                            actions: [
                                ...(izin(permissions)('create') &&
                                context.legal_entity_id
                                    ? [
                                          {
                                              id: 'budgeted',
                                              label: 'Aset sebagai perencanaan',
                                              onSelect: () =>
                                                  setBudgetedAsset(null),
                                          },
                                      ]
                                    : []),
                                {
                                    id: 'labels',
                                    label: selected.length
                                        ? `Cetak label (${selected.length})`
                                        : `Cetak label (${visible.length})`,
                                    icon: <QrCode />,
                                    disabled: memuat || !visible.length,
                                    onSelect: printLabels,
                                },
                                {
                                    id: 'export',
                                    label: 'Ekspor ke Excel',
                                    icon: <FileSpreadsheet />,
                                    disabled: memuat,
                                    onSelect: exportList,
                                },
                            ],
                        },
                    ],
                    trailing: (
                        <div
                            ref={setFactBoxToolbar}
                            className="flex items-center gap-1"
                        />
                    ),
                    children: (
                        <>
                            {izin(permissions)('create') && (
                                <ActionButton
                                    action="create"
                                    type="button"
                                    onClick={createReceipt}
                                />
                            )}
                        </>
                    ),
                }}
            >
                <RecordFactBox
                    toolbarContainer={factBoxToolbar}
                    fillHeight
                    recordType="aset_tr_aset"
                    recordId={activeAsset?.id}
                    details={
                        activeAsset ? (
                            <AssetPicture assetId={activeAsset.id} />
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                Pilih aset untuk melihat fotonya.
                            </p>
                        )
                    }
                >
                    {!memuat && !visible.length && !list.error ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    {aset.length
                                        ? 'Tidak ada aset yang cocok'
                                        : 'Belum ada aset'}
                                </EmptyTitle>
                                <EmptyDescription>
                                    {aset.length
                                        ? 'Ubah kata kunci pencarian untuk menemukan aset lain.'
                                        : 'Catat penerimaan aset pertama untuk mulai memantau lokasi, pengguna, dan penyusutannya.'}
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            columns={columns}
                            incremental={list}
                            data={visible}
                            getRowKey={(aset) => aset.id}
                            getRowLabel={(aset) => aset.kode}
                            sort={sort}
                            onSortChange={setSort}
                            selection={{
                                selectedKeys: selected,
                                onSelectedKeysChange: (keys) => {
                                    const ids = keys.map(String);
                                    setSelected(ids);
                                    setActiveRowId(ids.at(-1) ?? null);
                                },
                            }}
                            actions={rowActions}
                            activeRowKey={activeAsset?.id}
                            rowActivation="single-click"
                            onRowClick={(asset) => {
                                setSelected([asset.id]);
                                setActiveRowId(asset.id);
                            }}
                            onRowAction={(action, aset) => {
                                if (action === 'detail') {
                                    bukaAset(aset.id);
                                }

                                if (action === 'edit') {
                                    // Aset yang sudah dilepas tidak dapat dikoreksi; membuka
                                    // mode sunting hanya untuk ditolak server membuang waktu
                                    // orangnya dan menyembunyikan alasannya di balik toast.
                                    if (aset.lifecycle_state === 'disposed') {
                                        toast.error(
                                            'Aset yang sudah dilepas tidak dapat diubah.',
                                        );

                                        return;
                                    }

                                    bukaAsetUbah(aset.id);
                                }
                            }}
                        />
                    )}
                </RecordFactBox>
            </RecordListPage>
            {budgetedAsset !== undefined && context.legal_entity_id && (
                <BudgetedAssetCard
                    key={budgetedAsset?.id ?? 'new'}
                    asset={budgetedAsset ?? undefined}
                    companyId={
                        budgetedAsset?.legal_entity_id ??
                        context.legal_entity_id
                    }
                    unitId={context.org_unit_id}
                    permissions={permissions}
                    onClose={() => setBudgetedAsset(undefined)}
                    onSaved={() => {
                        setBudgetedAsset(undefined);
                        list.reload();
                    }}
                />
            )}
            {card && (
                <PageDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            closeCard();
                        }
                    }}
                >
                    <PageDialogContent
                        ref={cardContentRef}
                        onEscapeKeyDown={(event) => {
                            if (cardBusy) {
                                event.preventDefault();
                            }
                        }}
                        title={
                            card.kind === 'asset'
                                ? 'Inventarisasi aset'
                                : 'Penerimaan aset'
                        }
                        busy={cardBusy}
                        navigation={
                            card?.kind === 'asset' ? cardNavigation : undefined
                        }
                        onNew={
                            card &&
                            !(
                                card.kind === 'receipt' &&
                                card.record.mode === 'create'
                            ) &&
                            izin(permissions)('create')
                                ? createReceipt
                                : undefined
                        }
                    >
                        {card.kind === 'asset' ? (
                            <AsetDetailPage
                                key={`${card.mode}-${card.id}-${cardRevision}`}
                                context={context}
                                canUpdate={canUpdate}
                                permissions={permissions}
                                asetId={card.id}
                                mode={card.mode}
                                portalContainer={cardContentRef}
                                onBusyChange={setCardBusy}
                                onClose={closeCard}
                                onSaved={(id) => {
                                    setCardDirty(false);
                                    setCard({
                                        kind: 'asset',
                                        mode: 'view',
                                        id,
                                    });
                                    setCardRevision((version) => version + 1);
                                    list.reload();
                                }}
                                onRequestView={bukaAset}
                                onRequestEdit={bukaAsetUbah}
                                onDirtyChange={setCardDirty}
                            />
                        ) : (
                            <PenerimaanDetailPage
                                key={`${card.record.mode}-${'id' in card.record ? card.record.id : 'new'}-${cardRevision}`}
                                context={context}
                                permissions={permissions}
                                penerimaanId={
                                    'id' in card.record
                                        ? card.record.id
                                        : undefined
                                }
                                mode={card.record.mode}
                                portalContainer={cardContentRef}
                                onBusyChange={setCardBusy}
                                onClose={closeCard}
                                onSaved={(id) => {
                                    setCardDirty(false);

                                    if (!izin(permissions)('read')) {
                                        setCard(null);
                                        list.reload();

                                        return;
                                    }

                                    setCard({
                                        kind: 'receipt',
                                        record: { mode: 'view', id },
                                    });
                                    setCardRevision((version) => version + 1);
                                    list.reload();
                                }}
                                onRequestView={(id) => {
                                    requestChangesTransition(() => {
                                        setCard({
                                            kind: 'receipt',
                                            record: { mode: 'view', id },
                                        });
                                    });
                                }}
                                onRequestEdit={(id) => {
                                    requestChangesTransition(() => {
                                        setCard({
                                            kind: 'receipt',
                                            record: { mode: 'edit', id },
                                        });
                                    });
                                }}
                                onDirtyChange={setCardDirty}
                            />
                        )}
                    </PageDialogContent>
                </PageDialog>
            )}
        </>
    );
}
