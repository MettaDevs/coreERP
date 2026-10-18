import type { DataTableSort } from '@apperp/ui/data-table';
import type { InfiniteScrollRef } from '@inertiajs/core';
import { InfiniteScroll, router, usePage } from '@inertiajs/react';
import type { ReactNode, Ref } from 'react';

/** Adapter Inertia untuk pola scroll yang sama; API JSON memakai useIncrementalList SDK. */
export function RecordListScroll({
    data,
    children,
    className,
    listRef,
}: {
    data: string;
    children: ReactNode;
    className?: string;
    listRef?: Ref<InfiniteScrollRef>;
}) {
    return (
        <InfiniteScroll
            ref={listRef}
            data={data}
            itemsElement="tbody"
            onlyNext
            preserveUrl
            buffer={160}
            className={className}
            loading={
                <p role="status" className="p-2 text-sm text-muted-foreground">
                    Memuat data…
                </p>
            }
        >
            {children}
        </InfiniteScroll>
    );
}

export function useRecordListSort(data: string) {
    const { url } = usePage();
    const params = new URL(url, 'http://localhost').searchParams;
    const columnId = params.get('sort');
    const sort: DataTableSort | null = columnId
        ? {
              columnId,
              direction: params.get('direction') === 'desc' ? 'desc' : 'asc',
          }
        : null;

    return {
        serverSort: true,
        sort,
        onSortChange: (next: DataTableSort | null) =>
            router.reload({
                data: {
                    sort: next?.columnId,
                    direction: next?.direction,
                    page: null,
                    cursor: null,
                },
                only: [data],
                reset: [data],
            }),
    };
}
