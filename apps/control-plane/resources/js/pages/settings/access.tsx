import { Head, useForm } from '@inertiajs/react';
import { Check, Copy, Pencil, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

type Duty = { code: string; module_id: string; name: string };
type Module = { id: string; name: string; duties: Duty[] };
type Role = { id: string; name: string; duties: Duty[] };
type Member = {
    id: string;
    name: string;
    email: string;
    system_role: string;
    status: string;
    roles: string[];
    role_ids: string[];
    organization_id: string | null;
    hierarchy_id: string | null;
    include_descendants: boolean;
    can_edit_access: boolean;
};
type Organization = { id: string; name: string; classification: string };
type Hierarchy = { id: string; name: string };
type Invitation = {
    id: string;
    system_role: string;
    organization_id: string | null;
    include_descendants: boolean;
    roles: string[];
    used_at: string | null;
    revoked_at: string | null;
};
type Props = {
    tenant: { id: string; name: string };
    canManage: boolean;
    members: Member[];
    modules: Module[];
    roles: Role[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    invitations: Invitation[];
    newInvitationCode: string | null;
};

const toggle = (values: string[], value: string, checked: boolean) =>
    checked
        ? [...new Set([...values, value])]
        : values.filter((item) => item !== value);

function RoleForm({ modules }: { modules: Module[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', duty_codes: [] as string[] });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>Buat role</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Security role baru</DialogTitle>
                    <DialogDescription>
                        Role mewakili tanggung jawab bisnis dan boleh
                        menggabungkan duty dari beberapa produk.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/settings/access/roles', {
                            onSuccess: () => {
                                form.reset();
                                setOpen(false);
                            },
                        });
                    }}
                >
                    <FieldGroup>
                        <Field data-invalid={Boolean(form.errors.name)}>
                            <Input
                                label="Nama role"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={Boolean(form.errors.name)}
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>
                        <FieldSet
                            data-invalid={Boolean(form.errors.duty_codes)}
                        >
                            <FieldLegend hint="Pilih tanggung jawab yang memang dibutuhkan pekerjaan ini.">
                                Tanggung jawab bisnis
                            </FieldLegend>
                            <div className="max-h-72 space-y-4 overflow-auto rounded-md border p-3">
                                {modules.map((module) => (
                                    <div key={module.id} className="space-y-2">
                                        <p className="text-sm font-medium">
                                            {module.name}
                                        </p>
                                        {module.duties.map((duty) => (
                                            <label
                                                key={duty.code}
                                                className="flex items-center gap-3 text-sm"
                                            >
                                                <Checkbox
                                                    checked={form.data.duty_codes.includes(
                                                        duty.code,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        form.setData(
                                                            'duty_codes',
                                                            toggle(
                                                                form.data
                                                                    .duty_codes,
                                                                duty.code,
                                                                checked ===
                                                                    true,
                                                            ),
                                                        )
                                                    }
                                                />
                                                {duty.name}
                                            </label>
                                        ))}
                                    </div>
                                ))}
                            </div>
                            <FieldError>{form.errors.duty_codes}</FieldError>
                        </FieldSet>
                        <Button type="submit" disabled={form.processing}>
                            Simpan role
                        </Button>
                    </FieldGroup>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function InviteForm({
    roles,
    organizations,
    hierarchies,
}: Pick<Props, 'roles' | 'organizations' | 'hierarchies'>) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        system_role: 'user',
        role_ids: [] as string[],
        organization_id: '',
        hierarchy_id: '',
        include_descendants: false,
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <UserPlus />
                    Buat undangan
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Kode undangan baru</DialogTitle>
                    <DialogDescription>
                        Kode hanya dapat dipakai sekali, berlaku tujuh hari, dan
                        hanya ditampilkan sekali.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/settings/access/invitations', {
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <FieldGroup>
                        <Field>
                            <NativeSelect
                                label="Role platform"
                                value={form.data.system_role}
                                onChange={(event) =>
                                    form.setData(
                                        'system_role',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </NativeSelect>
                        </Field>
                        <FieldSet data-invalid={Boolean(form.errors.role_ids)}>
                            <FieldLegend hint="Security role menentukan tanggung jawab bisnis, terpisah dari role platform.">
                                Security role
                            </FieldLegend>
                            <div className="max-h-48 space-y-2 overflow-auto rounded-md border p-3">
                                {roles.map((role) => (
                                    <label
                                        key={role.id}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <Checkbox
                                            checked={form.data.role_ids.includes(
                                                role.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'role_ids',
                                                    toggle(
                                                        form.data.role_ids,
                                                        role.id,
                                                        checked === true,
                                                    ),
                                                )
                                            }
                                        />
                                        {role.name}
                                    </label>
                                ))}
                            </div>
                            <FieldError>{form.errors.role_ids}</FieldError>
                        </FieldSet>
                        <Field
                            data-invalid={Boolean(form.errors.organization_id)}
                        >
                            <NativeSelect
                                label="Batas organisasi"
                                value={form.data.organization_id}
                                onChange={(event) =>
                                    form.setData(
                                        'organization_id',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={Boolean(
                                    form.errors.organization_id,
                                )}
                            >
                                <option value="">Seluruh tenant</option>
                                {organizations.map((organization) => (
                                    <option
                                        key={organization.id}
                                        value={organization.id}
                                    >
                                        {organization.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <FieldError>
                                {form.errors.organization_id}
                            </FieldError>
                        </Field>
                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox
                                checked={form.data.include_descendants}
                                disabled={!form.data.organization_id}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'include_descendants',
                                        checked === true,
                                    )
                                }
                            />
                            Sertakan organisasi di bawahnya
                        </label>
                        {form.data.include_descendants && (
                            <Field
                                data-invalid={Boolean(form.errors.hierarchy_id)}
                            >
                                <NativeSelect
                                    label="Hierarchy acuan"
                                    value={form.data.hierarchy_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'hierarchy_id',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        form.errors.hierarchy_id,
                                    )}
                                >
                                    <option value="">Pilih hierarchy</option>
                                    {hierarchies.map((hierarchy) => (
                                        <option
                                            key={hierarchy.id}
                                            value={hierarchy.id}
                                        >
                                            {hierarchy.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <FieldDescription>
                                    Turunan dihitung hanya dari versi hierarchy
                                    yang aktif saat assignment dibuat.
                                </FieldDescription>
                                <FieldError>
                                    {form.errors.hierarchy_id}
                                </FieldError>
                            </Field>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            Buat kode
                        </Button>
                    </FieldGroup>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MemberAccessDialog({
    member,
    roles,
    organizations,
    hierarchies,
    onClose,
}: {
    member: Member | null;
    roles: Role[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    onClose: () => void;
}) {
    const form = useForm({
        system_role: member?.system_role ?? 'user',
        role_ids: member?.role_ids ?? [],
        organization_id: member?.organization_id ?? '',
        hierarchy_id: member?.hierarchy_id ?? '',
        include_descendants: member?.include_descendants ?? false,
    });

    return (
        <Dialog
            open={Boolean(member)}
            onOpenChange={(value) => !value && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Atur akses anggota</DialogTitle>
                    <DialogDescription>
                        {member?.name}.{' '}
                        {member?.system_role === 'owner'
                            ? 'Role pemilik tetap, tetapi tanggung jawab bisnisnya dapat diatur.'
                            : 'Atur peran platform, tanggung jawab bisnis, dan batas organisasinya.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (member) {
                            form.patch(
                                `/settings/access/memberships/${member.id}`,
                                { onSuccess: onClose },
                            );
                        }
                    }}
                >
                    <FieldGroup>
                        <Field>
                            <NativeSelect
                                label="Role platform"
                                value={form.data.system_role}
                                disabled={member?.system_role === 'owner'}
                                onChange={(event) =>
                                    form.setData(
                                        'system_role',
                                        event.target.value,
                                    )
                                }
                            >
                                {member?.system_role === 'owner' && (
                                    <option value="owner">Pemilik</option>
                                )}
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </NativeSelect>
                        </Field>
                        <FieldSet>
                            <FieldLegend>Tanggung jawab bisnis</FieldLegend>
                            <div className="max-h-44 space-y-2 overflow-auto rounded-md border p-3">
                                {roles.map((role) => (
                                    <label
                                        key={role.id}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <Checkbox
                                            checked={form.data.role_ids.includes(
                                                role.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'role_ids',
                                                    toggle(
                                                        form.data.role_ids,
                                                        role.id,
                                                        checked === true,
                                                    ),
                                                )
                                            }
                                        />
                                        {role.name}
                                    </label>
                                ))}
                            </div>
                        </FieldSet>
                        <Field>
                            <NativeSelect
                                label="Batas organisasi"
                                value={form.data.organization_id}
                                onChange={(event) =>
                                    form.setData(
                                        'organization_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Seluruh bisnis</option>
                                {organizations.map((organization) => (
                                    <option
                                        key={organization.id}
                                        value={organization.id}
                                    >
                                        {organization.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox
                                checked={form.data.include_descendants}
                                disabled={!form.data.organization_id}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'include_descendants',
                                        checked === true,
                                    )
                                }
                            />
                            Sertakan organisasi di bawahnya
                        </label>
                        {form.data.include_descendants && (
                            <Field>
                                <NativeSelect
                                    label="Susunan organisasi acuan"
                                    value={form.data.hierarchy_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'hierarchy_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">
                                        Pilih susunan organisasi
                                    </option>
                                    {hierarchies.map((hierarchy) => (
                                        <option
                                            key={hierarchy.id}
                                            value={hierarchy.id}
                                        >
                                            {hierarchy.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            Simpan akses
                        </Button>
                    </FieldGroup>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Access({
    tenant,
    canManage,
    members,
    modules,
    roles,
    organizations,
    hierarchies,
    invitations,
    newInvitationCode,
}: Props) {
    const [editingMember, setEditingMember] = useState<Member | null>(null);
    const memberColumns: DataTableColumn<Member>[] = [
        {
            id: 'name',
            header: 'Identity',
            cell: (member) => (
                <div>
                    <p className="font-medium">{member.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {member.email}
                    </p>
                </div>
            ),
            sortValue: (member) => member.name,
        },
        {
            id: 'system-role',
            header: 'Role platform',
            cell: (member) => <Badge>{member.system_role}</Badge>,
            sortValue: (member) => member.system_role,
        },
        {
            id: 'roles',
            header: 'Security role',
            cell: (member) => member.roles.join(', ') || '—',
        },
        {
            id: 'actions',
            header: 'Aksi',
            align: 'right',
            cell: (member) =>
                canManage && member.can_edit_access ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditingMember(member)}
                    >
                        <Pencil />
                        Atur akses
                    </Button>
                ) : null,
        },
    ];
    const roleColumns: DataTableColumn<Role>[] = [
        {
            id: 'name',
            header: 'Role',
            cell: (role) => <span className="font-medium">{role.name}</span>,
            sortValue: (role) => role.name,
        },
        {
            id: 'duties',
            header: 'Tanggung jawab',
            cell: (role) => role.duties.map((duty) => duty.name).join(', '),
        },
        {
            id: 'products',
            header: 'Produk terkait',
            cell: (role) =>
                [...new Set(role.duties.map((duty) => duty.module_id))].join(
                    ', ',
                ),
        },
    ];
    const invitationColumns: DataTableColumn<Invitation>[] = [
        {
            id: 'status',
            header: 'Status',
            cell: (invitation) => (
                <Badge
                    variant={
                        invitation.revoked_at || invitation.used_at
                            ? 'secondary'
                            : 'default'
                    }
                >
                    {invitation.revoked_at
                        ? 'Dicabut'
                        : invitation.used_at
                          ? 'Terpakai'
                          : 'Aktif'}
                </Badge>
            ),
        },
        {
            id: 'access',
            header: 'Akses',
            cell: (invitation) =>
                `${invitation.system_role} · ${invitation.organization_id ? (invitation.include_descendants ? 'organisasi dan turunannya' : 'satu organisasi') : 'seluruh tenant'}`,
        },
        {
            id: 'roles',
            header: 'Security role',
            cell: (invitation) => invitation.roles.join(', ') || '—',
        },
    ];

    return (
        <>
            <Head title="Identity & access" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Identity & access"
                    description={`Kelola anggota dan tanggung jawab bisnis untuk ${tenant.name}.`}
                />
                {newInvitationCode && (
                    <Alert>
                        <Check />
                        <AlertTitle>Kode undangan berhasil dibuat</AlertTitle>
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            <code className="rounded bg-muted px-2 py-1 font-mono text-base">
                                {newInvitationCode}
                            </code>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    void navigator.clipboard.writeText(
                                        newInvitationCode,
                                    );
                                    toast('Kode disalin');
                                }}
                            >
                                <Copy />
                                Salin
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}
                <Tabs defaultValue="members">
                    <TabsList>
                        <TabsTrigger value="members">Anggota</TabsTrigger>
                        <TabsTrigger value="roles">Role</TabsTrigger>
                        <TabsTrigger value="invitations">Undangan</TabsTrigger>
                    </TabsList>
                    <TabsContent value="members">
                        <Card>
                            <CardHeader>
                                <CardTitle>Anggota</CardTitle>
                                <CardDescription>
                                    Identity dengan membership tenant aktif.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <DataTable
                                    columns={memberColumns}
                                    data={members}
                                    getRowKey={(member) => member.id}
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="roles">
                        <Card>
                            <CardHeader>
                                <CardTitle>Security role</CardTitle>
                                <CardDescription>
                                    Susun role dari tanggung jawab bisnis,
                                    termasuk lintas produk.
                                </CardDescription>
                                {canManage && <RoleForm modules={modules} />}
                            </CardHeader>
                            <CardContent>
                                <DataTable
                                    columns={roleColumns}
                                    data={roles}
                                    getRowKey={(role) => role.id}
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="invitations">
                        <Card>
                            <CardHeader>
                                <CardTitle>Kode undangan</CardTitle>
                                <CardDescription>
                                    Kode asli tidak disimpan setelah
                                    ditampilkan.
                                </CardDescription>
                                {canManage && (
                                    <InviteForm
                                        roles={roles}
                                        organizations={organizations}
                                        hierarchies={hierarchies}
                                    />
                                )}
                            </CardHeader>
                            <CardContent>
                                {invitations.length ? (
                                    <DataTable
                                        columns={invitationColumns}
                                        data={invitations}
                                        getRowKey={(invitation) =>
                                            invitation.id
                                        }
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
                    </TabsContent>
                </Tabs>
                <MemberAccessDialog
                    key={editingMember?.id ?? 'closed'}
                    member={editingMember}
                    roles={roles}
                    organizations={organizations}
                    hierarchies={hierarchies}
                    onClose={() => setEditingMember(null)}
                />
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
