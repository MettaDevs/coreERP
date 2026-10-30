import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';

export type PlaceholderPageProps = {
    title: string;
    description: string;
};

/**
 * Halaman penampung sementara untuk menu Pengadaan yang belum memiliki fitur aktif.
 * Otorisasi menu telah diverifikasi di controller berdasarkan manifest `app.yaml`.
 */
export function PlaceholderPage({ title, description }: PlaceholderPageProps) {
    return (
        <div className="p-6">
            <Card>
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                </CardHeader>
                <CardContent className="py-12">
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Fitur ini belum tersedia</EmptyTitle>
                            <EmptyDescription>
                                {description} Halaman ini sedang disiapkan dan
                                belum dapat digunakan saat ini.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                </CardContent>
            </Card>
        </div>
    );
}
