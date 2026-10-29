import { Button } from '@apperp/ui/button';
import { FactBox } from '@apperp/ui/fact-box';
import { FieldHint } from '@apperp/ui/field';
import { Link, usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { RecordAttachments } from '@/components/record-attachments';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { apiJson, errorText } from '@/lib/core-api';
import type { Workspace } from '@/types';

type ActivityRow = {
    currency_code: string;
    receipt_count: number | string;
    quantity: number | string;
    completed_value: number | string;
};
type ActivityDocument = Omit<ActivityRow, 'receipt_count'> & {
    receipt_id: string;
    receipt_number: string;
    received_on: string;
};
type Activity = {
    available: boolean;
    message?: string;
    totals?: ActivityRow[];
    documents?: ActivityDocument[];
    generated_at?: string;
};

export function VendorHint({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <FieldHint
            hint={children}
            side="right"
            className="inline-flex shrink-0"
        >
            <button
                type="button"
                aria-label={`Lihat penjelasan ${label.toLowerCase()}`}
                className="text-muted-foreground hover:text-foreground"
            >
                <CircleAlert className="size-4" />
            </button>
        </FieldHint>
    );
}

function money(value: number | string, currency: string) {
    const amount = Number(value);

    try {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency,
        }).format(amount);
    } catch {
        return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 }).format(amount)} ${currency}`;
    }
}

/** Ringkasan dan lampiran memakai vendor tersimpan; tidak menyimpulkan saldo dari feed posting. */
export function VendorContextPanel({
    vendor,
    company,
    children,
    draft = true,
    toolbarContainer,
    className = 'flex-1',
}: {
    vendor: {
        id: string;
        number: string;
        name: string;
        party_id: string;
        tax_number: string | null;
    } | null;
    company: string;
    children: ReactNode;
    draft?: boolean;
    toolbarContainer?: HTMLElement | null;
    className?: string;
}) {
    const formatDateTime = useDateTimeFormat();
    const { workspace } = usePage<{ workspace: Workspace }>().props;
    const activeCompanyId = workspace.active_legal_entity?.id;
    const requestKey = `${vendor?.id ?? ''}:${activeCompanyId ?? ''}`;
    const [activity, setActivity] = useState<{
        requestKey: string;
        data: Activity;
    } | null>(null);
    const [failure, setFailure] = useState<{
        requestKey: string;
        message: string;
    } | null>(null);
    const [refresh, setRefresh] = useState(0);
    const [attachmentState, setAttachmentState] = useState<{
        vendorId: string;
        count: number;
    } | null>(null);
    const vendorId = vendor?.id;
    const attachmentCount =
        attachmentState?.vendorId === vendorId ? attachmentState?.count : null;
    const updateAttachmentCount = useCallback(
        (count: number) => {
            if (vendorId) {
                setAttachmentState({ vendorId, count });
            }
        },
        [vendorId],
    );
    useEffect(() => {
        if (!vendorId) {
            return;
        }

        const controller = new AbortController();
        apiJson<{ data: Activity }>(`/api/v1/vendors/${vendorId}/activity`, {
            signal: controller.signal,
        })
            .then(({ data }) => {
                if (!controller.signal.aborted) {
                    setActivity({ requestKey, data });
                    setFailure(null);
                }
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setFailure({
                        requestKey,
                        message: errorText(
                            caught,
                            'Ringkasan aset belum dapat dimuat.',
                        ),
                    });
                }
            });

        return () => controller.abort();
    }, [vendorId, requestKey, refresh]);
    const current =
        activity?.requestKey === requestKey ? activity?.data : undefined;
    const error =
        failure?.requestKey === requestKey ? failure?.message : undefined;
    const count = (key: 'receipt_count' | 'quantity') =>
        new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 }).format(
            (current?.totals ?? []).reduce(
                (sum, row) => sum + Number(row[key] ?? 0),
                0,
            ),
        );

    return (
        <FactBox
            fillHeight
            className={className}
            toolbarContainer={toolbarContainer}
            attachmentCount={attachmentCount}
            attachments={
                vendor ? (
                    <RecordAttachments
                        key={vendor.id}
                        recordType="vendors"
                        recordId={vendor.id}
                        onCountChange={updateAttachmentCount}
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {draft
                            ? 'Simpan vendor terlebih dahulu untuk menambahkan lampiran.'
                            : 'Pilih vendor untuk melihat lampirannya.'}
                    </p>
                )
            }
            details={
                <div className="space-y-5">
                    <section className="space-y-3">
                        <h3 className="font-medium">Identitas vendor</h3>
                        <dl className="space-y-2 text-sm">
                            {[
                                [
                                    'Nomor vendor',
                                    vendor?.number ??
                                        (draft ? 'Belum disimpan' : '—'),
                                ],
                                ['Nama', vendor?.name ?? '—'],
                                ['Perusahaan', company],
                                ['NPWP', vendor?.tax_number ?? '—'],
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="grid grid-cols-2 gap-2"
                                >
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="min-w-0 break-words">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                    <section className="space-y-3 border-t pt-4">
                        <div className="flex items-center gap-2">
                            <h3 className="font-medium">Perolehan aset</h3>
                            <VendorHint label="perolehan aset">
                                Penerimaan selesai milik vendor dan perusahaan
                                ini yang boleh Anda lihat. Nilai sebelum PPN,
                                dipisahkan per mata uang. Angka ini bukan saldo
                                utang atau nilai buku aset saat ini.
                            </VendorHint>
                        </div>
                        {!vendor ? (
                            <p className="text-sm text-muted-foreground">
                                {draft
                                    ? 'Ringkasan tersedia setelah vendor disimpan.'
                                    : 'Pilih vendor untuk melihat ringkasan asetnya.'}
                            </p>
                        ) : error ? (
                            <div role="alert" className="space-y-2 text-sm">
                                <p className="text-destructive">{error}</p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setRefresh((value) => value + 1)
                                    }
                                >
                                    Muat ulang
                                </Button>
                            </div>
                        ) : !current ? (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Memuat ringkasan aset…
                            </p>
                        ) : !current.available ? (
                            <p className="text-sm text-muted-foreground">
                                {current.message}
                            </p>
                        ) : (
                            <>
                                <p className="text-xs text-muted-foreground">
                                    Dihitung{' '}
                                    {formatDateTime(current.generated_at)}
                                </p>
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-3">
                                        <dt>Dokumen selesai</dt>
                                        <dd>{count('receipt_count')}</dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt>Unit diterima</dt>
                                        <dd>{count('quantity')}</dd>
                                    </div>
                                </dl>
                                {(current.totals ?? []).map((row) => (
                                    <div
                                        key={row.currency_code}
                                        className="space-y-1 text-sm"
                                    >
                                        <p className="text-muted-foreground">
                                            Nilai perolehan ·{' '}
                                            {row.currency_code}
                                        </p>
                                        <p className="font-medium">
                                            {money(
                                                row.completed_value,
                                                row.currency_code,
                                            )}
                                        </p>
                                    </div>
                                ))}
                                {(current.documents ?? []).length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Belum ada penerimaan aset selesai yang
                                        dapat ditampilkan.
                                    </p>
                                ) : (
                                    <div className="space-y-2 border-t pt-3">
                                        <h4 className="text-sm font-medium">
                                            10 penerimaan terakhir
                                        </h4>
                                        {current.documents?.map((row) => (
                                            <div
                                                key={row.receipt_id}
                                                className="space-y-1 text-sm"
                                            >
                                                <Link
                                                    className="text-primary underline"
                                                    href={`/management-aset/inventarisasi-aset/penerimaan/${encodeURIComponent(row.receipt_id)}`}
                                                >
                                                    {row.receipt_number}
                                                </Link>
                                                <p className="text-muted-foreground">
                                                    {row.received_on} ·{' '}
                                                    {money(
                                                        row.completed_value,
                                                        row.currency_code,
                                                    )}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </section>
                    {/* Saldo utang memerlukan buku pembantu/pembayaran; total debit feed bukan saldo vendor. */}
                </div>
            }
        >
            {children}
        </FactBox>
    );
}
