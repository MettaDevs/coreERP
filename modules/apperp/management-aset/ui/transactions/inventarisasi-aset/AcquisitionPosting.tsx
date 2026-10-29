import { usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { lazy, Suspense, useCallback, useState } from 'react';
import type { RefObject } from 'react';
import { PostingCheck } from '@/components/finance/posting-check';
import type { Auth } from '@/types';
import { FormControl } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { useRecordList } from '../../use-record-list';
import type {
    PratinjauPosting,
    StatusPosting,
    VendorRingkas,
} from './penerimaan';

const VendorCreateCard = lazy(() =>
    import('@/pages/foundation/vendor/vendors').then((module) => ({
        default: module.VendorCreateCard,
    })),
);

/**
 * Vendor dan jurnal perolehan pada dokumen penerimaan (feed posting finance, TODO 9.3).
 *
 * Vendor milik Core (K-06): pemilihnya membaca vendor aktif entitas legal dokumen lewat module,
 * dan dokumen hanya menyimpan id-nya. Jurnal perolehan ditampilkan sebelum penerimaan
 * diselesaikan dengan komponen pemeriksaan posting yang sama dengan layar pantau Core (K-22),
 * supaya yang dilihat di sini persis yang akan terbit.
 */

const NO_VENDOR: VendorRingkas = {
    id: '',
    number: '',
    name: 'Tanpa vendor',
    status: '',
};
const labelVendor = (vendor: VendorRingkas) =>
    vendor.id ? vendor.number + ' — ' + vendor.name : vendor.name;

export function VendorPicker({
    legalEntityId,
    value,
    saved,
    readOnly,
    error,
    portal,
    onChange,
}: {
    legalEntityId: string | null;
    value: string;
    saved: VendorRingkas | null;
    readOnly: boolean;
    error?: string;
    portal: RefObject<HTMLDivElement | null>;
    onChange: (vendorId: string) => void;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [creating, setCreating] = useState(false);
    const canCreate =
        auth.membership?.permissions.includes('core.vendor.update') ?? false;
    const [selected, setSelected] = useState<{
        scope: string | null;
        item: VendorRingkas;
    } | null>(null);
    const source = useRecordList<VendorRingkas>(
        '/penerimaan-aset/vendor',
        '',
        { legal_entity_id: legalEntityId ?? '' },
        false,
    );
    const fetchItems = source.loadItems;
    const loadItems = useCallback<LookupLoader<VendorRingkas>>(
        async (request) => {
            if (!legalEntityId) {
                return { items: [], nextCursor: null };
            }

            const batch = await fetchItems({ ...request, sort: null });

            return {
                ...batch,
                items:
                    !request.cursor && !request.query
                        ? [NO_VENDOR, ...batch.items]
                        : batch.items,
            };
        },
        [legalEntityId, fetchItems],
    );
    const current = !value
        ? NO_VENDOR
        : selected?.scope === legalEntityId && selected.item.id === value
          ? selected.item
          : saved?.id === value
            ? saved
            : null;

    return (
        <>
            <FormControl
                error={error}
                hint="Vendor asetnya. Wajib untuk pembelian, kecuali perusahaan ini mencatat perolehan lewat akun perantara."
            >
                {readOnly ? (
                    <Input
                        label="Vendor"
                        readOnly
                        value={current ? labelVendor(current) : ''}
                    />
                ) : (
                    <InlineLookup<VendorRingkas>
                        label="Vendor"
                        value={current}
                        loadItems={loadItems}
                        columns={[
                            {
                                id: 'number',
                                header: 'Nomor',
                                width: 112,
                                cell: (item) => item.number,
                            },
                            {
                                id: 'name',
                                header: 'Nama',
                                width: 240,
                                cell: (item) => item.name,
                            },
                        ]}
                        getItemValue={(item) => item.id}
                        getItemLabel={labelVendor}
                        getSearchText={(item) => item.number + ' ' + item.name}
                        onValueChange={(item) => {
                            setSelected({ scope: legalEntityId, item });
                            onChange(item.id);
                        }}
                        disabled={!legalEntityId}
                        portalContainer={portal}
                        showDetails={false}
                        onCreate={
                            canCreate && legalEntityId
                                ? () => setCreating(true)
                                : undefined
                        }
                        emptyMessage="Vendor tidak ditemukan."
                    />
                )}
            </FormControl>
            {creating && legalEntityId && canCreate && (
                <Suspense fallback={<p role="status">Membuka form vendor…</p>}>
                    <VendorCreateCard
                        key={legalEntityId}
                        legalEntityId={legalEntityId}
                        onClose={() => setCreating(false)}
                        onCreated={(vendor) => {
                            if (
                                vendor.legal_entity_id !== legalEntityId ||
                                vendor.status !== 'active'
                            ) {
                                throw new Error(
                                    'Pilih akun vendor aktif untuk perusahaan penerimaan ini.',
                                );
                            }

                            setSelected({
                                scope: legalEntityId,
                                item: {
                                    id: vendor.id,
                                    number: vendor.number,
                                    name: vendor.name,
                                    status: vendor.status,
                                },
                            });
                            onChange(vendor.id);
                            setCreating(false);
                        }}
                    />
                </Suspense>
            )}
        </>
    );
}

/** Label singkat keadaan jurnal perolehan untuk ringkasan bagian yang tertutup. */
export function labelStatusPosting(status: string | null): string {
    switch (status) {
        case 'pending':
            return 'Siap dikirim';
        case 'held':
            return 'Tertahan';
        case 'manual':
            return 'Tidak dikirim';
        case 'posted':
            return 'Sudah dibukukan';
        case 'rejected':
            return 'Ditolak';
        default:
            return 'Tanpa jurnal';
    }
}

/** Kalimat keadaan jurnal perolehan, dalam bahasa yang dipakai pengguna. */
function keadaan(
    status: string | null,
    selesai: boolean,
    posting?: StatusPosting | null,
    adaPenghalang = false,
): string {
    switch (status) {
        case 'pending':
            return selesai
                ? 'Siap diambil aplikasi finance.'
                : 'Jurnal ini siap dikirim ke aplikasi finance begitu penerimaan diselesaikan.';
        case 'held':
            if (selesai) {
                return 'Tertahan sampai masalah di bawah dibenahi. Setelah dibenahi, owner atau admin menekan Validasi ulang di layar Posting finance.';
            }

            // Selama masih ada penghalang, "tetap dapat diselesaikan" membantah kotak di atasnya.
            return adaPenghalang
                ? 'Jurnal ini akan tertahan sampai masalah di bawah dibenahi.'
                : 'Jurnal ini akan tertahan sampai masalah di bawah dibenahi. Penerimaan tetap dapat diselesaikan.';
        case 'manual':
            return 'Jurnal dicatat tetapi tidak dikirim: pengiriman posting perusahaan ini belum aktif, atau tanggalnya sebelum cutover.';
        case 'posted':
            return posting?.external_reference
                ? `Sudah dibukukan aplikasi finance (${posting.external_reference}).`
                : 'Sudah dibukukan aplikasi finance.';
        case 'rejected':
            return `Ditolak aplikasi finance${posting?.reason ? `: ${posting.reason}` : '.'}`;
        default:
            return 'Tidak ada nilai yang dijurnal.';
    }
}

/**
 * Jurnal perolehan dokumen ini: pratinjau selama draf, keadaannya sesudah diselesaikan.
 */
export function AcquisitionJournal({
    pratinjau,
    memuat,
    selesai,
    posting,
}: {
    pratinjau: PratinjauPosting | null;
    memuat: boolean;
    selesai: boolean;
    posting: StatusPosting | null;
}) {
    if (selesai) {
        return (
            <div className="space-y-3 text-sm">
                <p>{keadaan(posting?.status ?? null, true, posting)}</p>
                {(posting?.problems ?? []).map((masalah, index) => (
                    <p
                        key={`${masalah.code}-${index}`}
                        className="text-destructive"
                    >
                        Baris {masalah.line_no}: {masalah.message}
                    </p>
                ))}
            </div>
        );
    }

    if (memuat && !pratinjau) {
        return (
            <p className="text-muted-foreground text-sm">
                Menyusun pratinjau jurnal…
            </p>
        );
    }

    if (!pratinjau) {
        return (
            <p className="text-muted-foreground text-sm">
                Pratinjau jurnal belum dapat disusun.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            {pratinjau.blockers.length > 0 && (
                <div className="border-destructive/40 bg-destructive/5 space-y-2 rounded-md border px-4 py-3">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <TriangleAlert className="size-4" />
                        Belum dapat diselesaikan
                    </p>
                    {pratinjau.blockers.map((blocker) => (
                        <p key={blocker.field} className="text-sm">
                            {blocker.message}
                        </p>
                    ))}
                </div>
            )}
            <p className="text-sm">
                {keadaan(
                    pratinjau.status,
                    false,
                    null,
                    pratinjau.blockers.length > 0,
                )}
            </p>
            {pratinjau.lines.length > 0 && (
                <PostingCheck
                    lines={pratinjau.lines}
                    problems={pratinjau.problems}
                    currencyCode={pratinjau.currency?.code ?? 'IDR'}
                    currencyDecimals={pratinjau.currency?.decimals ?? 2}
                />
            )}
        </div>
    );
}
