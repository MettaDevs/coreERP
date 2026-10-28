import { ActionButton } from '@apperp/ui/action-button';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@apperp/ui/alert-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@apperp/ui/avatar';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DialogAction } from '@apperp/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    FieldDescription,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import {
    PageDialog,
    PageDialogContent,
    PageDialogBody,
    PageDialogToolbar,
    PageDialogClose,
    PageDialogTrigger,
} from '@apperp/ui/page-dialog';
import { RadioGroup, RadioGroupItem } from '@apperp/ui/radio-group';
import { Select } from '@apperp/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListRequest } from '@apperp/ui/use-incremental-list';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { Deferred, Head, router, useForm, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Ban, Check, Copy, Pencil, Users } from 'lucide-react';
import type { RefObject } from 'react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';
import { FormErrorHeader } from '@/components/form-error-header';
import Heading from '@/components/heading';
import {
    useInertiaFeedback,
    withInertiaErrors,
} from '@/hooks/use-inertia-feedback';
import { useInitials } from '@/hooks/use-initials';

const accessLevelLabels: Record<string, string> = {
    read: 'Lihat',
    create: 'Buat',
    update: 'Ubah',
    delete: 'Delete',
    correct: 'Koreksi',
    invoke: 'Jalankan',
};

type Permission = {
    code: string;
    name: string;
    access_level: string;
};
type Privilege = { code: string; name: string; permissions: Permission[] };
type Duty = {
    code: string;
    app_id: string | null;
    name: string;
    privileges?: Privilege[];
};
type App = { id: string; name: string; duties: Duty[] };
type Role = {
    id: string;
    name: string;
    duties: Duty[];
    data_policy_codes: string[];
};
type Organization = {
    id: string;
    name: string;
    classification: string;
    unit_type: string | null;
};
type HierarchyNode = {
    organization_id: string;
    parent_organization_id: string | null;
};
type Hierarchy = {
    id: string;
    name: string;
    version_id: string | null;
    nodes: HierarchyNode[];
};
type DataPolicy = {
    code: string;
    name: string;
    requires_legal_entity: boolean;
    requires_operating_unit: boolean;
    allows_descendants: boolean;
};
type PolicyScope = {
    policy_code: string;
    legal_entity_id: string | null;
    organization_id: string | null;
    hierarchy_id: string | null;
    include_descendants: boolean;
    /** Grant tanpa dimensi apa pun: seluruh organisasi pada policy ini. */
    unrestricted: boolean;
};
type Assignment = {
    role_id: string;
    role_name?: string;
    source?: 'manual' | 'automatic';
    policy_scopes: PolicyScope[];
};
type Member = {
    id: string;
    name: string;
    email: string;
    platform_role?: string;
    security_role?: string;
    avatar_url?: string | null;
    roles: string[];
    assignments: Assignment[];
    can_edit_access: boolean;
    version: number;
    /** Pekerja di aplikasi tenaga kerja yang tertaut ke akun ini, bila ada. */
    worker: { name: string; personnel_number: string } | null;
};
type Invitation = {
    id: string;
    version: number;
    label: string | null;
    roles: string[];
    assignments: Assignment[];
    redeemed_count: number;
    code: string | null;
    revoked_at: string | null;
    /** Terisi untuk undangan yang terikat ke satu akun SSO; null untuk kode anonim. */
    sso: {
        email: string;
        name: string | null;
        notified_at: string | null;
        redeemed_at: string | null;
    } | null;
};
type Props = {
    tenant: { id: string; name: string };
    canManage: boolean;
    members: Member[];
    membersNextCursor: string | null;
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    invitations: Invitation[];
    invitationsNextCursor: string | null;
    newInvitationCodes: string[];
    /** Tenant ini memakai SSO bersama, sehingga undangan lewat email mungkin dibuat. */
    ssoAvailable: boolean;
    /** Aplikasi yang mencatat pekerja terpasang, sehingga kolom pekerja punya arti. */
    workersAvailable: boolean;
    /*
     * Dua prop berikut dikirim `Inertia::defer()`: ia **tidak ada** pada respons pertama dan
     * tiba pada permintaan susulan yang dikirim Inertia sendiri sesudah halaman tercat.
     * Karena itu tipenya opsional — bukan karena backend kadang tidak punya, melainkan karena
     * memang ada satu jendela waktu ketika halaman hidup tanpa keduanya. Keduanya hanya dibaca
     * di dalam dialog, jadi jendela itu tidak terlihat siapa pun.
     */
    apps?: App[];
    hierarchies?: Hierarchy[];
};

async function accessBatch<T>(
    kind: 'members' | 'invitations',
    input: Partial<ListRequest> & { recordId?: string },
) {
    const params = new URLSearchParams({ list: kind });

    if (input.query) {
        params.set('q', input.query);
    }

    if (input.cursor) {
        params.set('cursor', input.cursor);
    }

    if (input.sort) {
        params.set('sort', input.sort.columnId);
        params.set('direction', input.sort.direction);
    }

    if (input.recordId) {
        params.set('record_id', input.recordId);
    }

    const response = await fetch(`/settings/access?${params}`, {
        signal: input.signal,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error('Daftar akses belum dapat dimuat. Coba lagi.');
    }

    const batch = (await response.json()) as {
        data: T[];
        next_cursor: string | null;
    };

    return { items: batch.data, nextCursor: batch.next_cursor };
}

const unrestrictedScope = (policyCode: string): PolicyScope => ({
    policy_code: policyCode,
    legal_entity_id: null,
    organization_id: null,
    hierarchy_id: null,
    include_descendants: false,
    unrestricted: true,
});

const grantKey = (scope: PolicyScope): string =>
    [
        scope.legal_entity_id ?? '',
        scope.organization_id ?? '',
        scope.hierarchy_id ?? '',
        scope.include_descendants ? '1' : '0',
    ].join('|');

type TreeRow = { organization: Organization; depth: number };

/**
 * Susunan organisasi menentukan bentuk pohon, jadi ia dipilih lebih dulu dan
 * daftar node mengikutinya. Urutan ini membuat kombinasi unit-di-luar-susunan
 * mustahil dibentuk, bukan sekadar ditolak backend setelah disimpan.
 */
function hierarchyRows(
    hierarchy: Hierarchy | undefined,
    organizations: Organization[],
): TreeRow[] {
    const byId = new Map(
        organizations.map((organization) => [organization.id, organization]),
    );

    if (!hierarchy) {
        return organizations
            .filter(
                (organization) =>
                    organization.classification === 'operating_unit',
            )
            .map((organization) => ({ organization, depth: 0 }));
    }

    const children = new Map<string | null, string[]>();

    for (const node of hierarchy.nodes) {
        children.set(node.parent_organization_id, [
            ...(children.get(node.parent_organization_id) ?? []),
            node.organization_id,
        ]);
    }

    const rows: TreeRow[] = [];
    const walk = (parent: string | null, depth: number) => {
        for (const id of children.get(parent) ?? []) {
            const organization = byId.get(id);

            if (organization) {
                rows.push({ organization, depth });
            }

            walk(id, depth + 1);
        }
    };
    walk(null, 0);

    return rows;
}

/**
 * Satu policy, satu panel. Bentuknya mengikuti layar `Assign organizations`
 * Dynamics 365: pilih cakupan, pilih susunan, tunjuk node, lalu beri akses —
 * hasilnya menumpuk sebagai baris grant yang dapat dibaca utuh.
 *
 * `Revoke` sengaja tidak ditiru. Pengecualian harus menjadi policy tersendiri,
 * bukan daftar deny yang menabrak policy lain.
 */
function PolicyScopePanel({
    policy,
    scopes,
    organizations,
    hierarchies,
    portalContainer,
    onChange,
}: {
    policy: DataPolicy;
    scopes: PolicyScope[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (scopes: PolicyScope[]) => void;
}) {
    const grants = scopes.filter((scope) => !scope.unrestricted);
    const unrestricted = scopes.length > 0 && grants.length === 0;
    const [legalEntityId, setLegalEntityId] = useState<string | null>(null);
    const [hierarchyId, setHierarchyId] = useState<string | null>(null);
    const [organizationId, setOrganizationId] = useState<string | null>(null);
    const [filter, setFilter] = useState('');

    const organizationById = new Map(
        organizations.map((organization) => [organization.id, organization]),
    );
    const hierarchyById = new Map(
        hierarchies.map((hierarchy) => [hierarchy.id, hierarchy]),
    );
    const query = filter.trim().toLowerCase();
    const rows = hierarchyRows(
        hierarchyId ? hierarchyById.get(hierarchyId) : undefined,
        organizations,
    ).filter(({ organization }) =>
        organization.name.toLowerCase().includes(query),
    );
    const selectable = (organization: Organization) =>
        !policy.requires_operating_unit ||
        organization.classification === 'operating_unit';
    const legalEntityReady =
        !policy.requires_legal_entity || legalEntityId !== null;
    const canGrant = organizationId !== null && legalEntityReady;

    const grant = (withChildren: boolean) => {
        if (!organizationId) {
            return;
        }

        const next: PolicyScope = {
            policy_code: policy.code,
            legal_entity_id: policy.requires_legal_entity
                ? legalEntityId
                : null,
            organization_id: organizationId,
            hierarchy_id: withChildren ? hierarchyId : null,
            include_descendants: withChildren,
            unrestricted: false,
        };

        if (grants.some((scope) => grantKey(scope) === grantKey(next))) {
            toast('Batas data yang sama sudah ditambahkan.');

            return;
        }

        if (
            grants.some(
                (scope) =>
                    scope.legal_entity_id === next.legal_entity_id &&
                    scope.organization_id === next.organization_id &&
                    scope.include_descendants !== next.include_descendants,
            )
        ) {
            toast(
                'Unit ini sudah tercakup dalam akses beserta turunannya. Hapus salah satu batas data.',
            );

            return;
        }

        onChange([...grants, next]);
    };

    return (
        <div className="space-y-4">
            <RadioGroup
                value={unrestricted ? 'all' : 'specific'}
                onValueChange={(value) =>
                    onChange(
                        value === 'all' ? [unrestrictedScope(policy.code)] : [],
                    )
                }
            >
                <label className="flex items-center gap-3 text-sm">
                    <RadioGroupItem value="all" />
                    Seluruh organisasi
                </label>
                <label className="flex items-center gap-3 text-sm">
                    <RadioGroupItem value="specific" />
                    Organisasi tertentu
                </label>
            </RadioGroup>
            {unrestricted ? (
                <FieldDescription>
                    Role ini menjangkau semua data yang dilindungi{' '}
                    {policy.name.toLowerCase()}, tanpa batas organisasi.
                </FieldDescription>
            ) : (
                <>
                    <FormGrid>
                        {policy.requires_legal_entity && (
                            <FormControl>
                                <Select
                                    label="Badan hukum"
                                    items={organizations
                                        .filter(
                                            (organization) =>
                                                organization.classification ===
                                                'legal_entity',
                                        )
                                        .map((organization) => ({
                                            value: organization.id,
                                            label: organization.name,
                                        }))}
                                    value={legalEntityId}
                                    onValueChange={setLegalEntityId}
                                    placeholder="Pilih badan hukum"
                                    searchPlaceholder="Cari badan hukum..."
                                    portalContainer={portalContainer}
                                />
                            </FormControl>
                        )}
                        <FormControl hint="Menentukan bentuk pohon dan unit yang termasuk ketika turunannya dipilih.">
                            <Select
                                label="Susunan organisasi"
                                items={hierarchies.map((hierarchy) => ({
                                    value: hierarchy.id,
                                    label: hierarchy.name,
                                }))}
                                value={hierarchyId}
                                onValueChange={(value) => {
                                    setHierarchyId(value);
                                    setOrganizationId(null);
                                }}
                                placeholder="(Semua unit kerja)"
                                searchPlaceholder="Cari susunan organisasi..."
                                portalContainer={portalContainer}
                            />
                        </FormControl>
                    </FormGrid>
                    <div className="space-y-2">
                        <Input
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                            placeholder="Cari organisasi..."
                        />
                        <div className="max-h-64 overflow-auto rounded-md border">
                            {rows.length === 0 ? (
                                <p className="p-3 text-sm text-muted-foreground">
                                    Tidak ada organisasi yang cocok.
                                </p>
                            ) : (
                                rows.map(({ organization, depth }) => (
                                    <button
                                        key={organization.id}
                                        type="button"
                                        disabled={!selectable(organization)}
                                        onClick={() =>
                                            setOrganizationId(organization.id)
                                        }
                                        data-selected={
                                            organizationId === organization.id
                                                ? ''
                                                : undefined
                                        }
                                        style={{
                                            paddingLeft: `${depth * 16 + 12}px`,
                                        }}
                                        className="flex w-full items-center gap-2 py-1.5 pr-3 text-left text-sm hover:bg-accent/40 disabled:cursor-default disabled:text-muted-foreground disabled:hover:bg-transparent data-selected:bg-accent"
                                    >
                                        {organization.name}
                                        {organization.classification ===
                                            'legal_entity' && (
                                            <Badge variant="outline">
                                                Badan hukum
                                            </Badge>
                                        )}
                                    </button>
                                ))
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => grant(false)}
                                disabled={!canGrant}
                            >
                                Beri akses
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => grant(true)}
                                disabled={
                                    !canGrant ||
                                    !policy.allows_descendants ||
                                    hierarchyId === null
                                }
                            >
                                Beri akses beserta turunannya
                            </Button>
                        </div>
                        {policy.requires_legal_entity && !legalEntityReady && (
                            <FieldDescription>
                                Pilih badan hukum lebih dulu — grant policy ini
                                selalu berupa pasangan badan hukum dan unit
                                kerja.
                            </FieldDescription>
                        )}
                    </div>
                    <DataTable<PolicyScope>
                        columns={[
                            ...(policy.requires_legal_entity
                                ? [
                                      {
                                          id: 'entity',
                                          header: 'Badan hukum',
                                          cell: (scope: PolicyScope) =>
                                              (scope.legal_entity_id &&
                                                  organizationById.get(
                                                      scope.legal_entity_id,
                                                  )?.name) ||
                                              '—',
                                      },
                                  ]
                                : []),
                            {
                                id: 'unit',
                                header: 'Unit kerja',
                                cell: (scope) =>
                                    (scope.organization_id &&
                                        organizationById.get(
                                            scope.organization_id,
                                        )?.name) ||
                                    '—',
                            },
                            {
                                id: 'type',
                                header: 'Jenis unit',
                                cell: (scope) =>
                                    (scope.organization_id &&
                                        organizationById.get(
                                            scope.organization_id,
                                        )?.unit_type) ||
                                    '—',
                            },
                            {
                                id: 'descendants',
                                header: 'Turunan',
                                cell: (scope) =>
                                    scope.include_descendants
                                        ? 'Termasuk'
                                        : 'Tidak',
                            },
                            {
                                id: 'hierarchy',
                                header: 'Susunan organisasi',
                                cell: (scope) =>
                                    (scope.hierarchy_id &&
                                        hierarchyById.get(scope.hierarchy_id)
                                            ?.name) ||
                                    '—',
                            },
                        ]}
                        data={grants}
                        getRowKey={grantKey}
                        actions={[
                            {
                                id: 'delete',
                                label: 'Delete',
                                destructive: true,
                                icon: <Trash2 />,
                            },
                        ]}
                        actionsPlacement="inline"
                        onRowAction={(_, scope) =>
                            onChange(
                                grants.filter(
                                    (item) =>
                                        grantKey(item) !== grantKey(scope),
                                ),
                            )
                        }
                        emptyMessage="Belum ada organisasi yang diberi akses."
                    />
                </>
            )}
        </div>
    );
}

/**
 * Satu tab per data policy yang dipakai role. Policy dipisah karena dimensi
 * wajibnya berbeda dan karena grant satu policy tidak pernah memperluas policy
 * lain — menggabungkannya dalam satu daftar akan menyiratkan sebaliknya.
 */
function ScopeEditor({
    role,
    scopes,
    policies,
    organizations,
    hierarchies,
    portalContainer,
    onChange,
}: {
    role: Role;
    scopes: PolicyScope[];
    policies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (scopes: PolicyScope[]) => void;
}) {
    const rolePolicies = policies.filter((policy) =>
        role.data_policy_codes.includes(policy.code),
    );
    const [active, setActive] = useState(rolePolicies[0]?.code ?? '');

    if (rolePolicies.length === 0) {
        return null;
    }

    const panel = (policy: DataPolicy) => (
        <PolicyScopePanel
            policy={policy}
            scopes={scopes.filter((scope) => scope.policy_code === policy.code)}
            organizations={organizations}
            hierarchies={hierarchies}
            portalContainer={portalContainer}
            onChange={(next) =>
                onChange([
                    ...scopes.filter(
                        (scope) => scope.policy_code !== policy.code,
                    ),
                    ...next,
                ])
            }
        />
    );

    if (rolePolicies.length === 1) {
        return (
            <FieldSet>
                <FieldLegend>{rolePolicies[0].name}</FieldLegend>
                {panel(rolePolicies[0])}
            </FieldSet>
        );
    }

    return (
        <Tabs value={active} onValueChange={setActive}>
            <TabsList>
                {rolePolicies.map((policy) => (
                    <TabsTrigger key={policy.code} value={policy.code}>
                        {policy.name}
                    </TabsTrigger>
                ))}
            </TabsList>
            {rolePolicies.map((policy) => (
                <TabsContent
                    key={policy.code}
                    value={policy.code}
                    className="pt-4"
                >
                    {panel(policy)}
                </TabsContent>
            ))}
        </Tabs>
    );
}

const scopeSummary = (role: Role, scopes: PolicyScope[]): string => {
    if (role.data_policy_codes.length === 0) {
        return 'Tidak dibatasi';
    }

    if (scopes.length === 0) {
        return 'Belum diatur';
    }

    const grants = scopes.filter((scope) => !scope.unrestricted);

    return grants.length === 0
        ? 'Seluruh organisasi'
        : `${grants.length} batas`;
};

/**
 * Batas data tidak muat dalam satu sel grid, jadi sel itu hanya meringkas dan
 * membuka dialog — pola yang sama dipakai grid anggota dan grid kode undangan.
 */
function RoleScopeDialog({
    role,
    scopes,
    policies,
    organizations,
    hierarchies,
    onChange,
}: {
    role: Role;
    scopes: PolicyScope[];
    policies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    onChange: (scopes: PolicyScope[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<PolicyScope[]>(scopes);
    const scoped = role.data_policy_codes.length > 0;

    const transition = useRecordTransition(
        JSON.stringify(draft) !== JSON.stringify(scopes),
        false,
    );

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    transition.request(() => setOpen(false));

                    return;
                }

                setOpen(true);

                if (next) {
                    setDraft(scopes);
                }
            }}
        >
            <PageDialogTrigger asChild>
                <button
                    type="button"
                    disabled={!scoped}
                    className="text-left text-sm underline-offset-2 hover:underline disabled:cursor-default disabled:text-muted-foreground disabled:no-underline"
                >
                    {scopeSummary(role, scopes)}
                    {scoped && <span className="ml-1">…</span>}
                </button>
            </PageDialogTrigger>
            <PageDialogContent
                ref={contentRef}
                title="Batas data"
                recordTitle={role.name}
                description="Tentukan organisasi yang datanya boleh digunakan. Setiap batas data berlaku secara terpisah."
            >
                <PageDialogToolbar>
                    <DialogAction
                        type="button"
                        onClick={() => {
                            onChange(draft);
                            setOpen(false);
                        }}
                    >
                        Terapkan
                    </DialogAction>
                    <PageDialogClose asChild>
                        <ActionButton action="cancel" type="button" />
                    </PageDialogClose>
                </PageDialogToolbar>
                <PageDialogBody>
                    <ScopeEditor
                        role={role}
                        scopes={draft}
                        policies={policies}
                        organizations={organizations}
                        hierarchies={hierarchies}
                        portalContainer={contentRef}
                        onChange={setDraft}
                    />
                </PageDialogBody>
            </PageDialogContent>
            {transition.confirmation}
        </PageDialog>
    );
}

function AssignmentPicker({
    assignments,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    disabledRoleIds = [],
    onChange,
}: {
    assignments: Assignment[];
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    disabledRoleIds?: string[];
    onChange: (assignments: Assignment[]) => void;
}) {
    const assignmentFor = (roleId: string) =>
        assignments.find((assignment) => assignment.role_id === roleId);
    const updateScopes = (roleId: string, policyScopes: PolicyScope[]) =>
        onChange(
            assignments.map((assignment) =>
                assignment.role_id === roleId
                    ? { ...assignment, policy_scopes: policyScopes }
                    : assignment,
            ),
        );

    const columns: DataTableColumn<Role>[] = [
        {
            id: 'selected',
            header: 'Pilih',
            width: 64,
            content: 'control',
            cell: (role) => {
                const automatic = disabledRoleIds.includes(role.id);

                return (
                    <Checkbox
                        aria-label={`Berikan role ${role.name}`}
                        checked={Boolean(assignmentFor(role.id)) || automatic}
                        disabled={automatic}
                        onCheckedChange={(value) =>
                            onChange(
                                value === true
                                    ? [
                                          ...assignments,
                                          {
                                              role_id: role.id,
                                              policy_scopes: [],
                                          },
                                      ]
                                    : assignments.filter(
                                          (assignment) =>
                                              assignment.role_id !== role.id,
                                      ),
                            )
                        }
                    />
                );
            },
        },
        { id: 'name', header: 'Role', cell: (role) => role.name },
        {
            id: 'duties',
            header: 'Tanggung jawab',
            width: 180,
            cell: (role) => `${role.duties.length} tanggung jawab`,
        },
        {
            id: 'scope',
            header: 'Batas data',
            cell: (role) =>
                assignmentFor(role.id) && !disabledRoleIds.includes(role.id) ? (
                    <RoleScopeDialog
                        role={role}
                        scopes={assignmentFor(role.id)?.policy_scopes ?? []}
                        policies={dataPolicies}
                        organizations={organizations}
                        hierarchies={hierarchies}
                        onChange={(scopes) => updateScopes(role.id, scopes)}
                    />
                ) : role.data_policy_codes.length > 0 ? (
                    `${role.data_policy_codes.length} kebijakan`
                ) : (
                    'Tidak dibatasi'
                ),
        },
        {
            id: 'source',
            header: 'Sumber',
            width: 180,
            cell: (role) =>
                disabledRoleIds.includes(role.id) ? (
                    <Badge variant="outline">Aturan otomatis</Badge>
                ) : (
                    'Manual'
                ),
        },
    ];

    return (
        <FieldSet>
            <FieldLegend>Role anggota</FieldLegend>
            <FieldDescription>
                Centang role yang diperlukan, lalu atur batas datanya. Role dari
                aturan otomatis hanya dapat diubah melalui aturan asalnya.
            </FieldDescription>
            <DataTable
                columns={columns}
                data={roles}
                getRowKey={(role) => role.id}
                emptyMessage="Belum ada role yang dapat diberikan."
            />
        </FieldSet>
    );
}

type CodeDraft = {
    key: string;
    label: string;
    /**
     * Email orang yang diundang. Kosong berarti kode anonim yang dapat dipakai siapa pun yang
     * memegangnya — perilaku yang sudah ada sebelum undangan SSO, dan yang sengaja dipertahankan.
     */
    sso_email: string;
    assignments: Assignment[];
    /**
     * Terisi untuk kode yang sudah diterbitkan. Baris itu tetap dapat diubah —
     * kodenya tidak berubah, yang berubah hanya apa yang diterima penukar
     * berikutnya. `redeemed` dipakai untuk memperingatkan bahwa kode sudah
     * beredar sebelum perubahan disimpan.
     */
    issued?: {
        id: string;
        version: number;
        revoked: boolean;
        redeemed: number;
        sso: Invitation['sso'];
    };
};

const issuedDraft = (invitation: Invitation): CodeDraft => ({
    key: `issued-${invitation.id}`,
    label: invitation.label ?? '',
    sso_email: invitation.sso?.email ?? '',
    assignments: invitation.assignments,
    issued: {
        id: invitation.id,
        version: invitation.version,
        revoked: Boolean(invitation.revoked_at),
        redeemed: invitation.redeemed_count,
        sso: invitation.sso,
    },
});

/**
 * Sidik jari isi baris, dipakai untuk mengetahui baris terbit mana yang sudah
 * diubah. Role dan grant diurutkan lebih dulu supaya perubahan urutan pilihan
 * tidak terbaca sebagai perubahan isi.
 */
const codeFingerprint = (code: CodeDraft): string =>
    JSON.stringify({
        label: code.label,
        assignments: [...code.assignments]
            .sort((first, second) =>
                first.role_id.localeCompare(second.role_id),
            )
            .map((assignment) => ({
                role_id: assignment.role_id,
                scopes: assignment.policy_scopes
                    .map(
                        (scope) =>
                            `${scope.policy_code}|${grantKey(scope)}|${scope.unrestricted ? '1' : '0'}`,
                    )
                    .sort(),
            })),
    });

/** Ringkasan batas data seluruh role pada satu kode undangan. */
const codeScopeSummary = (roles: Role[], assignments: Assignment[]): string => {
    const scoped = roles.filter(
        (role) =>
            assignments.some((assignment) => assignment.role_id === role.id) &&
            role.data_policy_codes.length > 0,
    );

    if (scoped.length === 0) {
        return 'Tidak dibatasi';
    }

    const scopes = assignments.flatMap(
        (assignment) => assignment.policy_scopes,
    );
    const grants = scopes.filter((scope) => !scope.unrestricted);

    if (grants.length > 0) {
        return `${grants.length} batas`;
    }

    return scopes.length > 0 ? 'Seluruh organisasi' : 'Belum diatur';
};

const blankCode = (): CodeDraft => ({
    key: crypto.randomUUID(),
    label: '',
    sso_email: '',
    assignments: [],
});

/**
 * Batas data terlalu bercabang untuk muat dalam satu sel, jadi ia dibuka
 * sebagai dialog kedua di atas grid — lapisan di bawahnya otomatis mundur dan
 * mengabur, sehingga jelas pengguna sedang satu lapis lebih dalam.
 */
function ScopeDialog({
    code,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    onChange,
    disabled = false,
}: {
    code: CodeDraft;
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    onChange: (assignments: Assignment[]) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<Assignment[]>(code.assignments);
    const scopedRoles = roles.filter(
        (role) =>
            code.assignments.some(
                (assignment) => assignment.role_id === role.id,
            ) && role.data_policy_codes.length > 0,
    );

    const transition = useRecordTransition(
        JSON.stringify(draft) !== JSON.stringify(code.assignments),
        false,
    );

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (disabled) {
                    return;
                }

                if (!next) {
                    transition.request(() => setOpen(false));

                    return;
                }

                setOpen(true);

                if (next) {
                    setDraft(code.assignments);
                }
            }}
        >
            <PageDialogTrigger asChild>
                <button
                    type="button"
                    disabled={disabled || scopedRoles.length === 0}
                    className="w-full text-left text-sm underline-offset-2 hover:underline disabled:cursor-default disabled:text-muted-foreground disabled:no-underline"
                >
                    {codeScopeSummary(roles, code.assignments)}
                    {scopedRoles.length > 0 && <span className="ml-1">…</span>}
                </button>
            </PageDialogTrigger>
            <PageDialogContent
                ref={contentRef}
                title="Batas data kode undangan"
                recordTitle="Kode undangan"
                description="Tentukan organisasi yang datanya boleh digunakan. Setiap batas data berlaku secara terpisah."
            >
                <PageDialogToolbar>
                    <DialogAction
                        type="button"
                        disabled={disabled}
                        onClick={() => {
                            if (disabled) {
                                return;
                            }

                            onChange(draft);
                            setOpen(false);
                        }}
                    >
                        Terapkan
                    </DialogAction>
                    <PageDialogClose asChild>
                        <ActionButton action="cancel" type="button" />
                    </PageDialogClose>
                </PageDialogToolbar>
                <PageDialogBody>
                    <FieldGroup>
                        {scopedRoles.map((role) => {
                            const assignment = draft.find(
                                (item) => item.role_id === role.id,
                            );

                            return assignment ? (
                                <div key={role.id} className="space-y-3">
                                    <p className="text-sm font-medium">
                                        {role.name}
                                    </p>
                                    <ScopeEditor
                                        role={role}
                                        scopes={assignment.policy_scopes}
                                        policies={dataPolicies}
                                        organizations={organizations}
                                        hierarchies={hierarchies}
                                        portalContainer={contentRef}
                                        onChange={(next) =>
                                            !disabled &&
                                            setDraft((current) =>
                                                current.map((item) =>
                                                    item.role_id === role.id
                                                        ? {
                                                              ...item,
                                                              policy_scopes:
                                                                  next,
                                                          }
                                                        : item,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            ) : null;
                        })}
                    </FieldGroup>
                </PageDialogBody>
            </PageDialogContent>
            {transition.confirmation}
        </PageDialog>
    );
}

/**
 * Satu baris grid = satu kode undangan. Kolom kiri diisi pengguna, kolom kanan
 * dihitung dari role yang dipilih — pola Edit List Business Central.
 *
 * Kode yang sudah diterbitkan ikut tampil di atas baris baru supaya grid ini
 * memperlihatkan daftar yang sama dengan tabel di halaman. Baris tersebut
 * terkunci: mencabut kode dilakukan dari tabel halaman, bukan dari sini.
 */
function mergeIssuedRows(
    current: CodeDraft[],
    incoming: Invitation[],
    pristine: Map<string, string>,
    refreshedKey?: string,
) {
    const fingerprints = new Map(pristine);
    const latest = new Map(
        incoming.map((invitation) => {
            const row = issuedDraft(invitation);

            return [row.key, row];
        }),
    );
    const rows = current.map((row) => {
        const next = latest.get(row.key);
        latest.delete(row.key);

        if (
            next?.issued &&
            row.issued &&
            next.issued.version < row.issued.version
        ) {
            return row;
        }

        if (
            next &&
            (row.key === refreshedKey ||
                pristine.get(row.key) === codeFingerprint(row))
        ) {
            fingerprints.set(row.key, codeFingerprint(next));

            return next;
        }

        return row;
    });

    for (const row of latest.values()) {
        fingerprints.set(row.key, codeFingerprint(row));
        rows.push(row);
    }

    return { rows, fingerprints };
}

function InviteForm({
    roles,
    dataPolicies,
    organizations,
    // Ditunda di server, jadi ia kosong sampai permintaan susulan tiba. Pemilih batas data
    // yang membacanya berada di dalam dialog; ketika dialog itu dapat dibuka, ia sudah ada.
    hierarchies = [],
    invitations,
    invitationList,
    ssoAvailable,
}: Pick<
    Props,
    'roles' | 'dataPolicies' | 'organizations' | 'hierarchies' | 'invitations'
> & {
    ssoAvailable: boolean;
    invitationList: ReturnType<typeof useIncrementalList<Invitation>>;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const [open, setOpen] = useState(false);
    const [activeKey, setActiveKey] = useState<string | null>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const form = useForm({ codes: [blankCode()] });
    const [issuedState, setIssuedState] = useState(() => ({
        source: invitations,
        rows: invitations.map(issuedDraft),
        fingerprints: new Map(
            invitations.map((invitation) => [
                `issued-${invitation.id}`,
                codeFingerprint(issuedDraft(invitation)),
            ]),
        ),
    }));
    const issuedRows = issuedState.rows;
    const pristine = issuedState.fingerprints;
    const setIssuedRows = (update: (rows: CodeDraft[]) => CodeDraft[]) =>
        setIssuedState((current) => ({
            ...current,
            rows: update(current.rows),
        }));
    const [saving, setSaving] = useState<string | null>(null);
    const writeInFlight = useRef(false);
    const [refreshError, setRefreshError] = useState('');
    const roleById = new Map(roles.map((role) => [role.id, role]));

    if (issuedState.source !== invitations) {
        setIssuedState((current) => ({
            source: invitations,
            ...mergeIssuedRows(current.rows, invitations, current.fingerprints),
        }));
    }

    const refreshIssued = async (code: CodeDraft) => {
        if (!code.issued) {
            return;
        }

        setRefreshError('');

        try {
            const batch = await accessBatch<Invitation>('invitations', {
                recordId: code.issued.id,
            });

            if (batch.items.length !== 1) {
                throw new Error('Undangan belum dapat dimuat ulang.');
            }

            setIssuedState((current) => ({
                ...current,
                ...mergeIssuedRows(
                    current.rows,
                    batch.items,
                    current.fingerprints,
                    code.key,
                ),
            }));
            invitationList.reload();
        } catch {
            setRefreshError(
                'Perubahan sudah tersimpan, tetapi rincian terbaru belum dapat dimuat. Tutup lalu buka kembali undangan sebelum mengubahnya lagi.',
            );
        }
    };

    const update = (key: string, change: Partial<CodeDraft>) => {
        if (writeInFlight.current || form.processing) {
            return;
        }

        const apply = (code: CodeDraft) =>
            code.key === key ? { ...code, ...change } : code;

        if (key.startsWith('issued-')) {
            setIssuedRows((current) => current.map(apply));

            return;
        }

        form.setData('codes', form.data.codes.map(apply));
    };
    const rolesOf = (code: CodeDraft) =>
        code.assignments
            .map((assignment) => roleById.get(assignment.role_id))
            .filter((role): role is Role => Boolean(role));
    // Baris terbit yang sudah dicabut tidak dapat ditukar siapa pun lagi,
    // sehingga mengubahnya tidak mengubah akses siapa pun.
    const locked = (code: CodeDraft) =>
        Boolean(code.issued?.revoked) || Boolean(code.issued?.sso?.redeemed_at);
    const dirty = (code: CodeDraft) =>
        Boolean(code.issued) &&
        pristine.get(code.key) !== codeFingerprint(code);
    const rowErrorKey = (code: CodeDraft, field: string) =>
        code.issued
            ? `issued.${code.key}.${field}`
            : `codes.${form.data.codes.findIndex((item) => item.key === code.key)}.${field}`;
    const rowError = (code: CodeDraft, field: string) =>
        Object.entries(form.errors)
            .filter(
                ([key]) =>
                    key === rowErrorKey(code, field) ||
                    key.startsWith(`${rowErrorKey(code, field)}.`),
            )
            .map(([, value]) =>
                Array.isArray(value) ? value.join(' ') : value,
            )
            .join(' ');
    const reportIssuedErrors = (
        code: CodeDraft,
        errors: Record<string, string>,
    ) =>
        form.setError(
            Object.fromEntries(
                Object.entries(errors).map(([field, message]) => [
                    field === '_form' ? '_form' : rowErrorKey(code, field),
                    message,
                ]),
            ),
        );
    const save = (code: CodeDraft) => {
        if (!code.issued || writeInFlight.current || form.processing) {
            return;
        }

        writeInFlight.current = true;
        setSaving(code.key);
        router.patch(
            `/settings/access/invitations/${code.issued.id}`,
            {
                label: code.label,
                assignments: code.assignments.map((assignment) => ({
                    role_id: assignment.role_id,
                    policy_scopes: assignment.policy_scopes,
                })),
                version: code.issued.version,
            },
            withInertiaErrors({
                preserveScroll: true,
                onSuccess: async () => {
                    await refreshIssued(code);
                    toast('Perubahan kode undangan disimpan.');
                },
                onFinish: () => {
                    writeInFlight.current = false;
                    setSaving(null);
                },
                onError: (errors) => reportIssuedErrors(code, errors),
            }),
        );
    };

    /**
     * Meminta penyedia mengirim ulang email undangan.
     *
     * Ada karena pengiriman pertama boleh gagal tanpa menghanguskan undangannya — penyedia yang
     * sedang mati meninggalkan baris yang sah dengan surat yang tidak pernah keluar.
     */
    const resend = (code: CodeDraft) => {
        if (!code.issued || writeInFlight.current || form.processing) {
            return;
        }

        writeInFlight.current = true;
        setSaving(code.key);
        router.post(
            `/settings/access/invitations/${code.issued.id}/kirim-ulang`,
            {},
            withInertiaErrors({
                preserveScroll: true,
                onSuccess: async () => {
                    await refreshIssued(code);
                    toast('Penyedia SSO diminta mengirim ulang undangannya.');
                },
                onFinish: () => {
                    writeInFlight.current = false;
                    setSaving(null);
                },
                onError: (errors) => reportIssuedErrors(code, errors),
            }),
        );
    };

    const columns: DataTableColumn<CodeDraft>[] = [
        // Hanya ada bila tenant ini memakai SSO. Tanpa itu, yang muncul adalah kotak email yang
        // setiap isinya pasti ditolak pembuatan undangannya.
        ...(ssoAvailable
            ? [
                  {
                      id: 'sso_email',
                      content: 'control',
                      header: 'Diundang',
                      width: 240,
                      cell: (code: CodeDraft) =>
                          code.issued ? (
                              code.issued.sso ? (
                                  <div className="min-w-0">
                                      <p className="truncate text-sm">
                                          {code.issued.sso.name ??
                                              code.issued.sso.email}
                                      </p>
                                      <p className="truncate text-xs text-muted-foreground">
                                          {code.issued.sso.email}
                                      </p>
                                  </div>
                              ) : (
                                  <span className="text-xs text-muted-foreground">
                                      Kode anonim
                                  </span>
                              )
                          ) : (
                              <FormControl
                                  data-error-field={rowErrorKey(
                                      code,
                                      'sso_email',
                                  )}
                                  error={rowError(code, 'sso_email')}
                              >
                                  <Input
                                      disabled={
                                          saving !== null || form.processing
                                      }
                                      type="email"
                                      aria-invalid={
                                          !!rowError(code, 'sso_email')
                                      }
                                      value={code.sso_email}
                                      placeholder="nama@rumahsakit.co.id"
                                      onChange={(event) =>
                                          update(code.key, {
                                              sso_email: event.target.value,
                                          })
                                      }
                                  />
                                  <p className="mt-1 text-xs text-muted-foreground">
                                      Kosongkan untuk kode anonim yang dapat
                                      dipakai siapa pun yang memegangnya.
                                  </p>
                              </FormControl>
                          ),
                  } satisfies DataTableColumn<CodeDraft>,
              ]
            : []),
        {
            id: 'label',
            content: 'control',
            header: 'Keterangan',
            width: 240,
            // Kode berupa huruf acak; tanpa keterangan admin tidak akan ingat
            // kode ini dibuat untuk siapa.
            cell: (code) =>
                locked(code) ? (
                    <span className={code.label ? '' : 'text-muted-foreground'}>
                        {code.label || 'Tanpa keterangan'}
                    </span>
                ) : (
                    <FormControl
                        data-error-field={rowErrorKey(code, 'label')}
                        error={rowError(code, 'label')}
                    >
                        <Input
                            disabled={saving !== null || form.processing}
                            value={code.label}
                            aria-invalid={!!rowError(code, 'label')}
                            placeholder="mis. Onboarding staf gudang Agustus"
                            onChange={(event) =>
                                update(code.key, { label: event.target.value })
                            }
                        />
                    </FormControl>
                ),
        },
        {
            id: 'status',
            header: 'Status',
            width: 160,
            cell: (code) => (
                <div className="flex flex-wrap items-center gap-1">
                    {code.issued ? (
                        <Badge
                            variant={
                                code.issued.revoked ? 'secondary' : 'default'
                            }
                        >
                            {code.issued.revoked ? 'Dicabut' : 'Aktif'}
                        </Badge>
                    ) : (
                        <Badge variant="outline">Baru</Badge>
                    )}
                    {code.issued?.sso ? (
                        <>
                            {/*
                                Undangan pribadi hanya punya dua keadaan yang berarti: sudah dipakai
                                orangnya, atau masih menunggu. Penghitung "n terpakai" milik kode
                                anonim tidak pernah lebih dari satu di sini, dan membacanya sebagai
                                angka justru menyesatkan.
                            */}
                            <Badge variant="outline">
                                {code.issued.sso.redeemed_at
                                    ? 'Dipakai'
                                    : 'Menunggu'}
                            </Badge>
                            {!code.issued.sso.notified_at &&
                                !code.issued.sso.redeemed_at && (
                                    <Badge variant="secondary">
                                        Email belum terkirim
                                    </Badge>
                                )}
                        </>
                    ) : (
                        code.issued &&
                        code.issued.redeemed > 0 && (
                            <Badge variant="outline">
                                {code.issued.redeemed} terpakai
                            </Badge>
                        )
                    )}
                    {dirty(code) && <Badge variant="outline">Diubah</Badge>}
                </div>
            ),
        },
        {
            id: 'roles',
            content: 'control',
            header: 'Security role',
            width: 260,
            // MultiSelect bekerja dengan daftar teks, jadi nama role dipetakan
            // balik ke id saat nilainya berubah.
            cell: (code) =>
                locked(code) || saving !== null || form.processing ? (
                    <span className="text-muted-foreground">
                        {rolesOf(code)
                            .map((role) => role.name)
                            .join(', ') || '—'}
                    </span>
                ) : (
                    <FormControl
                        data-error-field={rowErrorKey(code, 'assignments')}
                        error={rowError(code, 'assignments')}
                    >
                        <MultiSelect
                            items={roles.map((role) => role.name)}
                            value={rolesOf(code).map((role) => role.name)}
                            onValueChange={(names) =>
                                update(code.key, {
                                    assignments: names
                                        .map((name) =>
                                            roles.find(
                                                (role) => role.name === name,
                                            ),
                                        )
                                        .filter((role): role is Role =>
                                            Boolean(role),
                                        )
                                        .map(
                                            (role) =>
                                                code.assignments.find(
                                                    (assignment) =>
                                                        assignment.role_id ===
                                                        role.id,
                                                ) ?? {
                                                    role_id: role.id,
                                                    policy_scopes: [],
                                                },
                                        ),
                                })
                            }
                            placeholder="Pilih role"
                            portalContainer={contentRef}
                        />
                    </FormControl>
                ),
        },
        {
            id: 'duties',
            header: 'Tanggung Jawab',
            width: 150,
            align: 'right',
            cell: (code) => {
                const total = new Set(
                    rolesOf(code).flatMap((role) =>
                        role.duties.map((duty) => duty.code),
                    ),
                ).size;

                return (
                    <span className="text-muted-foreground">
                        {total || '—'}
                    </span>
                );
            },
        },
        {
            id: 'scopes',
            header: 'Batas Data',
            width: 180,
            cell: (code) =>
                locked(code) ? (
                    <span className="text-muted-foreground">
                        {codeScopeSummary(roles, code.assignments)}
                    </span>
                ) : (
                    <ScopeDialog
                        disabled={saving !== null || form.processing}
                        code={code}
                        roles={roles}
                        dataPolicies={dataPolicies}
                        organizations={organizations}
                        hierarchies={hierarchies}
                        onChange={(assignments) =>
                            update(code.key, { assignments })
                        }
                    />
                ),
        },
        {
            // Menggantikan kolom "Sumber" yang isinya selalu "Undangan" di
            // dalam dialog ini. Tiap kode terbit adalah record tersendiri,
            // jadi penyimpanannya per baris — tombol footer hanya membuat kode.
            id: 'save',
            header: 'Aksi',
            width: 190,
            align: 'right',
            cell: (code) => {
                if (!code.issued || locked(code)) {
                    return null;
                }

                if (!dirty(code)) {
                    // Undangan terikat yang suratnya belum sampai: satu-satunya tindakan yang
                    // berarti di baris ini adalah mengirimnya lagi.
                    if (code.issued.sso && !code.issued.sso.redeemed_at) {
                        return (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={saving !== null}
                                onClick={() => resend(code)}
                            >
                                {code.issued.sso.notified_at
                                    ? 'Kirim ulang email'
                                    : 'Kirim email'}
                            </Button>
                        );
                    }

                    return (
                        <span className="text-muted-foreground">Tersimpan</span>
                    );
                }

                const button = (
                    <ActionButton
                        action="save"
                        type="button"
                        disabled={saving !== null}
                        onClick={
                            code.issued.redeemed > 0
                                ? undefined
                                : () => save(code)
                        }
                    />
                );

                if (code.issued.redeemed === 0) {
                    return button;
                }

                return (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            {button}
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Kode ini sudah dipakai{' '}
                                    {code.issued.redeemed} orang
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    Akses anggota yang sudah bergabung tidak
                                    berubah. Yang berubah hanya role dan batas
                                    data yang diterima orang berikutnya yang
                                    menukarkan kode ini. Kodenya sendiri tetap
                                    sama, jadi tidak perlu dibagikan ulang.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Batal</AlertDialogCancel>
                                <AlertDialogAction onClick={() => save(code)}>
                                    Simpan perubahan
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                );
            },
        },
    ];

    const transition = useRecordTransition(
        issuedRows.some(dirty) ||
            form.data.codes.some((code) =>
                Boolean(
                    code.label || code.sso_email || code.assignments.length,
                ),
            ),
        form.processing || saving !== null,
    );

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    if (writeInFlight.current) {
                        return;
                    }

                    transition.request(() => setOpen(false));

                    return;
                }

                setOpen(true);
                // Selalu mulai dari keadaan server: baris baru dikosongkan dan
                // suntingan baris terbit yang belum disimpan dibuang.
                setIssuedState({
                    source: invitations,
                    rows: invitations.map(issuedDraft),
                    fingerprints: new Map(
                        invitations.map((invitation) => [
                            `issued-${invitation.id}`,
                            codeFingerprint(issuedDraft(invitation)),
                        ]),
                    ),
                });
                setRefreshError('');
                form.setData('codes', next ? [blankCode()] : []);
            }}
        >
            <PageDialogTrigger asChild>
                <ActionButton action="create" />
            </PageDialogTrigger>
            <PageDialogContent
                ref={contentRef}
                title="Undangan"
                recordTitle="Kode undangan"
                description="Buat kode undangan, pilih role, lalu tentukan batas data yang diterima anggota."
            >
                <form
                    noValidate
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (writeInFlight.current || form.processing) {
                            return;
                        }

                        writeInFlight.current = true;
                        setSaving('new');
                        // `key` hanya penanda baris di klien, tidak dikirim.
                        form.transform((data) => ({
                            codes: data.codes.map((code) => ({
                                label: code.label,
                                // Dikirim huruf kecil: aturannya `lowercase`, dan menolak
                                // "Dewi@Klinik.test" karena huruf besarnya adalah penolakan yang
                                // tidak dapat dijelaskan kepada siapa pun yang mengetiknya.
                                sso_email: code.sso_email.trim().toLowerCase(),
                                assignments: code.assignments,
                            })),
                        }));
                        form.post(
                            '/settings/access/invitations',
                            wrapFormRequest({
                                onSuccess: () => setOpen(false),
                                onFinish: () => {
                                    writeInFlight.current = false;
                                    setSaving(null);
                                },
                            }),
                        );
                    }}
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="save"
                            type="submit"
                            pending={form.processing}
                            disabled={
                                form.data.codes.length === 0 ||
                                saving !== null ||
                                form.processing
                            }
                        />
                        <PageDialogClose asChild>
                            <ActionButton action="cancel" type="button" />
                        </PageDialogClose>
                    </PageDialogToolbar>
                    <>
                        {formRequestSummary}
                        <FormErrorHeader
                            errors={form.errors}
                            message={refreshError}
                            operation={
                                refreshError
                                    ? 'Perubahan sudah tersimpan'
                                    : undefined
                            }
                        />
                    </>

                    <PageDialogBody className="space-y-4">
                        {Object.keys(form.errors).length > 0 && (
                            <Alert variant="destructive">
                                <AlertTitle>Undangan belum disimpan</AlertTitle>
                                <AlertDescription>
                                    {Object.values(form.errors).map(
                                        (error, index) => (
                                            <p key={index}>{error}</p>
                                        ),
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                        <DataTable
                            columns={columns}
                            data={[...issuedRows, ...form.data.codes]}
                            incremental={invitationList}
                            getRowKey={(code) => code.key}
                            getRowLabel={() => 'kode undangan'}
                            activeRowKey={activeKey ?? undefined}
                            rowActivation="single-click"
                            onRowClick={(code) => setActiveKey(code.key)}
                            actions={
                                saving !== null || form.processing
                                    ? []
                                    : [
                                          {
                                              id: 'duplicate',
                                              label: 'Duplikat baris',
                                          },
                                          {
                                              id: 'delete',
                                              label: 'Delete',
                                              icon: (
                                                  <Trash2 aria-hidden="true" />
                                              ),
                                              destructive: true,
                                              separatorBefore: true,
                                          },
                                      ]
                            }
                            onRowAction={(action, code) => {
                                if (writeInFlight.current || form.processing) {
                                    return;
                                }

                                if (action === 'duplicate') {
                                    form.setData('codes', [
                                        ...form.data.codes,
                                        {
                                            ...code,
                                            key: crypto.randomUUID(),
                                            issued: undefined,
                                        },
                                    ]);

                                    return;
                                }

                                if (code.issued) {
                                    toast(
                                        'Kode yang sudah terbit dihentikan lewat tombol Cabut di daftar, bukan dihapus dari sini.',
                                    );

                                    return;
                                }

                                form.setData(
                                    'codes',
                                    form.data.codes.filter(
                                        (item) => item.key !== code.key,
                                    ),
                                );
                            }}
                            onAddRow={
                                saving !== null || form.processing
                                    ? undefined
                                    : () =>
                                          form.setData('codes', [
                                              ...form.data.codes,
                                              blankCode(),
                                          ])
                            }
                            addRowLabel="Tambah"
                            emptyMessage="Belum ada baris."
                        />
                        <div className="rounded-md border border-border/60 bg-muted/20 p-3 text-xs leading-relaxed text-muted-foreground">
                            💡 <strong>Catatan:</strong> Perubahan pada baris{' '}
                            <span className="font-semibold text-foreground">
                                Aktif
                            </span>{' '}
                            disimpan lewat tombol{' '}
                            <span className="font-semibold text-foreground">
                                Simpan perubahan
                            </span>{' '}
                            di baris itu, dan hanya berlaku untuk penukaran
                            berikutnya. Baris{' '}
                            <span className="font-semibold text-foreground">
                                Dicabut
                            </span>{' '}
                            terkunci karena kodenya sudah tidak dapat ditukar.
                            Tanggung jawab terisi otomatis dari role yang
                            dipilih; klik sel{' '}
                            <span className="font-semibold text-foreground">
                                Batas Data
                            </span>{' '}
                            untuk mengatur badan hukum dan unit kerja per role.
                        </div>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
            {transition.confirmation}
        </PageDialog>
    );
}

function MemberAccessDialog({
    member,
    apps,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    onClose,
}: {
    member: Member | null;
    apps: App[];
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    onClose: () => void;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const contentRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        assignments: (member?.assignments ?? [])
            .filter((assignment) => assignment.source !== 'automatic')
            .map((assignment) => ({
                role_id: assignment.role_id,
                policy_scopes: assignment.policy_scopes,
            })),
    });
    const transition = useRecordTransition(form.isDirty, form.processing);
    const automaticRoleIds = (member?.assignments ?? [])
        .filter((assignment) => assignment.source === 'automatic')
        .map((assignment) => assignment.role_id);
    const dutiesByCode = new Map(
        apps.flatMap((app) => app.duties).map((duty) => [duty.code, duty]),
    );

    return (
        <PageDialog
            open={Boolean(member)}
            onOpenChange={(open) => {
                if (!open) {
                    transition.request(onClose);
                }
            }}
        >
            <PageDialogContent
                ref={contentRef}
                title="Akses anggota"
                recordTitle={member?.name}
                description="Pilih role dan batas data, lalu simpan perubahan akses anggota."
                busy={form.processing}
            >
                <form
                    noValidate
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (member) {
                            form.transform((data) => ({
                                ...data,
                                version: member.version,
                            }));
                            form.patch(
                                `/settings/access/memberships/${member.id}`,
                                wrapFormRequest({ onSuccess: onClose }),
                            );
                        }
                    }}
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="save"
                            type="submit"
                            disabled={form.processing}
                        />
                        <PageDialogClose asChild>
                            <ActionButton action="cancel" type="button" />
                        </PageDialogClose>
                    </PageDialogToolbar>
                    <>
                        {formRequestSummary}
                        <FormErrorHeader errors={form.errors} />
                    </>

                    <PageDialogBody>
                        {Object.keys(form.errors).length > 0 && (
                            <Alert variant="destructive">
                                <AlertTitle>Akses belum disimpan</AlertTitle>
                                <AlertDescription>
                                    {Object.values(form.errors).map(
                                        (error, index) => (
                                            <p key={index}>{error}</p>
                                        ),
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                        {member && (
                            <p className="mb-4 text-sm text-muted-foreground">
                                {member.email}
                            </p>
                        )}
                        <FieldGroup>
                            <AssignmentPicker
                                assignments={form.data.assignments}
                                roles={roles}
                                dataPolicies={dataPolicies}
                                organizations={organizations}
                                hierarchies={hierarchies}
                                disabledRoleIds={automaticRoleIds}
                                onChange={(assignments) =>
                                    form.setData('assignments', assignments)
                                }
                            />
                            <FieldSet>
                                <FieldLegend>Rincian akses anggota</FieldLegend>
                                <FieldDescription>
                                    Rincian akses yang sudah tersimpan. Pilihan
                                    di atas berlaku setelah Anda menekan Simpan.
                                </FieldDescription>
                                <CollapsibleSectionGroup>
                                    {(member?.assignments ?? []).map(
                                        (assignment) => {
                                            const role = roles.find(
                                                (item) =>
                                                    item.id ===
                                                    assignment.role_id,
                                            );
                                            const value = `${assignment.role_id}-${assignment.source}`;

                                            return (
                                                <CollapsibleSection
                                                    key={value}
                                                    value={value}
                                                    title={
                                                        assignment.role_name ??
                                                        role?.name ??
                                                        'Role'
                                                    }
                                                    summary={
                                                        assignment.source ===
                                                        'automatic'
                                                            ? 'Aturan otomatis'
                                                            : 'Manual'
                                                    }
                                                >
                                                    <CollapsibleSectionGroup>
                                                        {role?.duties.map(
                                                            (roleDuty) => {
                                                                const duty =
                                                                    dutiesByCode.get(
                                                                        roleDuty.code,
                                                                    );

                                                                return (
                                                                    <CollapsibleSection
                                                                        key={
                                                                            roleDuty.code
                                                                        }
                                                                        value={
                                                                            roleDuty.code
                                                                        }
                                                                        title={
                                                                            roleDuty.name
                                                                        }
                                                                    >
                                                                        {duty?.privileges?.map(
                                                                            (
                                                                                privilege,
                                                                            ) => (
                                                                                <div
                                                                                    key={
                                                                                        privilege.code
                                                                                    }
                                                                                    className="space-y-2"
                                                                                >
                                                                                    <p className="text-sm">
                                                                                        {
                                                                                            privilege.name
                                                                                        }
                                                                                    </p>
                                                                                    <DataTable<Permission>
                                                                                        columns={[
                                                                                            {
                                                                                                id: 'permission',
                                                                                                header: 'Tindakan',
                                                                                                cell: (
                                                                                                    permission,
                                                                                                ) =>
                                                                                                    permission.name,
                                                                                            },
                                                                                            {
                                                                                                id: 'level',
                                                                                                header: 'Izin',
                                                                                                width: 160,
                                                                                                cell: (
                                                                                                    permission,
                                                                                                ) =>
                                                                                                    accessLevelLabels[
                                                                                                        permission
                                                                                                            .access_level
                                                                                                    ] ??
                                                                                                    permission.access_level,
                                                                                            },
                                                                                        ]}
                                                                                        data={
                                                                                            privilege.permissions
                                                                                        }
                                                                                        getRowKey={(
                                                                                            permission,
                                                                                        ) =>
                                                                                            permission.code
                                                                                        }
                                                                                    />
                                                                                </div>
                                                                            ),
                                                                        )}
                                                                    </CollapsibleSection>
                                                                );
                                                            },
                                                        )}
                                                    </CollapsibleSectionGroup>
                                                    {assignment.policy_scopes.map(
                                                        (scope) => (
                                                            <p
                                                                key={`${scope.policy_code}-${grantKey(scope)}`}
                                                                className="text-sm text-muted-foreground"
                                                            >
                                                                Batas data:{' '}
                                                                {dataPolicies.find(
                                                                    (policy) =>
                                                                        policy.code ===
                                                                        scope.policy_code,
                                                                )?.name ??
                                                                    scope.policy_code}
                                                            </p>
                                                        ),
                                                    )}
                                                </CollapsibleSection>
                                            );
                                        },
                                    )}
                                </CollapsibleSectionGroup>
                            </FieldSet>
                        </FieldGroup>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
            {transition.confirmation}
        </PageDialog>
    );
}

export default function Access({
    tenant,
    canManage,
    members,
    membersNextCursor,
    invitationsNextCursor,
    apps = [],
    roles,
    dataPolicies,
    organizations,
    hierarchies = [],
    invitations = [],
    newInvitationCodes = [],
    ssoAvailable = false,
    workersAvailable,
}: Props) {
    const { wrapRouter: wrapRouterRequest, summary: routerRequestSummary } =
        useInertiaFeedback();

    const url = usePage().url;
    const queryString = url.includes('?') ? url.split('?')[1] : '';
    const section = new URLSearchParams(queryString).get('section');
    const activeSection = section === 'invitations' ? section : 'members';
    const seededMembers = useRef<Member[] | null>(null);
    const seededInvitations = useRef<Invitation[] | null>(null);
    const loadMembers = useCallback(
        (input: ListRequest) => {
            const initial = seededMembers.current !== members;
            seededMembers.current = members;

            return initial && !input.cursor && !input.query && !input.sort
                ? Promise.resolve({
                      items: members,
                      nextCursor: membersNextCursor,
                  })
                : accessBatch<Member>('members', input);
        },
        [members, membersNextCursor],
    );
    const loadInvitations = useCallback(
        (input: ListRequest) => {
            const initial = seededInvitations.current !== invitations;
            seededInvitations.current = invitations;

            return initial && !input.cursor && !input.query && !input.sort
                ? Promise.resolve({
                      items: invitations,
                      nextCursor: invitationsNextCursor,
                  })
                : accessBatch<Invitation>('invitations', input);
        },
        [invitations, invitationsNextCursor],
    );
    const memberList = useIncrementalList({
        loadItems: loadMembers,
        getKey: (member) => member.id,
        active: activeSection === 'members',
    });
    const invitationList = useIncrementalList({
        loadItems: loadInvitations,
        getKey: (invitation) => invitation.id,
        active: activeSection === 'invitations',
    });
    const [editingMember, setEditingMember] = useState<Member | null>(null);
    const [revokingInvitation, setRevokingInvitation] =
        useState<Invitation | null>(null);
    const getInitials = useInitials();
    const copy = (code: string) => {
        void navigator.clipboard.writeText(code);
        toast('Kode disalin');
    };

    const memberColumns: DataTableColumn<Member>[] = [
        {
            id: 'identity',
            header: 'Anggota',
            cell: (member) => (
                <div className="flex items-center gap-3 py-0.5">
                    <Avatar size="default">
                        {member.avatar_url ? (
                            <AvatarImage
                                src={member.avatar_url}
                                alt={member.name}
                            />
                        ) : null}
                        <AvatarFallback className="bg-primary/10 text-xs font-medium text-primary">
                            {getInitials(member.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex min-w-0 flex-col">
                        <span className="truncate text-sm font-medium text-foreground">
                            {member.name}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                            {member.email}
                        </span>
                    </div>
                </div>
            ),
            sortValue: (member) => member.name,
        },
        {
            id: 'security-role',
            header: 'Security role',
            cell: (member) => member.roles.join(', ') || '—',
        },
        ...(workersAvailable
            ? [
                  {
                      id: 'worker',
                      header: 'Pekerja',
                      cell: (member) =>
                          member.worker ? (
                              <div className="flex min-w-0 flex-col">
                                  <span className="truncate text-sm text-foreground">
                                      {member.worker.name}
                                  </span>
                                  <span className="truncate text-xs text-muted-foreground">
                                      {member.worker.personnel_number}
                                  </span>
                              </div>
                          ) : (
                              <span className="text-muted-foreground">
                                  Belum tertaut
                              </span>
                          ),
                  } satisfies DataTableColumn<Member>,
              ]
            : []),
    ];
    const invitationColumns: DataTableColumn<Invitation>[] = [
        {
            id: 'label',
            header: 'Keterangan',
            cell: (invitation) =>
                invitation.label || (
                    <span className="text-muted-foreground">
                        Tanpa keterangan
                    </span>
                ),
            sortValue: (invitation) => invitation.label ?? '',
        },
        {
            id: 'status',
            header: 'Status',
            width: 120,
            cell: (invitation) => (
                <Badge
                    variant={invitation.revoked_at ? 'secondary' : 'default'}
                >
                    {invitation.revoked_at ? 'Dicabut' : 'Aktif'}
                </Badge>
            ),
        },
        {
            id: 'roles',
            header: 'Security role',
            cell: (invitation) => (invitation.roles ?? []).join(', ') || '—',
        },
    ];

    return (
        <>
            <Head title="Identity & access" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                {routerRequestSummary}
                <Heading
                    variant="small"
                    title="Identity & access"
                    description={`Kelola anggota dan tanggung jawab bisnis untuk ${tenant.name}.`}
                />

                {newInvitationCodes.length > 0 && (
                    <Alert>
                        <Check />
                        <AlertTitle>
                            {newInvitationCodes.length} kode undangan berhasil
                            dibuat
                        </AlertTitle>
                        <AlertDescription className="flex flex-col gap-2">
                            {newInvitationCodes.map((code) => (
                                <span
                                    key={code}
                                    className="flex flex-wrap items-center gap-3"
                                >
                                    <code className="rounded bg-muted px-2 py-1 font-mono text-base">
                                        {code}
                                    </code>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => copy(code)}
                                    >
                                        <Copy />
                                        Salin
                                    </Button>
                                </span>
                            ))}
                            {newInvitationCodes.length > 1 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="self-start"
                                    onClick={() =>
                                        copy(newInvitationCodes.join('\n'))
                                    }
                                >
                                    <Copy />
                                    Salin semua
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {activeSection === 'members' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Daftar anggota</CardTitle>
                            <CardDescription>
                                Orang yang dapat masuk ke bisnis ini.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                columns={memberColumns}
                                data={memberList.items}
                                incremental={memberList}
                                actions={
                                    canManage
                                        ? [
                                              {
                                                  id: 'access',
                                                  label: 'Atur akses',
                                                  icon: <Pencil />,
                                              },
                                          ]
                                        : []
                                }
                                getRowActions={(member) =>
                                    canManage && member.can_edit_access
                                        ? [
                                              {
                                                  id: 'access',
                                                  label: 'Atur akses',
                                                  icon: <Pencil />,
                                              },
                                          ]
                                        : []
                                }
                                actionsPlacement="inline"
                                onRowAction={(_, member) => {
                                    if (canManage && member.can_edit_access) {
                                        setEditingMember(member);
                                    }
                                }}
                                onRowClick={(member) => {
                                    if (canManage && member.can_edit_access) {
                                        setEditingMember(member);
                                    }
                                }}
                                getRowKey={(member) => member.id}
                            />
                        </CardContent>
                    </Card>
                )}

                {activeSection === 'invitations' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Kode undangan</CardTitle>
                            <CardDescription>
                                Kode dapat dipakai berulang sampai dicabut.
                            </CardDescription>
                            {canManage && (
                                <CardAction>
                                    <Deferred
                                        data={['apps', 'hierarchies']}
                                        fallback={
                                            <p
                                                role="status"
                                                className="text-sm text-muted-foreground"
                                            >
                                                Menyiapkan pilihan akses…
                                            </p>
                                        }
                                    >
                                        <InviteForm
                                            roles={roles}
                                            dataPolicies={dataPolicies}
                                            organizations={organizations}
                                            hierarchies={hierarchies}
                                            invitations={invitationList.items}
                                            invitationList={invitationList}
                                            ssoAvailable={ssoAvailable}
                                        />
                                    </Deferred>
                                </CardAction>
                            )}
                        </CardHeader>
                        <CardContent>
                            {invitationList.items.length ||
                            invitationList.loading ||
                            invitationList.error ? (
                                <DataTable
                                    columns={invitationColumns}
                                    data={invitationList.items}
                                    incremental={invitationList}
                                    actions={
                                        canManage
                                            ? [
                                                  {
                                                      id: 'copy',
                                                      label: 'Salin kode',
                                                      icon: <Copy />,
                                                  },
                                                  {
                                                      id: 'revoke',
                                                      label: 'Cabut kode',
                                                      icon: <Ban />,
                                                      destructive: true,
                                                  },
                                              ]
                                            : []
                                    }
                                    getRowActions={(invitation) =>
                                        !canManage || invitation.revoked_at
                                            ? []
                                            : [
                                                  ...(invitation.code
                                                      ? [
                                                            {
                                                                id: 'copy',
                                                                label: 'Salin kode',
                                                                icon: <Copy />,
                                                            },
                                                        ]
                                                      : []),
                                                  {
                                                      id: 'revoke',
                                                      label: 'Cabut kode',
                                                      icon: <Ban />,
                                                      destructive: true,
                                                  },
                                              ]
                                    }
                                    actionsPlacement="inline"
                                    onRowAction={(action, invitation) => {
                                        if (
                                            !canManage ||
                                            invitation.revoked_at
                                        ) {
                                            return;
                                        }

                                        if (
                                            action === 'copy' &&
                                            invitation.code
                                        ) {
                                            copy(invitation.code);
                                        }

                                        if (action === 'revoke') {
                                            setRevokingInvitation(invitation);
                                        }
                                    }}
                                    getRowKey={(invitation) => invitation.id}
                                />
                            ) : (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <Users />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            Belum ada undangan
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            Buat kode pertama untuk anggota
                                            baru.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            )}
                        </CardContent>
                    </Card>
                )}
                <AlertDialog
                    open={Boolean(revokingInvitation)}
                    onOpenChange={(open) => {
                        if (!open) {
                            setRevokingInvitation(null);
                        }
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Cabut kode akses?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Anggota baru tidak dapat memakai kode ini lagi.
                                Akses anggota yang sudah bergabung tidak
                                berubah.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => {
                                    if (
                                        canManage &&
                                        revokingInvitation &&
                                        !revokingInvitation.revoked_at
                                    ) {
                                        router.delete(
                                            `/settings/access/invitations/${revokingInvitation.id}`,
                                            wrapRouterRequest({
                                                data: {
                                                    version:
                                                        revokingInvitation.version,
                                                },
                                                onSuccess: () =>
                                                    setRevokingInvitation(null),
                                            }),
                                        );
                                    }
                                }}
                            >
                                Cabut kode
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
                {editingMember && (
                    <Deferred
                        data={['apps', 'hierarchies']}
                        fallback={
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Menyiapkan pilihan role dan batas data…
                            </p>
                        }
                    >
                        <MemberAccessDialog
                            key={`${editingMember?.id}:${editingMember?.version}`}
                            member={editingMember}
                            apps={apps}
                            roles={roles}
                            dataPolicies={dataPolicies}
                            organizations={organizations}
                            hierarchies={hierarchies}
                            onClose={() => setEditingMember(null)}
                        />
                    </Deferred>
                )}
            </main>
        </>
    );
}

Access.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/settings/access' },
        { title: 'Identity & access', href: '/settings/access' },
    ],
};
