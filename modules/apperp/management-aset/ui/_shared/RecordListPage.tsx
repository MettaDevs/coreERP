import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { FilterPane } from '@apperp/ui/filter-pane';
import { FormGrid } from '@apperp/ui/form-control';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import type { ListPageLayoutProps } from '@apperp/ui/list-page-layout';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import type { RecordActionBarProps } from '@apperp/ui/record-action-bar';

type RecordListPageProps = Omit<
    ListPageLayoutProps,
    'header' | 'filters' | 'filterPanel' | 'filterSide'
> & {
    toolbar: Omit<RecordActionBarProps, 'filter'>;
    filters?: ReactNode;
    filtersActive?: boolean;
    notice?: ReactNode;
};

/** Adapter SDK agar jarak tabel dan buka/tutup filter kiri sama di semua daftar module. */
export default function RecordListPage({
    toolbar,
    filters,
    filtersActive = false,
    notice,
    children,
    ...props
}: RecordListPageProps) {
    const [filtersOpen, setFiltersOpen] = useState(false);
    const filterPanelId = useId();

    return (
        <ListPageLayout
            {...props}
            header={
                <RecordActionBar
                    {...toolbar}
                    filter={
                        filters
                            ? {
                                  open: filtersOpen,
                                  onOpenChange: setFiltersOpen,
                                  active: filtersActive,
                                  controls: filterPanelId,
                              }
                            : undefined
                    }
                />
            }
            filters={notice}
            filterSide="left"
            filterPanel={
                filters && filtersOpen ? (
                    <FilterPane
                        id={filterPanelId}
                        title="Filter"
                        description="Hasil diperbarui otomatis saat filter diubah."
                        onClose={() => setFiltersOpen(false)}
                    >
                        <div className="p-4">
                            <FormGrid columns={1}>{filters}</FormGrid>
                        </div>
                    </FilterPane>
                ) : null
            }
        >
            <div className="flex min-h-0 min-w-0 flex-1 flex-col pt-3">
                {children}
            </div>
        </ListPageLayout>
    );
}
