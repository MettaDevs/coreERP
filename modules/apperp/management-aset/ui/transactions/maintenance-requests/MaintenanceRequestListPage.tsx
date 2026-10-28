import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { ActionButton } from '@apperp/ui/action-button';
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
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { useRecordList } from '../../use-record-list';
import type { RecordCardState } from '../_shared/record-card';
import type { Context } from './maintenanceRequest';
import type { MaintenanceRequest } from './maintenanceRequest';
import {
    STATUS,
    StatusBadge,
    codeName,
    permissionCheck,
} from './maintenanceRequest';
import MaintenanceRequestDetailPage from './MaintenanceRequestDetailPage';

/**
 * Nilai pilihan "semua". `Select` baru menganggap field terisi bila nilainya tidak kosong; dengan nilai
 * kosong label "Status" turun ke tengah dan bertumpuk dengan teks pilihannya.
 */
const ALL = '__all__';

const STATUS_FILTER = [
    { value: ALL, label: 'Semua status' },
    ...Object.entries(STATUS).map(([value, { label }]) => ({ value, label })),
];

/**
 * Daftar permintaan pemeliharaan. Hanya menampilkan dan memilih; mengajukan, memutuskan, dan
 * membuat work order dilakukan di halaman rincian.
 */
export default function MaintenanceRequestListPage({
    context,
    permissions,
}: {
    context: Context;
    permissions: string[];
}) {
    const [card, setCard] = useState<RecordCardState>(null);
    const [cardBusy, setCardBusy] = useState(false);
    const [cardDirty, setCardDirty] = useState(false);
    const {
        request: requestChangesTransition,
        confirmation: changesTransitionConfirmation,
    } = useRecordTransition(cardDirty, cardBusy);
    const openNewCard = () => {
        requestChangesTransition(() => {
            setCardDirty(false);
            setCard({ mode: 'create' });
        });
    };
    const closeCard = () => {
        requestChangesTransition(() => {
            setCard(null);
            setCardDirty(false);
            list.reload();
        });
    };
    const [cardRevision, setCardRevision] = useState(0);
    const cardContentRef = useRef<HTMLDivElement>(null);
    const openNewRequest = () => setCard({ mode: 'create' });
    const openRequest = (id: string) => setCard({ mode: 'view', id });
    const openRequestEdit = (id: string) => setCard({ mode: 'edit', id });

    const can = permissionCheck(permissions);
    const formatDateTime = useDateTimeFormat();
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');

    const list = useRecordList<MaintenanceRequest>(
        '/permintaan-pemeliharaan',
        search,
        Object.fromEntries(
            Object.entries({ status }).filter(([, value]) => value !== ''),
        ),
    );
    const rows = list.items;
    const visible = rows;
    const loading = list.loading;

    const columns: DataTableColumn<MaintenanceRequest>[] = [
        {
            id: 'kode',
            header: 'No. permintaan',
            cell: (row) => (
                <span className="text-primary font-medium">{row.kode}</span>
            ),
            sortValue: (row) => row.kode,
            width: 160,
        },
        {
            id: 'dibuat',
            header: 'Dilaporkan',
            cell: (row) => formatDateTime(row.created_at),
            sortValue: (row) => row.created_at,
            width: 170,
        },
        {
            id: 'jenis',
            header: 'Jenis permintaan',
            cell: (row) => row.jenis_permintaan_nama ?? '—',
            sortValue: (row) => row.jenis_permintaan_nama ?? '',
            width: 180,
        },
        {
            id: 'objek',
            header: 'Aset atau lokasi',
            cell: (row) =>
                row.aset_id
                    ? codeName(row.aset_kode, row.aset_nama)
                    : (row.lokasi_nama ?? '—'),
            sortValue: (row) => row.aset_kode ?? row.lokasi_nama ?? '',
            minWidth: 180,
            width: 240,
        },
        {
            id: 'deskripsi',
            header: 'Deskripsi',
            cell: (row) => (
                <span className="line-clamp-1">{row.deskripsi}</span>
            ),
            minWidth: 200,
            width: 300,
        },
        {
            id: 'layanan',
            header: 'Tingkat layanan',
            cell: (row) => row.tingkat_layanan_nama ?? '—',
            sortValue: (row) => row.tingkat_layanan_nama ?? '',
            width: 150,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (row) => <StatusBadge status={row.status} />,
            sortValue: (row) => STATUS[row.status]?.label ?? row.status,
            width: 160,
        },
        {
            id: 'wo',
            header: 'Work order',
            cell: (row) => row.work_order_kode ?? '—',
            sortValue: (row) => row.work_order_kode ?? '',
            width: 150,
        },
    ];

    const actions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (can('update')) {
        actions.push({ id: 'edit', label: 'Ubah' });
    }

    const currentCardId = card && 'id' in card ? card.id : null;
    const cardNavigation = useRecordNavigation({
        items: list.items,
        currentId: currentCardId,
        getId: (item) => item.id,
        onNavigate: (id) => setCard({ mode: 'view', id }),
        scopeKey: JSON.stringify([
            '/permintaan-pemeliharaan',
            search,
            Object.fromEntries(
                Object.entries({ status }).filter(([, value]) => value !== ''),
            ),
            list.sort,
        ]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: cardBusy || cardDirty,
    });

    return (
        <>
            {changesTransitionConfirmation}
            <ListPageLayout
                header={
                    <RecordActionBar title="Permintaan pemeliharaan">
                        {can('create') && (
                            <ActionButton
                                action="create"
                                type="button"
                                onClick={openNewRequest}
                            />
                        )}
                    </RecordActionBar>
                }
                filters={
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                        <div className="w-full sm:w-72">
                            <FormControl>
                                <Input
                                    label="Cari"
                                    type="search"
                                    placeholder="Nomor, aset, lokasi, atau deskripsi"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </FormControl>
                        </div>
                        <div className="w-full sm:w-52">
                            <FormControl>
                                <Select
                                    label="Status"
                                    items={STATUS_FILTER}
                                    value={status || ALL}
                                    ariaLabel="Saring berdasarkan status"
                                    onValueChange={(value) => {
                                        setStatus(
                                            !value || value === ALL
                                                ? ''
                                                : value,
                                        );
                                    }}
                                />
                            </FormControl>
                        </div>
                    </div>
                }
            >
                {!loading && !visible.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Belum ada permintaan pemeliharaan
                            </EmptyTitle>
                            <EmptyDescription>
                                Unit melaporkan kerusakan atau kebutuhan
                                perbaikan aset di sini. Perencana meninjaunya
                                lalu membuatkan work order.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        incremental={list}
                        sort={list.sort}
                        onSortChange={list.onSortChange}
                        data={visible}
                        getRowKey={(row) => row.id}
                        getRowLabel={(row) => row.kode}
                        actions={actions}
                        onRowClick={(row) => openRequest(row.id)}
                        onRowAction={(action, row) => {
                            if (action === 'detail') {
                                openRequest(row.id);
                            }

                            if (action === 'edit') {
                                if (row.status !== 'draft') {
                                    toast.error(
                                        'Hanya permintaan draf yang dapat diubah.',
                                    );

                                    return;
                                }

                                openRequestEdit(row.id);
                            }
                        }}
                    />
                )}
            </ListPageLayout>
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
                        title={'Permintaan pemeliharaan'}
                        busy={cardBusy}
                        onNew={
                            !(card?.mode === 'create') && can('create')
                                ? openNewCard
                                : undefined
                        }
                        navigation={currentCardId ? cardNavigation : undefined}
                    >
                        <MaintenanceRequestDetailPage
                            key={`${card.mode}-${'id' in card ? card.id : 'new'}-${cardRevision}`}
                            context={context}
                            permissions={permissions}
                            requestId={'id' in card ? card.id : undefined}
                            mode={card.mode}
                            portalContainer={cardContentRef}
                            onBusyChange={setCardBusy}
                            onClose={closeCard}
                            onSaved={(id) => {
                                setCard({ mode: 'view', id });
                                setCardRevision((version) => version + 1);
                                list.reload();
                            }}
                            onRequestView={(id) => {
                                requestChangesTransition(() => {
                                    setCard({ mode: 'view', id });
                                });
                            }}
                            onRequestEdit={(id) => {
                                requestChangesTransition(() => {
                                    setCard({ mode: 'edit', id });
                                });
                            }}
                            onDirtyChange={setCardDirty}
                        />
                    </PageDialogContent>
                </PageDialog>
            )}
        </>
    );
}
