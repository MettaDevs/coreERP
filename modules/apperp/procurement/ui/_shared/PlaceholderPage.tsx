import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';

export type PlaceholderPageProps = {
    title: string;
    description: string;
    category: string;
    view: string;
    segments?: string[];
    permissions?: string[];
};

export function PlaceholderPage({
    title,
    description,
    category,
    view,
    segments = [],
}: PlaceholderPageProps) {
    return (
        <div className="space-y-6 p-6">
            {/* Header & Action Bar */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                        <span>Pengadaan</span>
                        <span>/</span>
                        <span>{category}</span>
                    </div>
                    <h1 className="text-foreground mt-1 text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {description}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" disabled>
                        Filter & Cari
                    </Button>
                    <Button size="sm" disabled>
                        + Tambah Data
                    </Button>
                </div>
            </div>

            {/* Content Container */}
            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle className="text-base">{title}</CardTitle>
                            <CardDescription>
                                Identitas menu: <code>{view}</code>
                                {segments.length > 0 && (
                                    <>
                                        {' '}
                                        · Ruas:{' '}
                                        <code>{segments.join('/')}</code>
                                    </>
                                )}
                            </CardDescription>
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge variant="secondary">{category}</Badge>
                            <Badge variant="outline">Kerangka Aktif</Badge>
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="py-12">
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Halaman Siap Dikembangkan</EmptyTitle>
                            <EmptyDescription>
                                Rute menu, izin akses (
                                <code>procurement.{view}.read</code>), dan tata
                                letak shell sudah terhubung dengan baik ke
                                CoreERP. Langkah berikutnya adalah melengkapi
                                tabel database, API CRUD, dan komponen form
                                untuk layar ini.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                </CardContent>
            </Card>
        </div>
    );
}
