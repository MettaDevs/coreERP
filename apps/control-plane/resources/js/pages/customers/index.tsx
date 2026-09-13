import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
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
        <Shell
            title="Pelanggan"
            description="Setiap perusahaan yang punya tempatnya sendiri di sistem ini, beserta jumlah lingkungannya."
            actions={<CreateDialog app={app} />}
        >
            <Head title="Pelanggan" />

            {/*
                Kartunya di atas tabel, bukan di bawahnya. Ia hanya muncul beberapa detik setelah
                seorang pelanggan lahir, dan pada detik-detik itu ia satu-satunya hal di halaman
                ini yang benar-benar mendesak: kata sandinya tidak akan muncul lagi.
            */}
            {credentials && <CredentialsCard credentials={credentials} />}

            <div className="overflow-x-auto rounded-lg border bg-background">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama badan hukum</TableHead>
                            <TableHead>Lingkungan</TableHead>
                            <TableHead>Dibuat</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {customers.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={3}
                                    className="py-10 text-center text-sm text-muted-foreground"
                                >
                                    Belum ada pelanggan. Sampai ada, layar
                                    Lingkungan tidak punya siapa pun untuk
                                    dibuatkan tempat kerja.
                                </TableCell>
                            </TableRow>
                        )}
                        {customers.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-medium">
                                    {row.name}
                                    <span className="ms-2 font-mono text-xs text-muted-foreground">
                                        {row.id}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    {row.environments === 0 ? (
                                        <span className="text-sm text-muted-foreground">
                                            Belum ada
                                        </span>
                                    ) : (
                                        row.environments
                                    )}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {row.createdAt ?? 'Tidak tercatat'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Shell>
    );
}
