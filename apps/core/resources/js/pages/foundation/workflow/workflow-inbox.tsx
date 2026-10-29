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
import { IncrementalListStatus } from '@apperp/ui/incremental-list-status';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListLoader } from '@apperp/ui/use-incremental-list';
import { Head } from '@inertiajs/react';
import { useCallback } from 'react';
import Heading from '@/components/heading';
import { apiJson } from '@/lib/core-api';
import type { ApprovalRequest } from './ApprovalRequestCard';
import { ApprovalRequestCard } from './ApprovalRequestCard';

type Props = { targetItem: ApprovalRequest | null };
export default function WorkflowInbox({ targetItem }: Props) {
    const loader = useCallback<ListLoader<ApprovalRequest>>(
        async ({ cursor, signal }) => {
            const params = new URLSearchParams();

            if (cursor) {
                params.set('cursor', cursor);
            }

            const result = await apiJson<{
                data: ApprovalRequest[];
                next_cursor: string | null;
            }>(`/workflow-inbox?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [],
    );
    const list = useIncrementalList({
        loadItems: loader,
        getKey: (item) => item.id,
    });
    const visibleItems = targetItem
        ? [
              targetItem,
              ...list.items.filter((item) => item.id !== targetItem.id),
          ]
        : list.items;

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
                        {!list.loading &&
                        !list.error &&
                        visibleItems.length === 0 ? (
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
                                    onDecided={() => {
                                        list.setItems((items) =>
                                            items.filter(
                                                (row) => row.id !== item.id,
                                            ),
                                        );
                                        list.reload();
                                    }}
                                />
                            ))
                        )}
                        <IncrementalListStatus state={list} />
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
