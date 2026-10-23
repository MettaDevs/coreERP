import { Card, CardHeader, CardTitle, CardContent } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import { Head } from '@inertiajs/react';
import Shell from '@/components/shell';
import CreateDialog from '@/pages/customers/create-dialog';
import CredentialsCard from '@/pages/customers/credentials-card';
import type { Credentials } from '@/pages/customers/credentials-card';

type Row = {
    id: string;
    name: string;
    environments: number;
    createdAt: string | null;
};

export default function Index({
    customers,
    app,
    credentials,
}: {
    customers: Row[];
    app: { id: string; name: string }[];
    credentials: Credentials | null;
}) {
    return (
        <Shell title="Tenant" actions={<CreateDialog app={app} />}>
            <Head title="Tenant" />

            {credentials && <CredentialsCard credentials={credentials} />}
            <Card>
                <CardHeader>
                    <CardTitle>Perusahaan dan grup</CardTitle>
                    <span className="text-xs text-muted-foreground">
                        {customers.length} tenant
                    </span>
                </CardHeader>
                <CardContent className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Setiap tenant memiliki akun admin dan lingkungan
                        kerjanya sendiri.
                    </p>
                    <div className="h-[min(32rem,60vh)] min-h-40">
                        <DataTable
                            scrollMode="viewport"
                            data={customers}
                            getRowKey={(row) => row.id}
                            showRowNumbers={false}
                            emptyMessage="Belum ada tenant. Pilih Baru untuk mendaftarkan perusahaan beserta akun admin pertamanya."
                            columns={[
                                {
                                    id: 'name',
                                    header: 'Nama tenant',
                                    minWidth: 240,
                                    cell: (row) => (
                                        <div className="py-1">
                                            <p className="font-medium">
                                                {row.name}
                                            </p>
                                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                {row.id}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    id: 'environments',
                                    header: 'Lingkungan kerja',
                                    minWidth: 180,
                                    cell: (row) =>
                                        row.environments > 0 ? (
                                            `${row.environments} lingkungan`
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Belum dibuat
                                            </span>
                                        ),
                                },
                                {
                                    id: 'createdAt',
                                    header: 'Terdaftar pada',
                                    minWidth: 180,
                                    cell: (row) =>
                                        row.createdAt ?? 'Tidak tercatat',
                                },
                            ]}
                        />
                    </div>
                </CardContent>
            </Card>
        </Shell>
    );
}
