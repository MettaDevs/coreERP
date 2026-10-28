import { lazy } from 'react';
import { PlaceholderPage } from './_shared/PlaceholderPage';

export type ModulePageProps = {
    view: string;
    segments: string[];
    permissions: string[];
    context: {
        legal_entity_id: string | null;
        org_unit_id: string | null;
        user_id: string;
    };
};

/*
 * Pemanggilan lazy untuk masing-masing halaman menu.
 * Menjaga ukuran bundle tetap terpisah dan efisien saat menu dibuka.
 */
// Dashboard
const ProcurementOverviewPage = lazy(
    () => import('./dashboard/ProcurementOverviewPage'),
);

// 10 Master Data
const VendorPage = lazy(() => import('./master/VendorPage'));
const VendorPrequalificationPage = lazy(
    () => import('./master/VendorPrequalificationPage'),
);
const VendorQualificationPage = lazy(
    () => import('./master/VendorQualificationPage'),
);
const OwnerEstimatePage = lazy(() => import('./master/OwnerEstimatePage'));
const ProcurementCategoryPage = lazy(
    () => import('./master/ProcurementCategoryPage'),
);
const VendorTypePage = lazy(() => import('./master/VendorTypePage'));
const PaymentMethodPage = lazy(() => import('./master/PaymentMethodPage'));
const PaymentTermPage = lazy(() => import('./master/PaymentTermPage'));
const ServiceItemPage = lazy(() => import('./master/ServiceItemPage'));
const SubcontractItemPage = lazy(() => import('./master/SubcontractItemPage'));

// Manajemen Pengadaan (Transaksi)
const PurchaseRequisitionPage = lazy(
    () => import('./transactions/PurchaseRequisitionPage'),
);
const RequestForQuotationPage = lazy(
    () => import('./transactions/RequestForQuotationPage'),
);
const PurchaseOrderPage = lazy(
    () => import('./transactions/PurchaseOrderPage'),
);
const ProductReceiptPage = lazy(
    () => import('./transactions/ProductReceiptPage'),
);

// Laporan
const ProcurementReportPage = lazy(
    () => import('./reports/ProcurementReportPage'),
);

export default function App({
    view,
    segments,
    permissions,
    context,
}: ModulePageProps) {
    switch (view) {
        // Dashboard
        case 'ringkasan-pengadaan':
            return <ProcurementOverviewPage />;

        // 10 Master Data
        case 'vendor':
            return (
                <VendorPage
                    view={view}
                    segments={segments}
                    permissions={permissions}
                    context={context}
                />
            );
        case 'prakualifikasi':
            return (
                <VendorPrequalificationPage
                    view={view}
                    segments={segments}
                    permissions={permissions}
                    context={context}
                />
            );
        case 'kualifikasi':
            return <VendorQualificationPage />;
        case 'harga-perkiraan':
            return <OwnerEstimatePage />;
        case 'kategori-pengadaan':
            return <ProcurementCategoryPage />;
        case 'jenis-vendor':
            return <VendorTypePage />;
        case 'metode-pembayaran':
            return <PaymentMethodPage />;
        case 'termin-pembayaran':
            return <PaymentTermPage />;
        case 'item-jasa':
            return <ServiceItemPage />;
        case 'item-subkont':
            return <SubcontractItemPage />;

        // Manajemen Pengadaan (Transaksi)
        case 'permintaan-pembelian':
            return <PurchaseRequisitionPage />;
        case 'permintaan-penawaran':
            return <RequestForQuotationPage />;
        case 'pesanan-pembelian':
            return <PurchaseOrderPage />;
        case 'penerimaan-barang':
            return <ProductReceiptPage />;

        // Laporan
        case 'laporan-pengadaan':
            return <ProcurementReportPage />;

        default:
            return (
                <PlaceholderPage
                    title="Pengadaan"
                    description="Modul Pengadaan Barang dan Jasa"
                />
            );
    }
}
