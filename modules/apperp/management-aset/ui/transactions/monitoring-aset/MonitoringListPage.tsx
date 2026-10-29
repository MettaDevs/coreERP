import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import { DatePicker } from '@apperp/ui/date-picker';
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
import type { Context } from './monitoring';
import type { Monitoring } from './monitoring';
import { STATUS, StatusBadge, izin, tanggalTampil } from './monitoring';
import MonitoringDetailPage from './MonitoringDetailPage';

/** Nilai filter status; `''` berarti semua. */
const PILIHAN_STATUS = [
    { value: '', label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'selesai', label: 'Selesai' },
];

/**
 * Daftar pemeriksaan fisik aset. Ia hanya menampilkan dan memilih; mengisi baris dan
 * menyelesaikan pemeriksaan adalah urusan halaman rincian yang punya alamat sendiri.
 */
export default function MonitoringListPage({
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
    const [status, setStatus] = useState('');
    const [dari, setDari] = useState('');
    const [sampai, setSampai] = useState('');
    const [search, setSearch] = useState('');

    const list = useRecordList<Monitoring>(
        '/monitoring-aset',
        search,
        Object.fromEntries(
            Object.entries({ status, dari, sampai }).filter(
                ([, value]) => value !== '',
            ),
        ),
    );
    const daftar = list.items;
    const terlihat = daftar;
    const memuat = list.loading;

    const columns: DataTableColumn<Monitoring>[] = [
        {
            id: 'kode',
            header: 'No. bukti',
            cell: (baris) => (
                <span className="text-primary font-medium">{baris.kode}</span>
            ),
            sortValue: (baris) => baris.kode,
            width: 160,
        },
        {
            id: 'tanggal',
            header: 'Tanggal monitoring',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 160,
        },
        {
            id: 'lokasi',
            header: 'Lokasi aset',
            cell: (baris) => baris.lokasi_aset_nama ?? '—',
            sortValue: (baris) => baris.lokasi_aset_nama ?? '',
            minWidth: 160,
            width: 200,
        },
        {
            id: 'unit',
            header: 'Unit organisasi',
            cell: (baris) => baris.responsible_org_unit_nama ?? 'Semua unit',
            sortValue: (baris) => baris.responsible_org_unit_nama ?? '',
            minWidth: 150,
            width: 180,
        },
        {
            id: 'pic',
            header: 'Penanggung jawab',
            cell: (baris) => baris.penanggung_jawab_nama ?? '—',
            sortValue: (baris) => baris.penanggung_jawab_nama ?? '',
            minWidth: 150,
            width: 180,
        },
        {
            id: 'jumlah',
            header: 'Aset diperiksa',
            cell: (baris) => {
                const belum = baris.jumlah_belum_diperiksa ?? 0;

                return belum > 0
                    ? `${baris.jumlah_baris ?? 0} (${belum} belum)`
                    : (baris.jumlah_baris ?? 0);
            },
            sortValue: (baris) => baris.jumlah_baris ?? 0,
            align: 'center',
            width: 140,
        },
        {
            id: 'tidak_sesuai',
            header: 'Tidak sesuai',
            cell: (baris) => baris.jumlah_tidak_sesuai ?? 0,
            sortValue: (baris) => baris.jumlah_tidak_sesuai ?? 0,
            align: 'center',
            width: 130,
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
            '/monitoring-aset',
            search,
            Object.fromEntries(
                Object.entries({ status, dari, sampai }).filter(
                    ([, value]) => value !== '',
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
                    <RecordActionBar title="Monitoring aset">
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
                                    placeholder="Nomor bukti, lokasi, atau orang"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </FormControl>
                        </div>
                        <div className="w-full sm:w-44">
                            <FormControl>
                                <DatePicker
                                    label="Dari tanggal"

                                    value={dari}
                                    onValueChange={(value) => setDari(value)}
                                />
                            </FormControl>
                        </div>
                        <div className="w-full sm:w-44">
                            <FormControl>
                                <DatePicker
                                    label="Sampai tanggal"

                                    value={sampai}
                                    onValueChange={(value) => setSampai(value)}
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
                                            )?.value ?? '',
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
                            <EmptyTitle>Belum ada monitoring aset</EmptyTitle>
                            <EmptyDescription>
                                Monitoring mencatat hasil pemeriksaan fisik aset
                                di satu lokasi: mana yang ada, mana yang tidak,
                                dan mana yang tidak cocok dengan catatan aset.
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
                                        'Monitoring yang sudah selesai tidak dapat diubah. Buat monitoring baru bila temuannya perlu dikoreksi.',
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
                        title={'Monitoring aset'}
                        busy={cardBusy}
                        onNew={
                            !(card?.mode === 'create') && can('create')
                                ? openNewCard
                                : undefined
                        }
                        navigation={currentCardId ? cardNavigation : undefined}
                    >
                        <MonitoringDetailPage
                            key={
                                card.mode === 'create'
                                    ? 'create'
                                    : card.mode + ':' + card.id
                            }
                            context={context}
                            permissions={permissions}
                            monitoringId={
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
