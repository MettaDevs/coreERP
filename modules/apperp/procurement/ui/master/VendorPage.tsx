import { router, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { lazy, Suspense, useCallback, useState } from 'react';
import type { ComponentProps } from 'react';
import type { Auth } from '@/types';
import { ActionButton } from '@apperp/ui/action-button';
import { Alert, AlertDescription } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { DataTableText } from '@apperp/ui/data-table';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';

const VendorCreateCard = lazy(() =>
    import('@/pages/foundation/vendor/vendors').then((module) => ({
        default: module.VendorCreateCard,
    })),
);

type VendorOption = {
    id: string;
    number: string;
    name: string;
    tax_number: string | null;
    status: string;
    legal_entity_id: string;
};

export type VendorPageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
    context?: {
        legal_entity_id: string | null;
        org_unit_id: string | null;
        user_id: string;
    };
    portalContainer?: ComponentProps<typeof InlineLookup>['portalContainer'];
};

const formatVendorLabel = (vendor: VendorOption) =>
    `${vendor.number} — ${vendor.name}`;

export default function VendorPage({
    segments = [],
    context,
    portalContainer,
}: VendorPageProps) {
    const { auth } = usePage<{ auth?: Auth }>().props;
    const corePermissions = auth?.membership?.permissions ?? [];
    const canReadVendor = corePermissions.includes('core.vendor.read');
    const canUpdateVendor = corePermissions.includes('core.vendor.update');
    const legalEntityId = context?.legal_entity_id ?? null;
    const canCreateVendor = Boolean(canUpdateVendor && legalEntityId);

    const [creating, setCreating] = useState(false);
    const [selectedState, setSelectedState] = useState<{
        legalEntityId: string | null;
        vendor: VendorOption;
    } | null>(null);
    const [inferredPortal, setInferredPortal] = useState<HTMLElement | null>(
        null,
    );

    const attachRoot = useCallback((node: HTMLDivElement | null) => {
        setInferredPortal(
            node?.closest<HTMLElement>(
                '[data-slot="page-dialog-contents"], [data-slot="dialog-content"], [data-slot="sheet-content"]',
            ) ?? null,
        );
    }, []);

    const selectedVendor =
        selectedState && selectedState.legalEntityId === legalEntityId
            ? selectedState.vendor
            : null;
    const routeVendorId = segments[0]?.trim() || null;
    const targetVendorId = selectedVendor?.id ?? routeVendorId;

    const loadVendors = useCallback<LookupLoader<VendorOption>>(
        async ({ query, cursor, signal }) => {
            if (!legalEntityId || !canReadVendor) {
                return { items: [], nextCursor: null };
            }

            const params = new URLSearchParams({
                legal_entity_id: legalEntityId,
            });

            if (query.trim() !== '') {
                params.set('q', query.trim());
            }

            if (cursor) {
                params.set('cursor', cursor);
            }

            const response = await fetch(
                `/api/v1/vendors/lookup?${params.toString()}`,
                {
                    credentials: 'same-origin',
                    signal,
                    headers: { Accept: 'application/json' },
                },
            );

            if (!response.ok) {
                throw new Error('Daftar vendor belum dapat dimuat.');
            }

            const payload = (await response.json()) as {
                data?: VendorOption[];
                next_cursor?: string | null;
            };

            return {
                items: Array.isArray(payload.data) ? payload.data : [],
                nextCursor: payload.next_cursor ?? null,
            };
        },
        [canReadVendor, legalEntityId],
    );

    const openVendorRecordCard = (vendorId: string) => {
        if (!canReadVendor || !vendorId) {
            return;
        }

        router.visit(
            `/settings/vendors?vendor=${encodeURIComponent(vendorId)}`,
        );
    };

    const openVendorDirectory = () => {
        if (!canReadVendor) {
            return;
        }

        router.visit('/settings/vendors');
    };

    return (
        <div ref={attachRoot} className="p-6">
            <Card>
                <CardHeader>
                    <CardTitle>Vendor</CardTitle>
                    <CardAction>
                        {canCreateVendor && (
                            <ActionButton
                                action="create"
                                onClick={() => setCreating(true)}
                            />
                        )}
                        {canReadVendor && (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={!targetVendorId}
                                    onClick={() => {
                                        if (targetVendorId) {
                                            openVendorRecordCard(
                                                targetVendorId,
                                            );
                                        }
                                    }}
                                >
                                    Buka kartu vendor
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={openVendorDirectory}
                                >
                                    <ExternalLink className="mr-2 size-4" />
                                    Buka daftar vendor
                                </Button>
                            </>
                        )}
                    </CardAction>
                </CardHeader>
                <CardContent className="space-y-4">
                    {!legalEntityId && (
                        <Alert>
                            <AlertDescription>
                                Pilih perusahaan aktif terlebih dahulu untuk
                                mencari atau menambahkan vendor.
                            </AlertDescription>
                        </Alert>
                    )}

                    {!canReadVendor && (
                        <Alert>
                            <AlertDescription>
                                Akun Anda belum memiliki izin melihat master
                                vendor. Hubungi administrator untuk membuka
                                akses vendor perusahaan.
                            </AlertDescription>
                        </Alert>
                    )}

                    {canReadVendor && (
                        <div className="max-w-xl space-y-3">
                            <InlineLookup<VendorOption>
                                label="Pilih vendor"
                                placeholder="Cari nomor atau nama vendor…"
                                emptyMessage="Vendor tidak ditemukan untuk perusahaan ini."
                                value={selectedVendor}
                                loadItems={loadVendors}
                                disabled={!legalEntityId}
                                portalContainer={
                                    portalContainer ?? inferredPortal
                                }
                                columns={[
                                    {
                                        id: 'number',
                                        header: 'Nomor vendor',
                                        width: 140,
                                        cell: (item) => (
                                            <DataTableText>
                                                {item.number}
                                            </DataTableText>
                                        ),
                                    },
                                    {
                                        id: 'name',
                                        header: 'Nama',
                                        width: 260,
                                        cell: (item) => (
                                            <DataTableText>
                                                {item.name}
                                            </DataTableText>
                                        ),
                                    },
                                ]}
                                getItemValue={(item) => item.id}
                                getItemLabel={formatVendorLabel}
                                getSearchText={(item) =>
                                    `${item.number} ${item.name} ${item.tax_number ?? ''}`
                                }
                                onValueChange={(item) =>
                                    setSelectedState({
                                        legalEntityId,
                                        vendor: item,
                                    })
                                }
                                onCreate={
                                    canCreateVendor
                                        ? () => setCreating(true)
                                        : undefined
                                }
                                onDetails={(item) =>
                                    openVendorRecordCard(item.id)
                                }
                                onFullList={openVendorDirectory}
                            />

                            {selectedVendor && (
                                <p className="text-muted-foreground text-sm">
                                    Vendor terpilih:{' '}
                                    <span className="text-foreground font-medium">
                                        {formatVendorLabel(selectedVendor)}
                                    </span>
                                </p>
                            )}

                            {!selectedVendor && routeVendorId && (
                                <p className="text-muted-foreground text-sm">
                                    Target vendor dari tautan siap dibuka pada
                                    kartu vendor.
                                </p>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            {creating && canCreateVendor && legalEntityId && (
                <Suspense
                    fallback={
                        <p
                            role="status"
                            className="text-muted-foreground px-6 pt-2 text-sm"
                        >
                            Menyiapkan kartu vendor…
                        </p>
                    }
                >
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
                                    'Pilih vendor aktif untuk perusahaan ini.',
                                );
                            }

                            setSelectedState({
                                legalEntityId,
                                vendor: {
                                    id: vendor.id,
                                    number: vendor.number,
                                    name: vendor.name,
                                    tax_number: vendor.tax_number ?? null,
                                    status: vendor.status,
                                    legal_entity_id: vendor.legal_entity_id,
                                },
                            });
                            setCreating(false);
                        }}
                    />
                </Suspense>
            )}
        </div>
    );
}
