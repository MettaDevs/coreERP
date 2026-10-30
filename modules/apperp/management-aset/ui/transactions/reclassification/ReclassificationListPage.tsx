import { useRef, useState } from 'react';
import { toast } from 'sonner';
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
import type { Context } from './reclassification';
import type { Reclassification } from './reclassification';
import {
    KINDS,
    STATUS,
    StatusBadge,
    izin,
    kindLabel,
    tanggalTampil,
} from './reclassification';
import ReclassificationDetailPage from './ReclassificationDetailPage';

/** Nilai penanda "tanpa saringan"; bukan string kosong supaya label pilihan tidak bertumpuk. */
const SEMUA = '__all__';

const PILIHAN_STATUS = [
    { value: SEMUA, label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'posted', label: 'Diposting' },
];

const PILIHAN_JENIS = [{ value: SEMUA, label: 'Semua jenis' }, ...KINDS];

/** Daftar reklasifikasi aset. Pratinjau dan posting ada di halaman rincian. */
export default function ReclassificationListPage({
    context,
    permissions,
}: {
    context: Context;
    permissions: string[];
}) {
    const can = izin(permissions);
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
    const cardRef = useRef<HTMLDivElement>(null);
    const openView = (id: string) => {
        requestChangesTransition(() => {
            setCard({ mode: 'view', id });
        });
    };
    const openEdit = (id: string) => {
        requestChangesTransition(() => {
            setCard({ mode: 'edit', id });
        });
    };
    const [status, setStatus] = useState(SEMUA);
    const [jenis, setJenis] = useState(SEMUA);
    const [search, setSearch] = useState('');

    const list = useRecordList<Reclassification>(
        '/reklasifikasi-aset',
        search,
        Object.fromEntries(
            Object.entries({ status, jenis }).filter(
                ([, value]) => value !== '' && value !== SEMUA,
            ),
        ),
    );
    const daftar = list.items;
    const terlihat = daftar;
    const memuat = list.loading;

    const columns: DataTableColumn<Reclassification>[] = [
        {
            id: 'kode',
            header: 'No. bukti',
            cell: (baris) => (
                <span className="text-primary font-medium">{baris.kode}</span>
            ),
            sortValue: (baris) => baris.kode,
            width: 170,
        },
        {
            id: 'tanggal',
            header: 'Tanggal',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 130,
        },
        {
            id: 'jenis',
            header: 'Jenis',
            cell: (baris) => kindLabel(baris.jenis),
            sortValue: (baris) => baris.jenis,
            width: 180,
        },
        {
            id: 'keterangan',
            header: 'Alasan',
            cell: (baris) => baris.keterangan || '—',
            minWidth: 200,
        },
        {
            id: 'jumlah',
            header: 'Baris',
            cell: (baris) => baris.jumlah_baris ?? 0,
            sortValue: (baris) => baris.jumlah_baris ?? 0,
            align: 'center',
            width: 90,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (baris) => <StatusBadge status={baris.status} />,
            sortValue: (baris) => STATUS[baris.status]?.label ?? baris.status,
            width: 120,
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
            '/reklasifikasi-aset',
            search,
            Object.fromEntries(
                Object.entries({ status, jenis }).filter(
                    ([, value]) => value !== '' && value !== SEMUA,
                ),
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
                    <RecordActionBar title="Reklasifikasi aset">
                        {can('create') && (
                            <ActionButton
                                action="create"
                                type="button"
                                onClick={() => {
                                    requestChangesTransition(() => {
                                        setCard({ mode: 'create' });
                                    });
                                }}
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
                                    placeholder="Nomor bukti atau alasan"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </FormControl>
                        </div>
                        <div className="w-full sm:w-56">
                            <FormControl>
                                <Select
                                    label="Jenis"
                                    items={PILIHAN_JENIS.map(
                                        (pilihan) => pilihan.label,
                                    )}
                                    value={
                                        PILIHAN_JENIS.find(
                                            (pilihan) =>
                                                pilihan.value === jenis,
                                        )?.label ?? null
                                    }
                                    ariaLabel="Saring berdasarkan jenis"
                                    onValueChange={(value) =>
                                        setJenis(
                                            PILIHAN_JENIS.find(
                                                (pilihan) =>
                                                    pilihan.label === value,
                                            )?.value ?? SEMUA,
                                        )
                                    }
                                />
                            </FormControl>
                        </div>
                        <div className="w-full sm:w-48">
                            <FormControl>
                                <Select
                                    label="Status"
                                    items={PILIHAN_STATUS.map(
                                        (pilihan) => pilihan.label,
                                    )}
                                    value={
                                        PILIHAN_STATUS.find(
                                            (pilihan) =>
                                                pilihan.value === status,
                                        )?.label ?? null
                                    }
                                    ariaLabel="Saring berdasarkan status"
                                    onValueChange={(value) =>
                                        setStatus(
                                            PILIHAN_STATUS.find(
                                                (pilihan) =>
                                                    pilihan.label === value,
                                            )?.value ?? SEMUA,
                                        )
                                    }
                                />
                            </FormControl>
                        </div>
                    </div>
                }
            >
                {!memuat && !terlihat.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada reklasifikasi</EmptyTitle>
                            <EmptyDescription>
                                Reklasifikasi memindah aset ke group lain, atau
                                memecah sebagian nilainya menjadi aset baru,
                                beserta harga perolehan, akumulasi penyusutan,
                                dan penyesuaian nilainya.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        incremental={list}
                        sort={list.sort}
                        onSortChange={list.onSortChange}
                        data={terlihat}
                        getRowKey={(baris) => baris.id}
                        getRowLabel={(baris) => baris.kode}
                        actions={actions}
                        onRowClick={(baris) => openView(baris.id)}
                        onRowAction={(action, baris) => {
                            if (action === 'detail') {
                                openView(baris.id);
                            }

                            if (action === 'edit') {
                                if (baris.status !== 'draft') {
                                    toast.error(
                                        'Reklasifikasi yang sudah diposting tidak dapat diubah. Buat reklasifikasi baru bila perlu dikoreksi.',
                                    );

                                    return;
                                }

                                openEdit(baris.id);
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
                        ref={cardRef}
                        aria-describedby={undefined}
                        onEscapeKeyDown={(event) => {
                            if (cardBusy) {
                                event.preventDefault();
                            }
                        }}
                        title={'Reklasifikasi aset'}
                        busy={cardBusy}
                        onNew={
                            !(card?.mode === 'create') && can('create')
                                ? openNewCard
                                : undefined
                        }
                        navigation={currentCardId ? cardNavigation : undefined}
                    >
                        <ReclassificationDetailPage
                            key={
                                card.mode === 'create'
                                    ? 'create'
                                    : card.mode + ':' + card.id
                            }
                            context={context}
                            permissions={permissions}
                            reclassificationId={
                                card.mode === 'create' ? undefined : card.id
                            }
                            mode={card.mode}
                            card={{
                                onDirtyChange: setCardDirty,
                                portalContainer: cardRef,
                                onBusyChange: setCardBusy,
                                onClose: closeCard,
                                onSaved: (id) => {
                                    setCardDirty(false);
                                    setCard({ mode: 'view', id });
                                    setCardDirty(false);
                                    list.reload();
                                },
                                onRequestView: openView,
                                onRequestEdit: openEdit,
                            }}
                        />
                    </PageDialogContent>
                </PageDialog>
            )}
        </>
    );
}
