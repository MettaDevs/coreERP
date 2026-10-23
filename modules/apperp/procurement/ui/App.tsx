import { lazy, Suspense } from 'react';
import { Card, CardContent } from '@apperp/ui/card';
import { PlaceholderPage } from './_shared/PlaceholderPage';

export type PropsModul = {
    view: string;
    segments: string[];
    permissions: string[];
    konteks: {
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
const RingkasanDashboardPage = lazy(
    () => import('./dashboard/RingkasanDashboardPage'),
);

// 10 Master Data (Sesuai Dokumentasi Aplikasi Procurement)
const VendorPage = lazy(() => import('./master/VendorPage'));
const PrakualifikasiPage = lazy(() => import('./master/PrakualifikasiPage'));
const KualifikasiPage = lazy(() => import('./master/KualifikasiPage'));
const HargaPerkiraanPage = lazy(() => import('./master/HargaPerkiraanPage'));
const KategoriPengadaanPage = lazy(
    () => import('./master/KategoriPengadaanPage'),
);
const JenisVendorPage = lazy(() => import('./master/JenisVendorPage'));
const MetodePembayaranPage = lazy(
    () => import('./master/MetodePembayaranPage'),
);
const TerminPembayaranPage = lazy(
    () => import('./master/TerminPembayaranPage'),
);
const ItemJasaPage = lazy(() => import('./master/ItemJasaPage'));
const ItemSubkontPage = lazy(() => import('./master/ItemSubkontPage'));

// Manajemen Pengadaan (Transaksi)
const PermintaanPembelianPage = lazy(
    () => import('./transactions/PermintaanPembelianPage'),
);
const PermintaanPenawaranPage = lazy(
    () => import('./transactions/PermintaanPenawaranPage'),
);
const PesananPembelianPage = lazy(
    () => import('./transactions/PesananPembelianPage'),
);
const PenerimaanBarangPage = lazy(
    () => import('./transactions/PenerimaanBarangPage'),
);

// Laporan
const LaporanPengadaanPage = lazy(
    () => import('./laporan/LaporanPengadaanPage'),
);

function LoadingState() {
    return (
        <div className="p-6">
            <Card>
                <CardContent className="py-12 text-center">
                    <p className="text-muted-foreground text-sm">
                        Memuat antarmuka pengadaan...
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}

export default function App({ view, segments, permissions }: PropsModul) {
    const pageProps = { view, segments, permissions };

    return (
        <Suspense fallback={<LoadingState />}>
            {(() => {
                switch (view) {
                    // Dashboard
                    case 'ringkasan-pengadaan':
                        return <RingkasanDashboardPage {...pageProps} />;

                    // 10 Master Data
                    case 'vendor':
                        return <VendorPage {...pageProps} />;
                    case 'prakualifikasi':
                        return <PrakualifikasiPage {...pageProps} />;
                    case 'kualifikasi':
                        return <KualifikasiPage {...pageProps} />;
                    case 'harga-perkiraan':
                        return <HargaPerkiraanPage {...pageProps} />;
                    case 'kategori-pengadaan':
                        return <KategoriPengadaanPage {...pageProps} />;
                    case 'jenis-vendor':
                        return <JenisVendorPage {...pageProps} />;
                    case 'metode-pembayaran':
                        return <MetodePembayaranPage {...pageProps} />;
                    case 'termin-pembayaran':
                        return <TerminPembayaranPage {...pageProps} />;
                    case 'item-jasa':
                        return <ItemJasaPage {...pageProps} />;
                    case 'item-subkont':
                        return <ItemSubkontPage {...pageProps} />;

                    // Manajemen Pengadaan (Transaksi)
                    case 'permintaan-pembelian':
                        return <PermintaanPembelianPage {...pageProps} />;
                    case 'permintaan-penawaran':
                        return <PermintaanPenawaranPage {...pageProps} />;
                    case 'pesanan-pembelian':
                        return <PesananPembelianPage {...pageProps} />;
                    case 'penerimaan-barang':
                        return <PenerimaanBarangPage {...pageProps} />;

                    // Laporan
                    case 'laporan-pengadaan':
                        return <LaporanPengadaanPage {...pageProps} />;

                    default:
                        return (
                            <PlaceholderPage
                                title="Pengadaan"
                                description="Modul Pengadaan Barang dan Jasa"
                                category="Umum"
                                view={view}
                                segments={segments}
                                permissions={permissions}
                            />
                        );
                }
            })()}
        </Suspense>
    );
}
