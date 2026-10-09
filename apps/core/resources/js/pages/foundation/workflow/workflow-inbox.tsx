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
import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import type { ApprovalRequest } from './ApprovalRequestCard';
import { ApprovalRequestCard } from './ApprovalRequestCard';

type Props = { items: ApprovalRequest[] };
export default function WorkflowInbox({ items }: Props) {
    const { url } = usePage();
    const target = new URLSearchParams(url.split('?')[1] ?? '').get('item');
    const visibleItems = target
        ? [...items].sort(
              (a, b) => Number(b.id === target) - Number(a.id === target),
          )
        : items;

    return (
        <>
            <Head title="Persetujuan saya" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Persetujuan saya"
                    description="Tinjau permintaan yang menunggu keputusan Anda."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Menunggu keputusan</CardTitle>
                        <CardDescription>
                            Keputusan dicatat dan tidak dapat diubah dari layar
                            ini.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {items.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Tidak ada permintaan
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Anda tidak memiliki permintaan yang
                                        perlu ditinjau.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            visibleItems.map((item) => (
                                <ApprovalRequestCard
                                    key={item.id}
                                    item={item}
                                />
                            ))
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
