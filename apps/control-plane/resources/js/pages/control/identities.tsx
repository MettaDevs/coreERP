import { Head, router } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import Heading from '@/components/heading';
import { Badge } from '@apperp/ui/badge';
import { Card, CardContent } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';

type Identity = {
    id: number;
    name: string;
    email: string;
    created_at: string;
    last_login_at: string | null;
    memberships: {
        tenant: string;
        system_role: string;
        status: string;
    }[];
};

type Props = {
    identities: {
        data: Identity[];
        total: number;
    };
    filters: { search: string };
};

export default function Identities({ identities, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const columns: DataTableColumn<Identity>[] = [
        {
            id: 'identity',
            header: 'Identity',
            cell: (identity) => (
                <div>
                    <p className="font-medium">{identity.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {identity.email}
                    </p>
                </div>
            ),
            sortValue: (identity) => identity.name,
        },
        {
            id: 'tenants',
            header: 'Tenant memberships',
            cell: (identity) => (
                <div className="flex flex-wrap gap-2">
                    {identity.memberships.map((membership) => (
                        <Badge
                            key={`${membership.tenant}-${membership.system_role}`}
                            variant="outline"
                        >
                            {membership.tenant} · {membership.system_role}
                        </Badge>
                    ))}
                    {!identity.memberships.length && (
                        <span className="text-muted-foreground">
                            Provider only
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'last-login',
            header: 'Last login',
            cell: (identity) =>
                identity.last_login_at
                    ? new Date(identity.last_login_at).toLocaleString()
                    : 'Never',
            sortValue: (identity) => identity.last_login_at ?? '',
        },
    ];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/control/identities', { search }, { preserveState: true });
    };

    return (
        <>
            <Head title="Identity monitor" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-6">
                <Heading
                    title="Identity monitor"
                    description="Provider-only metadata lintas tenant. Data bisnis modul tidak tersedia di sini."
                />
                <Card>
                    <CardContent className="flex flex-col gap-4">
                        <form onSubmit={submit} className="relative max-w-md">
                            <Input
                                label="Cari identity"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                aria-label="Search identities"
                            />
                        </form>
                        {identities.data.length ? (
                            <DataTable
                                columns={columns}
                                data={identities.data}
                                getRowKey={(identity) => identity.id}
                            />
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Users />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        Identity tidak ditemukan
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Ubah kata pencarian atau buat tenant
                                        pertama.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

Identities.layout = {
    breadcrumbs: [
        { title: 'Control', href: '/control/identities' },
        { title: 'Identities', href: '/control/identities' },
    ],
};
