import { Badge } from '@apperp/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';

type SetupKind = 'parameters' | 'posting-profiles';

const CONTENT: Record<
    SetupKind,
    {
        title: string;
        description: string;
        emptyTitle: string;
        emptyDescription: string;
    }
> = {
    parameters: {
        title: 'Parameter aset tetap',
        description:
            'Tempat untuk pengaturan perhitungan yang berlaku bagi aset tetap.',
        emptyTitle: 'Pengaturan dasar belum tersedia',
        emptyDescription:
            'Pengaturan pembulatan saat ini disimpan pada Buku penyusutan. Pengaturan lain akan ditambahkan setelah kebutuhannya dipastikan.',
    },
    'posting-profiles': {
        title: 'Profil posting aset',
        description:
            'Tempat untuk menyiapkan pemetaan transaksi aset sebelum modul Finance tersedia.',
        emptyTitle: 'Pemetaan akun belum tersedia',
        emptyDescription:
            'Belum ada akun atau posting yang disimpan di app Aset. Pemetaan akun akan dibuat bersama modul Finance.',
    },
};

export default function PengaturanAsetTetapPlaceholderPage({
    kind,
}: {
    kind: SetupKind;
}) {
    const content = CONTENT[kind];

    return (
        <Card
            aria-labelledby="fixed-aset-setup-title"
            className="min-h-full rounded-none border-0 shadow-none"
        >
            <CardHeader className="border-b px-5 py-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <CardTitle id="fixed-aset-setup-title">
                            {content.title}
                        </CardTitle>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {content.description}
                        </p>
                    </div>
                    <Badge variant="secondary">Belum tersedia</Badge>
                </div>
            </CardHeader>
            <CardContent className="px-0">
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>{content.emptyTitle}</EmptyTitle>
                        <EmptyDescription>
                            {content.emptyDescription}
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            </CardContent>
        </Card>
    );
}
