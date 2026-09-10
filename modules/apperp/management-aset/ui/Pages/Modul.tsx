import { Suspense } from 'react';
import { Card, CardContent } from '@apperp/ui/card';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import App from '../App';
import type { PropsModul } from '../App';
import '../styles.css';

/**
 * Satu halaman Inertia untuk seluruh menu module ini.
 *
 * Yang memilih layar tetap rangkaian percabangan di `App.tsx`; yang berubah hanya sumber
 * nilainya. Dulu alamat dibaca dari ruas sesudah tanda pagar — perutean hash ada karena
 * satu image harus bisa disajikan di bawah awalan penempatan mana pun — dan sekarang ia
 * datang sebagai properti halaman dari rute shell `/management-aset/{view}/{sisa?}`.
 *
 * Gaya milik module diimpor di sini, bukan di `App.tsx`: berkas inilah yang menjadi titik
 * masuk potongan module, sehingga CSS-nya ikut sekali dan tidak lewat rantai impor yang
 * bisa berubah.
 *
 * Halaman ini tidak memeriksa ulang apakah `view` ada di manifest maupun apakah izinnya
 * dipegang. `HalamanModulController` sudah menjawab 404 dan 403 untuk keduanya, dan
 * pemeriksaan kedua di sini hanya akan menjadi daftar yang bisa menyimpang dari manifest.
 */
export default function Modul(props: PropsModul) {
    return (
        <Suspense
            fallback={
                <main>
                    <Card>
                        <CardContent>
                            <Empty>
                                <EmptyDescription>
                                    Menyiapkan layar…
                                </EmptyDescription>
                            </Empty>
                        </CardContent>
                    </Card>
                </main>
            }
        >
            <App {...props} />
        </Suspense>
    );
}
