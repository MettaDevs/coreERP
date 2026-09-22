<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Starter template yang disediakan untuk tenant Indonesia
    |--------------------------------------------------------------------------
    |
    | Ini adalah data konfigurasi yang sengaja berversi. Template baru harus
    | memakai key baru; seed tidak mengubah baris template yang sudah pernah
    | dipakai aset.
    |
    */
    'indonesia_starter' => [
        'template_key' => 'id:pmk72-2023:starter:v1',
        'fiscal_classifications' => [
            [
                'template_key' => 'id:pmk72-2023:kelompok-1:v1',
                'jurisdiction' => 'ID',
                'label' => 'Kelompok 1',
                'regulation_reference' => null,
                'effective_from' => '2023-07-17',
                'effective_to' => null,
                'useful_life_years' => 4,
                'straight_line_rate_percent' => 25,
                'reducing_balance_rate_percent' => 50,
                'allow_reducing_balance' => true,
                'depreciable' => true,
                'aktif' => true,
            ],
            [
                'template_key' => 'id:pmk72-2023:kelompok-2:v1',
                'jurisdiction' => 'ID',
                'label' => 'Kelompok 2',
                'regulation_reference' => null,
                'effective_from' => '2023-07-17',
                'effective_to' => null,
                'useful_life_years' => 8,
                'straight_line_rate_percent' => 12.5,
                'reducing_balance_rate_percent' => 25,
                'allow_reducing_balance' => true,
                'depreciable' => true,
                'aktif' => true,
            ],
            [
                'template_key' => 'id:pmk72-2023:kelompok-3:v1',
                'jurisdiction' => 'ID',
                'label' => 'Kelompok 3',
                'regulation_reference' => null,
                'effective_from' => '2023-07-17',
                'effective_to' => null,
                'useful_life_years' => 16,
                'straight_line_rate_percent' => 6.25,
                'reducing_balance_rate_percent' => 12.5,
                'allow_reducing_balance' => true,
                'depreciable' => true,
                'aktif' => true,
            ],
            [
                'template_key' => 'id:pmk72-2023:kelompok-4:v1',
                'jurisdiction' => 'ID',
                'label' => 'Kelompok 4',
                'regulation_reference' => null,
                'effective_from' => '2023-07-17',
                'effective_to' => null,
                'useful_life_years' => 20,
                'straight_line_rate_percent' => 5,
                'reducing_balance_rate_percent' => 10,
                'allow_reducing_balance' => true,
                'depreciable' => true,
                'aktif' => true,
            ],
            [
                'template_key' => 'id:pmk72-2023:bangunan-permanen:v1',
                'jurisdiction' => 'ID',
                'label' => 'Bangunan permanen',
                'regulation_reference' => null,
                'effective_from' => '2023-07-17',
                'effective_to' => null,
                'useful_life_years' => 20,
                'straight_line_rate_percent' => 5,
                'reducing_balance_rate_percent' => null,
                'allow_reducing_balance' => false,
                'depreciable' => true,
                'aktif' => true,
            ],
            [
                'template_key' => 'id:pmk72-2023:bangunan-tidak-permanen:v1',
                'jurisdiction' => 'ID',
                'label' => 'Bangunan tidak permanen',
                'regulation_reference' => null,
                'effective_from' => '2023-07-17',
                'effective_to' => null,
                'useful_life_years' => 10,
                'straight_line_rate_percent' => 10,
                'reducing_balance_rate_percent' => null,
                'allow_reducing_balance' => false,
                'depreciable' => true,
                'aktif' => true,
            ],
            [
                'template_key' => 'id:pmk72-2023:tanah-bukan-objek-penyusutan:v1',
                'jurisdiction' => 'ID',
                'label' => 'Tanah (bukan objek penyusutan)',
                'regulation_reference' => null,
                'effective_from' => '2023-07-17',
                'effective_to' => null,
                'useful_life_years' => null,
                'straight_line_rate_percent' => null,
                'reducing_balance_rate_percent' => null,
                'allow_reducing_balance' => false,
                'depreciable' => false,
                'aktif' => true,
            ],
        ],
        'profiles' => [
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-1:garis-lurus:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-1:v1',
                'method' => 'straight_line',
                'method_label' => 'Garis lurus',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-1:saldo-menurun:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-1:v1',
                'method' => 'reducing_balance',
                'method_label' => 'Saldo menurun',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-2:garis-lurus:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-2:v1',
                'method' => 'straight_line',
                'method_label' => 'Garis lurus',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-2:saldo-menurun:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-2:v1',
                'method' => 'reducing_balance',
                'method_label' => 'Saldo menurun',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-3:garis-lurus:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-3:v1',
                'method' => 'straight_line',
                'method_label' => 'Garis lurus',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-3:saldo-menurun:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-3:v1',
                'method' => 'reducing_balance',
                'method_label' => 'Saldo menurun',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-4:garis-lurus:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-4:v1',
                'method' => 'straight_line',
                'method_label' => 'Garis lurus',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:kelompok-4:saldo-menurun:v1',
                'classification_key' => 'id:pmk72-2023:kelompok-4:v1',
                'method' => 'reducing_balance',
                'method_label' => 'Saldo menurun',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:bangunan-permanen:garis-lurus:v1',
                'classification_key' => 'id:pmk72-2023:bangunan-permanen:v1',
                'method' => 'straight_line',
                'method_label' => 'Garis lurus',
            ],
            [
                'template_key' => 'id:pmk72-2023:profil:bangunan-tidak-permanen:garis-lurus:v1',
                'classification_key' => 'id:pmk72-2023:bangunan-tidak-permanen:v1',
                'method' => 'straight_line',
                'method_label' => 'Garis lurus',
            ],
        ],
        'books' => [
            [
                'template_key' => 'id:pmk72-2023:buku:komersial:v1',
                // Kode diketik (K-24): ikut terkirim ke aplikasi finance di rincian posting.
                'code' => 'KOMERSIAL',
                'name' => 'Buku komersial',
                'posting_layer' => 'current',
                'description' => 'Buku untuk kebijakan akuntansi tenant. Umur manfaat dan profilnya ditentukan tenant.',
            ],
            [
                'template_key' => 'id:pmk72-2023:buku:fiskal:v1',
                // Kode diketik (K-24): ikut terkirim ke aplikasi finance di rincian posting.
                'code' => 'FISKAL',
                'name' => 'Buku fiskal/pajak',
                'posting_layer' => 'tax',
                'description' => 'Buku untuk referensi penyusutan pajak Indonesia berdasarkan template PMK 72 Tahun 2023.',
            ],
        ],
        'location_types' => [
            ['template_key' => 'id:starter:tipe-lokasi:site:v1', 'code_label' => 'Site', 'description' => 'Kawasan atau lokasi utama aset.'],
            ['template_key' => 'id:starter:tipe-lokasi:gedung:v1', 'code_label' => 'Gedung', 'description' => 'Bangunan tempat aset berada.'],
            ['template_key' => 'id:starter:tipe-lokasi:lantai:v1', 'code_label' => 'Lantai', 'description' => 'Tingkat pada sebuah gedung.'],
            ['template_key' => 'id:starter:tipe-lokasi:ruangan:v1', 'code_label' => 'Ruangan', 'description' => 'Ruang atau area tertutup tempat aset berada.'],
            ['template_key' => 'id:starter:tipe-lokasi:area:v1', 'code_label' => 'Area', 'description' => 'Area kerja atau area operasional.'],
            ['template_key' => 'id:starter:tipe-lokasi:rak:v1', 'code_label' => 'Rak', 'description' => 'Rak atau titik penyimpanan aset.'],
        ],
        'conditions' => [
            ['template_key' => 'id:starter:kondisi:baru:v1', 'code_label' => 'Baru', 'description' => 'Aset belum pernah digunakan.'],
            ['template_key' => 'id:starter:kondisi:baik:v1', 'code_label' => 'Baik', 'description' => 'Aset dapat digunakan tanpa perhatian khusus.'],
            ['template_key' => 'id:starter:kondisi:perlu-perhatian:v1', 'code_label' => 'Perlu perhatian', 'description' => 'Aset masih digunakan tetapi perlu ditindaklanjuti.'],
            ['template_key' => 'id:starter:kondisi:rusak:v1', 'code_label' => 'Rusak', 'description' => 'Aset tidak dapat digunakan sebelum diperbaiki.'],
            ['template_key' => 'id:starter:kondisi:tidak-digunakan:v1', 'code_label' => 'Tidak digunakan', 'description' => 'Aset tidak sedang digunakan.'],
        ],
        // Sub-template ini menambah katalog pabrikan dan model yang umum dipakai
        // di Indonesia/Asia tanpa mengubah template fiskal starter yang sudah ada.
        'manufacturer_models' => [
            'template_key' => 'id:manufacturer-models:indonesia-asia:v1',
            'manufacturers' => [
                [
                    'template_key' => 'toyota',
                    'name' => 'Toyota',
                    'description' => 'Pabrikan kendaraan penumpang, niaga, dan operasional yang banyak digunakan di Indonesia.',
                    'models' => [
                        ['template_key' => 'avanza', 'name' => 'Avanza', 'description' => 'Kendaraan operasional serbaguna untuk kebutuhan kantor dan lapangan.'],
                        ['template_key' => 'innova', 'name' => 'Innova', 'description' => 'Kendaraan operasional keluarga dan perjalanan dinas.'],
                        ['template_key' => 'hilux', 'name' => 'Hilux', 'description' => 'Kendaraan pikap untuk kebutuhan operasional dan lapangan.'],
                        ['template_key' => 'dyna', 'name' => 'Dyna', 'description' => 'Kendaraan niaga ringan untuk angkutan operasional.'],
                    ],
                ],
                [
                    'template_key' => 'daihatsu',
                    'name' => 'Daihatsu',
                    'description' => 'Pabrikan kendaraan ringkas dan niaga yang umum digunakan untuk operasional di Indonesia.',
                    'models' => [
                        ['template_key' => 'gran-max', 'name' => 'Gran Max', 'description' => 'Kendaraan niaga ringan untuk angkutan barang dan layanan.'],
                        ['template_key' => 'xenia', 'name' => 'Xenia', 'description' => 'Kendaraan operasional serbaguna untuk perjalanan dan layanan.'],
                        ['template_key' => 'terios', 'name' => 'Terios', 'description' => 'Kendaraan operasional untuk kebutuhan jalan perkotaan dan lapangan.'],
                    ],
                ],
                [
                    'template_key' => 'honda',
                    'name' => 'Honda',
                    'description' => 'Pabrikan kendaraan penumpang yang banyak digunakan untuk operasional dan perjalanan dinas.',
                    'models' => [
                        ['template_key' => 'brio', 'name' => 'Brio', 'description' => 'Kendaraan operasional ringkas untuk mobilitas perkotaan.'],
                        ['template_key' => 'hr-v', 'name' => 'HR-V', 'description' => 'Kendaraan operasional serbaguna untuk mobilitas harian.'],
                        ['template_key' => 'cr-v', 'name' => 'CR-V', 'description' => 'Kendaraan operasional untuk perjalanan dinas dan lapangan.'],
                    ],
                ],
                [
                    'template_key' => 'suzuki',
                    'name' => 'Suzuki',
                    'description' => 'Pabrikan kendaraan penumpang dan niaga yang umum digunakan untuk kegiatan operasional.',
                    'models' => [
                        ['template_key' => 'carry', 'name' => 'Carry', 'description' => 'Kendaraan pikap untuk angkutan barang dan operasional lapangan.'],
                        ['template_key' => 'ertiga', 'name' => 'Ertiga', 'description' => 'Kendaraan operasional serbaguna untuk perjalanan dinas.'],
                        ['template_key' => 'xl7', 'name' => 'XL7', 'description' => 'Kendaraan operasional untuk mobilitas kantor dan lapangan.'],
                    ],
                ],
                [
                    'template_key' => 'mitsubishi',
                    'name' => 'Mitsubishi',
                    'description' => 'Pabrikan kendaraan penumpang dan pikap untuk kebutuhan operasional serta lapangan.',
                    'models' => [
                        ['template_key' => 'xpander', 'name' => 'Xpander', 'description' => 'Kendaraan operasional serbaguna untuk perjalanan dinas.'],
                        ['template_key' => 'triton', 'name' => 'Triton', 'description' => 'Kendaraan pikap untuk pekerjaan lapangan dan area operasional.'],
                        ['template_key' => 'pajero-sport', 'name' => 'Pajero Sport', 'description' => 'Kendaraan operasional untuk perjalanan dan medan lapangan.'],
                    ],
                ],
                [
                    'template_key' => 'mitsubishi-fuso',
                    'name' => 'Mitsubishi Fuso',
                    'description' => 'Pabrikan kendaraan niaga dan truk yang banyak digunakan untuk angkutan di Indonesia.',
                    'models' => [
                        ['template_key' => 'canter', 'name' => 'Canter', 'description' => 'Truk ringan untuk angkutan barang dan operasional distribusi.'],
                        ['template_key' => 'fighter-x', 'name' => 'Fighter X', 'description' => 'Truk menengah untuk angkutan dan pekerjaan operasional.'],
                        ['template_key' => 'ecanter', 'name' => 'eCanter', 'description' => 'Truk listrik untuk angkutan perkotaan dan distribusi.'],
                    ],
                ],
                [
                    'template_key' => 'hino',
                    'name' => 'Hino',
                    'description' => 'Pabrikan kendaraan niaga dan truk untuk angkutan barang serta operasional industri.',
                    'models' => [
                        ['template_key' => 'hino-300', 'name' => 'Hino 300', 'description' => 'Truk ringan untuk angkutan barang dan layanan operasional.'],
                        ['template_key' => 'hino-500', 'name' => 'Hino 500', 'description' => 'Truk menengah untuk angkutan dan kegiatan industri.'],
                        ['template_key' => 'hino-700', 'name' => 'Hino 700', 'description' => 'Truk berat untuk angkutan jarak jauh dan pekerjaan berat.'],
                    ],
                ],
                [
                    'template_key' => 'isuzu',
                    'name' => 'Isuzu',
                    'description' => 'Pabrikan kendaraan niaga dan mesin diesel untuk kebutuhan angkutan serta operasional.',
                    'models' => [
                        ['template_key' => 'elf', 'name' => 'ELF', 'description' => 'Kendaraan niaga ringan untuk angkutan barang dan penumpang.'],
                        ['template_key' => 'traga', 'name' => 'Traga', 'description' => 'Kendaraan pikap untuk distribusi dan pekerjaan lapangan.'],
                        ['template_key' => 'giga', 'name' => 'GIGA', 'description' => 'Truk berat untuk angkutan barang dan pekerjaan industri.'],
                    ],
                ],
                [
                    'template_key' => 'hyundai',
                    'name' => 'Hyundai',
                    'description' => 'Pabrikan kendaraan penumpang dan niaga untuk mobilitas serta kegiatan operasional.',
                    'models' => [
                        ['template_key' => 'creta', 'name' => 'Creta', 'description' => 'Kendaraan operasional untuk mobilitas perkotaan dan perjalanan dinas.'],
                        ['template_key' => 'stargazer', 'name' => 'Stargazer', 'description' => 'Kendaraan operasional serbaguna untuk perjalanan dan layanan.'],
                        ['template_key' => 'hd78', 'name' => 'HD78', 'description' => 'Truk ringan untuk angkutan barang dan operasional.'],
                    ],
                ],
                [
                    'template_key' => 'ud-trucks',
                    'name' => 'UD Trucks',
                    'description' => 'Pabrikan truk untuk angkutan barang, distribusi, dan pekerjaan industri.',
                    'models' => [
                        ['template_key' => 'kuzer', 'name' => 'Kuzer', 'description' => 'Truk ringan untuk distribusi dan angkutan operasional.'],
                        ['template_key' => 'croner', 'name' => 'Croner', 'description' => 'Truk menengah untuk angkutan dan layanan industri.'],
                        ['template_key' => 'quester', 'name' => 'Quester', 'description' => 'Truk berat untuk angkutan jarak jauh dan pekerjaan berat.'],
                    ],
                ],
                [
                    'template_key' => 'komatsu',
                    'name' => 'Komatsu',
                    'description' => 'Pabrikan alat berat untuk konstruksi, pertambangan, dan pekerjaan tanah.',
                    'models' => [
                        ['template_key' => 'pc200', 'name' => 'PC200', 'description' => 'Excavator kelas menengah untuk pekerjaan tanah dan konstruksi.'],
                        ['template_key' => 'pc300', 'name' => 'PC300', 'description' => 'Excavator untuk pekerjaan konstruksi dan pertambangan.'],
                        ['template_key' => 'wa200', 'name' => 'WA200', 'description' => 'Wheel loader untuk pemindahan material dan pekerjaan area.'],
                        ['template_key' => 'hd785', 'name' => 'HD785', 'description' => 'Dump truck untuk angkutan material pada pekerjaan berat.'],
                    ],
                ],
                [
                    'template_key' => 'caterpillar',
                    'name' => 'Caterpillar',
                    'description' => 'Pabrikan alat berat untuk konstruksi, pertambangan, dan pemindahan material.',
                    'models' => [
                        ['template_key' => '320', 'name' => '320', 'description' => 'Excavator untuk pekerjaan konstruksi dan penggalian.'],
                        ['template_key' => '336', 'name' => '336', 'description' => 'Excavator kelas berat untuk pekerjaan tanah dan tambang.'],
                        ['template_key' => '950', 'name' => '950', 'description' => 'Wheel loader untuk pemuatan dan pemindahan material.'],
                        ['template_key' => '777', 'name' => '777', 'description' => 'Dump truck untuk angkutan material pada area tambang.'],
                    ],
                ],
                [
                    'template_key' => 'hitachi',
                    'name' => 'Hitachi',
                    'description' => 'Pabrikan alat berat untuk penggalian, pemuatan, dan pekerjaan konstruksi.',
                    'models' => [
                        ['template_key' => 'zx200', 'name' => 'ZX200', 'description' => 'Excavator kelas menengah untuk pekerjaan konstruksi.'],
                        ['template_key' => 'zx350', 'name' => 'ZX350', 'description' => 'Excavator untuk pekerjaan tanah dan pertambangan.'],
                        ['template_key' => 'zw310', 'name' => 'ZW310', 'description' => 'Wheel loader untuk pemindahan dan pemuatan material.'],
                    ],
                ],
                [
                    'template_key' => 'kobelco',
                    'name' => 'Kobelco',
                    'description' => 'Pabrikan excavator dan alat berat untuk konstruksi serta pekerjaan tanah.',
                    'models' => [
                        ['template_key' => 'sk200', 'name' => 'SK200', 'description' => 'Excavator kelas menengah untuk konstruksi dan penggalian.'],
                        ['template_key' => 'sk350', 'name' => 'SK350', 'description' => 'Excavator untuk pekerjaan tanah dan area kerja berat.'],
                    ],
                ],
                [
                    'template_key' => 'sany',
                    'name' => 'SANY',
                    'description' => 'Pabrikan alat berat untuk konstruksi, pertambangan, dan pekerjaan infrastruktur.',
                    'models' => [
                        ['template_key' => 'sy215', 'name' => 'SY215', 'description' => 'Excavator untuk pekerjaan tanah dan konstruksi.'],
                        ['template_key' => 'sy365', 'name' => 'SY365', 'description' => 'Excavator kelas berat untuk proyek dan pertambangan.'],
                    ],
                ],
                [
                    'template_key' => 'xcmg',
                    'name' => 'XCMG',
                    'description' => 'Pabrikan alat berat dan crane untuk konstruksi serta pekerjaan infrastruktur.',
                    'models' => [
                        ['template_key' => 'xe215', 'name' => 'XE215', 'description' => 'Excavator untuk penggalian dan pekerjaan konstruksi.'],
                        ['template_key' => 'xca500', 'name' => 'XCA500', 'description' => 'Mobile crane untuk pengangkatan pada proyek konstruksi.'],
                    ],
                ],
                [
                    'template_key' => 'toyota-material-handling',
                    'name' => 'Toyota Material Handling',
                    'description' => 'Pabrikan peralatan material handling untuk gudang, pabrik, dan distribusi.',
                    'models' => [
                        ['template_key' => '8fg', 'name' => '8FG', 'description' => 'Forklift berbahan bakar untuk pemindahan material.'],
                        ['template_key' => '8fd', 'name' => '8FD', 'description' => 'Forklift diesel untuk pekerjaan gudang dan lapangan.'],
                        ['template_key' => 'bt-reflex', 'name' => 'BT Reflex', 'description' => 'Reach truck untuk penyimpanan dan pengambilan barang di rak.'],
                    ],
                ],
                [
                    'template_key' => 'mitsubishi-logisnext',
                    'name' => 'Mitsubishi Logisnext',
                    'description' => 'Pabrikan forklift dan peralatan material handling untuk kegiatan gudang dan industri.',
                    'models' => [
                        ['template_key' => 'fd', 'name' => 'FD', 'description' => 'Forklift diesel untuk pemindahan material.'],
                        ['template_key' => 'fb', 'name' => 'FB', 'description' => 'Forklift listrik untuk pekerjaan gudang dan fasilitas.'],
                        ['template_key' => 'opb', 'name' => 'OPB', 'description' => 'Order picker untuk pengambilan barang di gudang.'],
                    ],
                ],
                [
                    'template_key' => 'tcm',
                    'name' => 'TCM',
                    'description' => 'Pabrikan forklift untuk kebutuhan gudang, pabrik, dan distribusi.',
                    'models' => [
                        ['template_key' => 'fd', 'name' => 'FD', 'description' => 'Forklift diesel untuk pemindahan material.'],
                        ['template_key' => 'ftb', 'name' => 'FTB', 'description' => 'Forklift listrik untuk pekerjaan dalam fasilitas.'],
                        ['template_key' => 'fhd', 'name' => 'FHD', 'description' => 'Forklift kapasitas besar untuk pekerjaan material berat.'],
                    ],
                ],
                [
                    'template_key' => 'hangcha',
                    'name' => 'Hangcha',
                    'description' => 'Pabrikan forklift dan peralatan gudang untuk kebutuhan material handling.',
                    'models' => [
                        ['template_key' => 'x-series', 'name' => 'X Series', 'description' => 'Seri forklift untuk pemindahan material di gudang dan pabrik.'],
                        ['template_key' => 'a-series', 'name' => 'A Series', 'description' => 'Seri forklift untuk operasi gudang dan fasilitas.'],
                        ['template_key' => 'cbd', 'name' => 'CBD', 'description' => 'Seri stacker atau pallet mover untuk pekerjaan gudang.'],
                    ],
                ],
                [
                    'template_key' => 'heli',
                    'name' => 'HELI',
                    'description' => 'Pabrikan forklift dan peralatan material handling untuk kegiatan industri.',
                    'models' => [
                        ['template_key' => 'cpcd', 'name' => 'CPCD', 'description' => 'Forklift diesel untuk pemindahan material dan bongkar muat.'],
                        ['template_key' => 'cpd', 'name' => 'CPD', 'description' => 'Forklift listrik untuk operasi gudang dan fasilitas.'],
                    ],
                ],
                [
                    'template_key' => 'siemens',
                    'name' => 'Siemens',
                    'description' => 'Pabrikan otomasi industri, pengendali, dan penggerak untuk fasilitas produksi.',
                    'models' => [
                        ['template_key' => 'simatic-s7-1200', 'name' => 'SIMATIC S7-1200', 'description' => 'PLC ringkas untuk otomasi mesin dan proses.'],
                        ['template_key' => 'simatic-s7-1500', 'name' => 'SIMATIC S7-1500', 'description' => 'PLC untuk otomasi lini produksi dan proses industri.'],
                        ['template_key' => 'sinamics-g120', 'name' => 'SINAMICS G120', 'description' => 'Penggerak motor untuk pengaturan kecepatan dan proses.'],
                    ],
                ],
                [
                    'template_key' => 'mitsubishi-electric',
                    'name' => 'Mitsubishi Electric',
                    'description' => 'Pabrikan otomasi pabrik, penggerak, servo, dan tata udara untuk fasilitas industri.',
                    'models' => [
                        ['template_key' => 'melsec-iq-f', 'name' => 'MELSEC iQ-F', 'description' => 'PLC ringkas untuk mesin dan otomasi pabrik.'],
                        ['template_key' => 'melsec-iq-r', 'name' => 'MELSEC iQ-R', 'description' => 'PLC modular untuk lini produksi dan proses industri.'],
                        ['template_key' => 'fr-a800', 'name' => 'FR-A800', 'description' => 'Inverter untuk pengaturan motor dan mesin industri.'],
                        ['template_key' => 'melservo', 'name' => 'MELSERVO', 'description' => 'Sistem servo untuk kendali gerak mesin.'],
                        ['template_key' => 'city-multi', 'name' => 'CITY MULTI', 'description' => 'Sistem tata udara multi-unit untuk gedung dan fasilitas.'],
                        ['template_key' => 'mr-slim', 'name' => 'Mr Slim', 'description' => 'Sistem tata udara untuk ruang kerja dan fasilitas.'],
                        ['template_key' => 'ecodan', 'name' => 'Ecodan', 'description' => 'Sistem pompa kalor untuk pemanasan dan tata udara.'],
                    ],
                ],
                [
                    'template_key' => 'omron',
                    'name' => 'Omron',
                    'description' => 'Pabrikan otomasi, pengendali, dan sensor untuk mesin serta proses produksi.',
                    'models' => [
                        ['template_key' => 'cp2e', 'name' => 'CP2E', 'description' => 'PLC ringkas untuk kendali mesin dan otomasi.'],
                        ['template_key' => 'nx', 'name' => 'NX', 'description' => 'Pengendali modular untuk otomasi mesin dan proses.'],
                        ['template_key' => 'nj', 'name' => 'NJ', 'description' => 'Pengendali mesin untuk otomasi dan kendali gerak.'],
                    ],
                ],
                [
                    'template_key' => 'schneider-electric',
                    'name' => 'Schneider Electric',
                    'description' => 'Pabrikan otomasi, pengendali, dan pengelolaan energi untuk fasilitas industri.',
                    'models' => [
                        ['template_key' => 'modicon-m241', 'name' => 'Modicon M241', 'description' => 'PLC untuk kendali mesin dan otomasi fasilitas.'],
                        ['template_key' => 'modicon-m580', 'name' => 'Modicon M580', 'description' => 'PLC untuk otomasi proses dan lini produksi.'],
                        ['template_key' => 'altivar-atv600', 'name' => 'Altivar ATV600', 'description' => 'Penggerak motor untuk pompa, kipas, dan proses industri.'],
                    ],
                ],
                [
                    'template_key' => 'fuji-electric',
                    'name' => 'Fuji Electric',
                    'description' => 'Pabrikan otomasi, penggerak, dan servo untuk fasilitas serta mesin industri.',
                    'models' => [
                        ['template_key' => 'micrex-sx', 'name' => 'MICREX-SX', 'description' => 'PLC untuk kendali proses dan mesin industri.'],
                        ['template_key' => 'frenic', 'name' => 'FRENIC', 'description' => 'Inverter untuk kendali motor dan peralatan industri.'],
                        ['template_key' => 'alpha5', 'name' => 'ALPHA5', 'description' => 'Sistem servo untuk kendali gerak mesin.'],
                    ],
                ],
                [
                    'template_key' => 'yaskawa',
                    'name' => 'Yaskawa',
                    'description' => 'Pabrikan penggerak, servo, dan robot untuk otomasi mesin serta produksi.',
                    'models' => [
                        ['template_key' => 'ga700', 'name' => 'GA700', 'description' => 'Penggerak motor untuk peralatan dan proses industri.'],
                        ['template_key' => 'sigma-7', 'name' => 'Sigma-7', 'description' => 'Sistem servo untuk kendali gerak presisi.'],
                        ['template_key' => 'motoman', 'name' => 'MOTOMAN', 'description' => 'Robot industri untuk pengelasan, pemindahan, dan produksi.'],
                    ],
                ],
                [
                    'template_key' => 'fanuc',
                    'name' => 'FANUC',
                    'description' => 'Pabrikan robot, mesin CNC, dan kendali otomasi untuk industri manufaktur.',
                    'models' => [
                        ['template_key' => 'lr-mate', 'name' => 'LR Mate', 'description' => 'Robot kecil untuk perakitan dan pemindahan material.'],
                        ['template_key' => 'robodrill', 'name' => 'ROBODRILL', 'description' => 'Mesin CNC untuk proses pemesinan komponen.'],
                        ['template_key' => 'r-30ib', 'name' => 'R-30iB', 'description' => 'Pengendali robot untuk operasi otomasi industri.'],
                    ],
                ],
                [
                    'template_key' => 'delta-electronics',
                    'name' => 'Delta Electronics',
                    'description' => 'Pabrikan otomasi, penggerak, dan catu daya untuk mesin serta fasilitas.',
                    'models' => [
                        ['template_key' => 'as-series', 'name' => 'AS Series', 'description' => 'PLC untuk kendali mesin dan otomasi pabrik.'],
                        ['template_key' => 'vfd-el', 'name' => 'VFD-EL', 'description' => 'Inverter ringkas untuk kendali motor.'],
                        ['template_key' => 'dvp', 'name' => 'DVP', 'description' => 'PLC ringkas untuk otomasi mesin dan fasilitas.'],
                    ],
                ],
                [
                    'template_key' => 'keyence',
                    'name' => 'Keyence',
                    'description' => 'Pabrikan sensor, visi mesin, dan identifikasi untuk otomasi proses produksi.',
                    'models' => [
                        ['template_key' => 'iv-series', 'name' => 'IV Series', 'description' => 'Sistem visi sederhana untuk pemeriksaan kualitas.'],
                        ['template_key' => 'sr-2000', 'name' => 'SR-2000', 'description' => 'Pembaca kode untuk identifikasi dan pelacakan barang.'],
                        ['template_key' => 'kv-series', 'name' => 'KV Series', 'description' => 'PLC untuk kendali mesin dan otomasi.'],
                    ],
                ],
                [
                    'template_key' => 'cummins',
                    'name' => 'Cummins',
                    'description' => 'Pabrikan mesin diesel dan genset untuk fasilitas, industri, dan kebutuhan cadangan daya.',
                    'models' => [
                        ['template_key' => 'c33', 'name' => 'C33', 'description' => 'Genset untuk kebutuhan daya cadangan fasilitas.'],
                        ['template_key' => 'c110', 'name' => 'C110', 'description' => 'Genset untuk gedung, fasilitas, dan operasional.'],
                        ['template_key' => 'qsk60', 'name' => 'QSK60', 'description' => 'Mesin diesel berkapasitas besar untuk pekerjaan berat.'],
                    ],
                ],
                [
                    'template_key' => 'yanmar',
                    'name' => 'Yanmar',
                    'description' => 'Pabrikan mesin diesel, genset, dan peralatan untuk pertanian, kelautan, dan operasional.',
                    'models' => [
                        ['template_key' => 'tf-series', 'name' => 'TF Series', 'description' => 'Mesin diesel serbaguna untuk pompa, pertanian, dan aplikasi kelautan.'],
                        ['template_key' => 'yeg-series', 'name' => 'YEG Series', 'description' => 'Genset untuk fasilitas dan kebutuhan daya cadangan.'],
                        ['template_key' => '6aym', 'name' => '6AYM', 'description' => 'Mesin diesel untuk kebutuhan kelautan dan operasional berat.'],
                        ['template_key' => 'ym-series', 'name' => 'YM Series', 'description' => 'Mesin untuk traktor dan kebutuhan pertanian.'],
                        ['template_key' => 'aw-series', 'name' => 'AW Series', 'description' => 'Mesin dan peralatan untuk kebutuhan pertanian.'],
                    ],
                ],
                [
                    'template_key' => 'kubota',
                    'name' => 'Kubota',
                    'description' => 'Pabrikan mesin diesel, genset, traktor, dan mesin pertanian yang digunakan di Asia.',
                    'models' => [
                        ['template_key' => 'v-series', 'name' => 'V Series', 'description' => 'Mesin diesel untuk peralatan dan kebutuhan operasional.'],
                        ['template_key' => 'gl-series', 'name' => 'GL Series', 'description' => 'Genset untuk fasilitas dan kebutuhan daya cadangan.'],
                        ['template_key' => 'oc-series', 'name' => 'OC Series', 'description' => 'Mesin diesel ringkas untuk peralatan dan aplikasi kerja.'],
                        ['template_key' => 'm7040', 'name' => 'M7040', 'description' => 'Traktor untuk pekerjaan pertanian dan lahan.'],
                        ['template_key' => 'l4018', 'name' => 'L4018', 'description' => 'Traktor serbaguna untuk kebutuhan pertanian.'],
                        ['template_key' => 'dc-70', 'name' => 'DC-70', 'description' => 'Mesin panen untuk kegiatan pertanian.'],
                    ],
                ],
                [
                    'template_key' => 'denyo',
                    'name' => 'Denyo',
                    'description' => 'Pabrikan genset dan peralatan pembangkit listrik untuk proyek serta fasilitas.',
                    'models' => [
                        ['template_key' => 'dca-series', 'name' => 'DCA Series', 'description' => 'Genset untuk proyek, lapangan, dan fasilitas.'],
                        ['template_key' => 'ge-series', 'name' => 'GE Series', 'description' => 'Genset untuk kebutuhan daya cadangan operasional.'],
                    ],
                ],
                [
                    'template_key' => 'dell-technologies',
                    'name' => 'Dell Technologies',
                    'description' => 'Pabrikan komputer, server, dan penyimpanan data untuk kebutuhan kantor dan pusat data.',
                    'models' => [
                        ['template_key' => 'optiplex', 'name' => 'OptiPlex', 'description' => 'Komputer desktop untuk pekerjaan kantor.'],
                        ['template_key' => 'poweredge', 'name' => 'PowerEdge', 'description' => 'Server untuk aplikasi dan layanan pusat data.'],
                        ['template_key' => 'powervault', 'name' => 'PowerVault', 'description' => 'Penyimpanan data untuk server dan fasilitas TI.'],
                    ],
                ],
                [
                    'template_key' => 'lenovo',
                    'name' => 'Lenovo',
                    'description' => 'Pabrikan komputer, laptop, dan server untuk kebutuhan kantor serta pusat data.',
                    'models' => [
                        ['template_key' => 'thinkpad', 'name' => 'ThinkPad', 'description' => 'Laptop untuk pekerjaan kantor dan mobilitas.'],
                        ['template_key' => 'thinkcentre', 'name' => 'ThinkCentre', 'description' => 'Komputer desktop untuk pekerjaan kantor.'],
                        ['template_key' => 'thinksystem', 'name' => 'ThinkSystem', 'description' => 'Server untuk aplikasi dan layanan pusat data.'],
                    ],
                ],
                [
                    'template_key' => 'hewlett-packard-enterprise',
                    'name' => 'Hewlett Packard Enterprise',
                    'description' => 'Pabrikan server, jaringan, dan penyimpanan data untuk pusat data dan fasilitas TI.',
                    'models' => [
                        ['template_key' => 'proliant', 'name' => 'ProLiant', 'description' => 'Server untuk aplikasi, jaringan, dan layanan pusat data.'],
                        ['template_key' => 'aruba', 'name' => 'Aruba', 'description' => 'Perangkat jaringan dan akses nirkabel untuk fasilitas.'],
                        ['template_key' => 'storeeasy', 'name' => 'StoreEasy', 'description' => 'Penyimpanan data untuk file dan kebutuhan kantor.'],
                    ],
                ],
                [
                    'template_key' => 'acer',
                    'name' => 'Acer',
                    'description' => 'Pabrikan komputer, laptop, dan server untuk pekerjaan kantor serta pendidikan.',
                    'models' => [
                        ['template_key' => 'veriton', 'name' => 'Veriton', 'description' => 'Komputer desktop untuk pekerjaan kantor.'],
                        ['template_key' => 'travelmate', 'name' => 'TravelMate', 'description' => 'Laptop untuk pekerjaan kantor dan mobilitas.'],
                        ['template_key' => 'altos', 'name' => 'Altos', 'description' => 'Server untuk aplikasi dan infrastruktur TI.'],
                    ],
                ],
                [
                    'template_key' => 'asus',
                    'name' => 'ASUS',
                    'description' => 'Pabrikan komputer, laptop, dan server untuk kantor, desain, serta infrastruktur TI.',
                    'models' => [
                        ['template_key' => 'expertbook', 'name' => 'ExpertBook', 'description' => 'Laptop untuk pekerjaan kantor dan mobilitas.'],
                        ['template_key' => 'expertcenter', 'name' => 'ExpertCenter', 'description' => 'Komputer desktop untuk pekerjaan kantor.'],
                        ['template_key' => 'rs-series', 'name' => 'RS Series', 'description' => 'Server untuk aplikasi dan layanan infrastruktur TI.'],
                    ],
                ],
                [
                    'template_key' => 'samsung',
                    'name' => 'Samsung',
                    'description' => 'Pabrikan perangkat komputer, layar, perangkat bergerak, dan solusi fasilitas digital.',
                    'models' => [
                        ['template_key' => 'galaxy', 'name' => 'Galaxy', 'description' => 'Perangkat bergerak untuk komunikasi dan pekerjaan lapangan.'],
                        ['template_key' => 'smart-monitor', 'name' => 'Smart Monitor', 'description' => 'Layar kerja untuk kantor dan ruang kontrol.'],
                        ['template_key' => 'knox', 'name' => 'Knox', 'description' => 'Perangkat dan solusi pengelolaan keamanan perangkat.'],
                        ['template_key' => 'dvm', 'name' => 'DVM', 'description' => 'Sistem tata udara untuk gedung dan fasilitas.'],
                        ['template_key' => 'windfree', 'name' => 'WindFree', 'description' => 'Sistem tata udara untuk ruang kerja dan fasilitas.'],
                    ],
                ],
                [
                    'template_key' => 'huawei',
                    'name' => 'Huawei',
                    'description' => 'Pabrikan server, penyimpanan, dan jaringan untuk infrastruktur teknologi informasi.',
                    'models' => [
                        ['template_key' => 'fusionserver', 'name' => 'FusionServer', 'description' => 'Server untuk aplikasi dan pusat data.'],
                        ['template_key' => 'oceanstor', 'name' => 'OceanStor', 'description' => 'Penyimpanan data untuk kebutuhan pusat data.'],
                        ['template_key' => 'netengine', 'name' => 'NetEngine', 'description' => 'Perangkat jaringan untuk konektivitas fasilitas.'],
                    ],
                ],
                [
                    'template_key' => 'cisco',
                    'name' => 'Cisco',
                    'description' => 'Pabrikan perangkat jaringan dan server untuk kantor, fasilitas, dan pusat data.',
                    'models' => [
                        ['template_key' => 'catalyst', 'name' => 'Catalyst', 'description' => 'Switch dan perangkat jaringan untuk fasilitas.'],
                        ['template_key' => 'nexus', 'name' => 'Nexus', 'description' => 'Switch jaringan untuk pusat data.'],
                        ['template_key' => 'ucs', 'name' => 'UCS', 'description' => 'Sistem komputasi terpadu untuk pusat data.'],
                    ],
                ],
                [
                    'template_key' => 'daikin',
                    'name' => 'Daikin',
                    'description' => 'Pabrikan sistem tata udara untuk gedung, kantor, dan fasilitas industri.',
                    'models' => [
                        ['template_key' => 'vrv', 'name' => 'VRV', 'description' => 'Sistem tata udara multi-unit untuk gedung dan fasilitas.'],
                        ['template_key' => 'skyair', 'name' => 'SkyAir', 'description' => 'Sistem tata udara untuk ruang kerja dan komersial.'],
                        ['template_key' => 'inverter-split', 'name' => 'Inverter Split', 'description' => 'Pendingin ruangan untuk kantor dan ruang kerja.'],
                    ],
                ],
                [
                    'template_key' => 'panasonic',
                    'name' => 'Panasonic',
                    'description' => 'Pabrikan sistem tata udara dan peralatan fasilitas untuk gedung serta kantor.',
                    'models' => [
                        ['template_key' => 'paci', 'name' => 'PACi', 'description' => 'Sistem tata udara untuk ruang komersial dan fasilitas.'],
                        ['template_key' => 'ecoi', 'name' => 'ECOi', 'description' => 'Sistem tata udara multi-unit untuk gedung.'],
                        ['template_key' => 'vrf', 'name' => 'VRF', 'description' => 'Sistem tata udara variabel untuk fasilitas dan gedung.'],
                    ],
                ],
                [
                    'template_key' => 'lg',
                    'name' => 'LG',
                    'description' => 'Pabrikan sistem tata udara dan peralatan elektronik untuk gedung serta kantor.',
                    'models' => [
                        ['template_key' => 'multi-v', 'name' => 'Multi V', 'description' => 'Sistem tata udara multi-unit untuk gedung.'],
                        ['template_key' => 'inverter-split', 'name' => 'Inverter Split', 'description' => 'Pendingin ruangan untuk kantor dan ruang kerja.'],
                    ],
                ],
                [
                    'template_key' => 'gree',
                    'name' => 'Gree',
                    'description' => 'Pabrikan sistem pendingin dan tata udara untuk rumah, kantor, dan fasilitas.',
                    'models' => [
                        ['template_key' => 'gmv', 'name' => 'GMV', 'description' => 'Sistem tata udara multi-unit untuk gedung dan fasilitas.'],
                        ['template_key' => 'u-match', 'name' => 'U-Match', 'description' => 'Sistem tata udara untuk ruang komersial dan kantor.'],
                        ['template_key' => 'chiller', 'name' => 'Chiller', 'description' => 'Sistem pendingin air untuk fasilitas dan proses.'],
                    ],
                ],
                [
                    'template_key' => 'grundfos',
                    'name' => 'Grundfos',
                    'description' => 'Pabrikan pompa dan sistem pemindahan air untuk gedung, utilitas, dan industri.',
                    'models' => [
                        ['template_key' => 'cr', 'name' => 'CR', 'description' => 'Pompa multistage untuk air bersih dan utilitas.'],
                        ['template_key' => 'nb', 'name' => 'NB', 'description' => 'Pompa sentrifugal untuk fasilitas dan proses.'],
                        ['template_key' => 'nk', 'name' => 'NK', 'description' => 'Pompa sentrifugal untuk sistem air dan industri.'],
                    ],
                ],
                [
                    'template_key' => 'ebara',
                    'name' => 'Ebara',
                    'description' => 'Pabrikan pompa untuk air bersih, utilitas gedung, dan proses industri.',
                    'models' => [
                        ['template_key' => '3d', 'name' => '3D', 'description' => 'Pompa sentrifugal untuk sistem air dan utilitas.'],
                        ['template_key' => 'evms', 'name' => 'EVMS', 'description' => 'Pompa multistage untuk distribusi air dan fasilitas.'],
                        ['template_key' => 'gs', 'name' => 'GS', 'description' => 'Pompa untuk air, drainase, dan kebutuhan utilitas.'],
                    ],
                ],
                [
                    'template_key' => 'torishima',
                    'name' => 'Torishima',
                    'description' => 'Pabrikan pompa untuk utilitas air, pembangkit, dan proses industri.',
                    'models' => [
                        ['template_key' => 'mhs', 'name' => 'MHS', 'description' => 'Pompa untuk sistem air dan utilitas fasilitas.'],
                        ['template_key' => 'cdm', 'name' => 'CDM', 'description' => 'Pompa untuk kebutuhan proses dan sirkulasi.'],
                        ['template_key' => 'gsp', 'name' => 'GSP', 'description' => 'Pompa untuk distribusi air dan fasilitas industri.'],
                    ],
                ],
                [
                    'template_key' => 'wilo',
                    'name' => 'Wilo',
                    'description' => 'Pabrikan pompa dan sistem air untuk gedung, utilitas, dan fasilitas industri.',
                    'models' => [
                        ['template_key' => 'helix', 'name' => 'Helix', 'description' => 'Pompa multistage untuk suplai dan distribusi air.'],
                        ['template_key' => 'cronoline', 'name' => 'CronoLine', 'description' => 'Pompa untuk sirkulasi dan utilitas gedung.'],
                        ['template_key' => 'rexa', 'name' => 'Rexa', 'description' => 'Pompa submersible untuk air limbah dan drainase.'],
                    ],
                ],
                [
                    'template_key' => 'ksb',
                    'name' => 'KSB',
                    'description' => 'Pabrikan pompa dan katup untuk utilitas air, gedung, dan proses industri.',
                    'models' => [
                        ['template_key' => 'etanorm', 'name' => 'Etanorm', 'description' => 'Pompa sentrifugal untuk fasilitas dan proses.'],
                        ['template_key' => 'omega', 'name' => 'Omega', 'description' => 'Pompa untuk sistem air dan utilitas industri.'],
                        ['template_key' => 'movitec', 'name' => 'Movitec', 'description' => 'Pompa multistage untuk suplai air dan fasilitas.'],
                    ],
                ],
                [
                    'template_key' => 'shimadzu',
                    'name' => 'Shimadzu',
                    'description' => 'Pabrikan peralatan laboratorium dan analisis untuk industri, pendidikan, dan layanan teknis.',
                    'models' => [
                        ['template_key' => 'lc-2030', 'name' => 'LC-2030', 'description' => 'Peralatan kromatografi cair untuk analisis laboratorium.'],
                        ['template_key' => 'gc-2030', 'name' => 'GC-2030', 'description' => 'Peralatan kromatografi gas untuk analisis laboratorium.'],
                        ['template_key' => 'edx', 'name' => 'EDX', 'description' => 'Peralatan analisis unsur untuk pemeriksaan material.'],
                    ],
                ],
                [
                    'template_key' => 'olympus',
                    'name' => 'Olympus',
                    'description' => 'Pabrikan peralatan pemeriksaan, mikroskop, dan endoskopi untuk laboratorium serta layanan kesehatan.',
                    'models' => [
                        ['template_key' => 'evis', 'name' => 'EVIS', 'description' => 'Sistem endoskopi untuk pemeriksaan dan layanan kesehatan.'],
                        ['template_key' => 'cx23', 'name' => 'CX23', 'description' => 'Mikroskop untuk pemeriksaan laboratorium dan pendidikan.'],
                        ['template_key' => 'bx53', 'name' => 'BX53', 'description' => 'Mikroskop untuk analisis dan pemeriksaan laboratorium.'],
                    ],
                ],
                [
                    'template_key' => 'sysmex',
                    'name' => 'Sysmex',
                    'description' => 'Pabrikan peralatan analisis hematologi dan laboratorium untuk layanan kesehatan.',
                    'models' => [
                        ['template_key' => 'xn-1000', 'name' => 'XN-1000', 'description' => 'Penganalisis hematologi untuk laboratorium.'],
                        ['template_key' => 'xp-300', 'name' => 'XP-300', 'description' => 'Penganalisis darah untuk pemeriksaan laboratorium.'],
                        ['template_key' => 'cs-2500', 'name' => 'CS-2500', 'description' => 'Penganalisis koagulasi untuk layanan laboratorium.'],
                    ],
                ],
                [
                    'template_key' => 'mindray',
                    'name' => 'Mindray',
                    'description' => 'Pabrikan peralatan laboratorium dan layanan kesehatan untuk rumah sakit serta klinik.',
                    'models' => [
                        ['template_key' => 'bc-6800', 'name' => 'BC-6800', 'description' => 'Penganalisis hematologi untuk laboratorium.'],
                        ['template_key' => 'bs-2000', 'name' => 'BS-2000', 'description' => 'Penganalisis kimia klinis untuk laboratorium.'],
                        ['template_key' => 'beneheart', 'name' => 'BeneHeart', 'description' => 'Peralatan pemantauan dan penanganan pasien.'],
                    ],
                ],
                [
                    'template_key' => 'fujifilm',
                    'name' => 'Fujifilm',
                    'description' => 'Pabrikan peralatan pencitraan, ultrasound, dan sistem informasi layanan kesehatan.',
                    'models' => [
                        ['template_key' => 'fdr', 'name' => 'FDR', 'description' => 'Sistem pencitraan digital untuk pemeriksaan medis.'],
                        ['template_key' => 'sonosite', 'name' => 'SonoSite', 'description' => 'Peralatan ultrasound untuk pemeriksaan medis.'],
                        ['template_key' => 'synapse', 'name' => 'Synapse', 'description' => 'Sistem pengelolaan dan penyimpanan citra medis.'],
                    ],
                ],
                [
                    'template_key' => 'canon',
                    'name' => 'Canon',
                    'description' => 'Pabrikan perangkat cetak, pemindai, dan pencitraan untuk kebutuhan kantor serta produksi.',
                    'models' => [
                        ['template_key' => 'imagerunner', 'name' => 'imageRUNNER', 'description' => 'Perangkat multifungsi untuk cetak, salin, dan pindai dokumen.'],
                        ['template_key' => 'imagepress', 'name' => 'imagePRESS', 'description' => 'Perangkat cetak produksi untuk dokumen dan materi kantor.'],
                        ['template_key' => 'i-sensys', 'name' => 'i-SENSYS', 'description' => 'Printer dan perangkat multifungsi untuk kantor.'],
                    ],
                ],
                [
                    'template_key' => 'epson',
                    'name' => 'Epson',
                    'description' => 'Pabrikan printer, proyektor, dan perangkat cetak untuk kantor serta produksi.',
                    'models' => [
                        ['template_key' => 'workforce', 'name' => 'WorkForce', 'description' => 'Printer dan pemindai untuk pekerjaan kantor.'],
                        ['template_key' => 'surecolor', 'name' => 'SureColor', 'description' => 'Printer warna untuk desain dan produksi.'],
                        ['template_key' => 'ecotank', 'name' => 'EcoTank', 'description' => 'Printer tangki tinta untuk pekerjaan kantor.'],
                    ],
                ],
                [
                    'template_key' => 'brother',
                    'name' => 'Brother',
                    'description' => 'Pabrikan printer, pemindai, dan perangkat label untuk kantor serta operasional.',
                    'models' => [
                        ['template_key' => 'hl-series', 'name' => 'HL Series', 'description' => 'Printer untuk kebutuhan dokumen kantor.'],
                        ['template_key' => 'mfc-series', 'name' => 'MFC Series', 'description' => 'Perangkat multifungsi untuk cetak, salin, dan pindai.'],
                    ],
                ],
                [
                    'template_key' => 'ricoh',
                    'name' => 'Ricoh',
                    'description' => 'Pabrikan perangkat cetak, dokumen, dan layanan ruang kerja digital.',
                    'models' => [
                        ['template_key' => 'im-c-series', 'name' => 'IM C-Series', 'description' => 'Perangkat multifungsi warna untuk kantor.'],
                        ['template_key' => 'm-c-series', 'name' => 'M C-Series', 'description' => 'Perangkat multifungsi untuk dokumen kantor.'],
                    ],
                ],
                [
                    'template_key' => 'konica-minolta',
                    'name' => 'Konica Minolta',
                    'description' => 'Pabrikan perangkat cetak dan pengelolaan dokumen untuk kantor serta produksi.',
                    'models' => [
                        ['template_key' => 'bizhub', 'name' => 'bizhub', 'description' => 'Perangkat multifungsi untuk dokumen dan pekerjaan kantor.'],
                        ['template_key' => 'accuriopress', 'name' => 'AccurioPress', 'description' => 'Perangkat cetak produksi untuk kebutuhan dokumen dan materi.'],
                    ],
                ],
                [
                    'template_key' => 'iseki',
                    'name' => 'Iseki',
                    'description' => 'Pabrikan traktor dan mesin pertanian yang digunakan untuk pengolahan lahan.',
                    'models' => [
                        ['template_key' => 'tle', 'name' => 'TLE', 'description' => 'Traktor untuk pekerjaan pengolahan lahan.'],
                        ['template_key' => 'tm', 'name' => 'TM', 'description' => 'Traktor serbaguna untuk kegiatan pertanian.'],
                        ['template_key' => 'th', 'name' => 'TH', 'description' => 'Traktor untuk pekerjaan lahan dan pertanian.'],
                    ],
                ],
                [
                    'template_key' => 'honda-power-products',
                    'name' => 'Honda Power Products',
                    'description' => 'Pabrikan mesin dan peralatan bertenaga untuk pertanian, pompa, dan pekerjaan lapangan.',
                    'models' => [
                        ['template_key' => 'wb', 'name' => 'WB', 'description' => 'Pompa air untuk kebutuhan lapangan dan pertanian.'],
                        ['template_key' => 'wt', 'name' => 'WT', 'description' => 'Pompa air untuk drainase dan pekerjaan operasional.'],
                        ['template_key' => 'gx-series', 'name' => 'GX Series', 'description' => 'Mesin serbaguna untuk pompa, genset, dan peralatan kerja.'],
                    ],
                ],
                [
                    'template_key' => 'huawei-fusionsolar',
                    'name' => 'Huawei FusionSolar',
                    'description' => 'Pabrikan solusi inverter, penyimpanan, dan pemantauan energi surya.',
                    'models' => [
                        ['template_key' => 'sun2000', 'name' => 'SUN2000', 'description' => 'Inverter untuk sistem pembangkit listrik tenaga surya.'],
                        ['template_key' => 'luna2000', 'name' => 'LUNA2000', 'description' => 'Sistem penyimpanan energi untuk instalasi surya.'],
                        ['template_key' => 'smartlogger', 'name' => 'SmartLogger', 'description' => 'Perangkat pemantauan dan pengelolaan instalasi surya.'],
                    ],
                ],
                [
                    'template_key' => 'sungrow',
                    'name' => 'Sungrow',
                    'description' => 'Pabrikan inverter, penyimpanan energi, dan sistem surya untuk fasilitas serta pembangkit.',
                    'models' => [
                        ['template_key' => 'sg125cx', 'name' => 'SG125CX', 'description' => 'Inverter untuk pembangkit surya komersial.'],
                        ['template_key' => 'sg350hx', 'name' => 'SG350HX', 'description' => 'Inverter berkapasitas besar untuk pembangkit surya.'],
                        ['template_key' => 'powertitan', 'name' => 'PowerTitan', 'description' => 'Sistem penyimpanan energi untuk fasilitas dan pembangkit.'],
                    ],
                ],
                [
                    'template_key' => 'longi',
                    'name' => 'LONGi',
                    'description' => 'Pabrikan modul surya untuk instalasi atap, komersial, dan pembangkit.',
                    'models' => [
                        ['template_key' => 'hi-mo', 'name' => 'Hi-MO', 'description' => 'Seri modul surya untuk pembangkit dan fasilitas.'],
                        ['template_key' => 'lr5', 'name' => 'LR5', 'description' => 'Seri modul surya untuk instalasi tenaga surya.'],
                        ['template_key' => 'lr7', 'name' => 'LR7', 'description' => 'Seri modul surya untuk pembangkit dan instalasi komersial.'],
                    ],
                ],
                [
                    'template_key' => 'jinkosolar',
                    'name' => 'JinkoSolar',
                    'description' => 'Pabrikan modul surya dan sistem penyimpanan energi untuk fasilitas serta pembangkit.',
                    'models' => [
                        ['template_key' => 'tiger-neo', 'name' => 'Tiger Neo', 'description' => 'Seri modul surya untuk instalasi komersial dan pembangkit.'],
                        ['template_key' => 'tiger-pro', 'name' => 'Tiger Pro', 'description' => 'Seri modul surya untuk fasilitas dan pembangkit.'],
                        ['template_key' => 'sungiga', 'name' => 'SunGiga', 'description' => 'Sistem penyimpanan energi untuk kebutuhan pembangkit.'],
                    ],
                ],
                [
                    'template_key' => 'trina-solar',
                    'name' => 'Trina Solar',
                    'description' => 'Pabrikan modul surya dan solusi energi untuk instalasi atap serta pembangkit.',
                    'models' => [
                        ['template_key' => 'vertex', 'name' => 'Vertex', 'description' => 'Seri modul surya untuk pembangkit dan fasilitas.'],
                        ['template_key' => 'vertex-s', 'name' => 'Vertex S', 'description' => 'Seri modul surya untuk instalasi atap.'],
                        ['template_key' => 'vanguard', 'name' => 'Vanguard', 'description' => 'Seri modul surya untuk fasilitas dan pembangkit.'],
                    ],
                ],
            ],
        ],
        'maintenance' => [
            'template_key' => 'id:maintenance:starter:v1',
            'categories' => [
                ['code' => 'preventive', 'label' => 'Preventif'],
                ['code' => 'corrective', 'label' => 'Korektif'],
                ['code' => 'service', 'label' => 'Servis'],
                ['code' => 'condition_assessment', 'label' => 'Pemeriksaan kondisi'],
            ],
            'work_order_types' => [
                ['template_key' => 'id:maintenance:work-order-type:korektif:v1', 'name' => 'Korektif', 'description' => 'Pekerjaan untuk memulihkan aset yang mengalami kerusakan.'],
                ['template_key' => 'id:maintenance:work-order-type:preventif:v1', 'name' => 'Preventif', 'description' => 'Pekerjaan terjadwal untuk mencegah kerusakan.'],
                ['template_key' => 'id:maintenance:work-order-type:servis:v1', 'name' => 'Servis', 'description' => 'Pekerjaan layanan umum pada aset.'],
                ['template_key' => 'id:maintenance:work-order-type:pemeriksaan-kondisi:v1', 'name' => 'Pemeriksaan kondisi', 'description' => 'Pemeriksaan kondisi aset dan area kerja.'],
            ],
            'service_levels' => [
                ['template_key' => 'id:maintenance:service-level:kritis:v1', 'name' => 'Kritis', 'description' => 'Pekerjaan harus ditangani segera.', 'order' => 1],
                ['template_key' => 'id:maintenance:service-level:tinggi:v1', 'name' => 'Tinggi', 'description' => 'Pekerjaan diprioritaskan setelah kondisi kritis.', 'order' => 2],
                ['template_key' => 'id:maintenance:service-level:normal:v1', 'name' => 'Normal', 'description' => 'Pekerjaan mengikuti antrean biasa.', 'order' => 3],
                ['template_key' => 'id:maintenance:service-level:rendah:v1', 'name' => 'Rendah', 'description' => 'Pekerjaan dapat dijadwalkan setelah kebutuhan yang lebih mendesak.', 'order' => 4],
            ],
            'trades' => [
                ['template_key' => 'id:maintenance:trade:mekanik:v1', 'name' => 'Mekanik', 'description' => 'Pekerjaan mekanik dan komponen bergerak.'],
                ['template_key' => 'id:maintenance:trade:elektrik:v1', 'name' => 'Elektrik', 'description' => 'Pekerjaan kelistrikan dan panel.'],
                ['template_key' => 'id:maintenance:trade:hvac:v1', 'name' => 'HVAC', 'description' => 'Pekerjaan tata udara dan pendingin.'],
                ['template_key' => 'id:maintenance:trade:teknisi-umum:v1', 'name' => 'Teknisi umum', 'description' => 'Pekerjaan teknis umum pada aset.'],
            ],
            'job_types' => [
                ['template_key' => 'id:maintenance:job-type:inspeksi:v1', 'name' => 'Inspeksi', 'category' => 'preventive', 'description' => 'Pemeriksaan rutin untuk memastikan aset tetap aman digunakan.'],
                ['template_key' => 'id:maintenance:job-type:kalibrasi:v1', 'name' => 'Kalibrasi', 'category' => 'preventive', 'description' => 'Penyetelan dan pemeriksaan ketepatan alat ukur.'],
                ['template_key' => 'id:maintenance:job-type:pelumasan:v1', 'name' => 'Pelumasan', 'category' => 'preventive', 'description' => 'Pemberian pelumas pada bagian yang bergerak.'],
                ['template_key' => 'id:maintenance:job-type:preventif:v1', 'name' => 'Preventif', 'category' => 'preventive', 'description' => 'Pekerjaan terjadwal untuk mencegah kerusakan.'],
                ['template_key' => 'id:maintenance:job-type:perbaikan:v1', 'name' => 'Perbaikan', 'category' => 'corrective', 'description' => 'Pekerjaan untuk memulihkan aset yang mengalami kerusakan.'],
                ['template_key' => 'id:maintenance:job-type:servis:v1', 'name' => 'Servis', 'category' => 'service', 'description' => 'Pekerjaan layanan umum pada aset.'],
                ['template_key' => 'id:maintenance:job-type:pemeriksaan-kondisi:v1', 'name' => 'Pemeriksaan kondisi fasilitas', 'category' => 'condition_assessment', 'description' => 'Pemeriksaan kondisi fasilitas dan area kerja.'],
            ],
            'variants' => [
                ['template_key' => 'id:maintenance:variant:mingguan:v1', 'name' => 'Mingguan'],
                ['template_key' => 'id:maintenance:variant:bulanan:v1', 'name' => 'Bulanan'],
                ['template_key' => 'id:maintenance:variant:triwulanan:v1', 'name' => 'Triwulanan'],
                ['template_key' => 'id:maintenance:variant:semesteran:v1', 'name' => 'Semesteran'],
                ['template_key' => 'id:maintenance:variant:tahunan:v1', 'name' => 'Tahunan'],
                ['template_key' => 'id:maintenance:variant:1000-jam:v1', 'name' => '1000 jam'],
            ],
            'checklist_variables' => [
                [
                    'template_key' => 'id:maintenance:variable:kualitas-oli:v1',
                    'name' => 'Kualitas oli',
                    'description' => 'Pilihan hasil pemeriksaan warna dan kejernihan oli.',
                    'values' => [
                        ['line_number' => 1, 'value' => 'Cokelat muda dan jernih', 'result_code' => 'pass'],
                        ['line_number' => 2, 'value' => 'Keruh', 'result_code' => 'fail'],
                        ['line_number' => 3, 'value' => 'Hitam', 'result_code' => 'fail'],
                    ],
                ],
            ],
            'checklist_templates' => [
                [
                    'template_key' => 'id:maintenance:template:pemeriksaan-conveyor:v1',
                    'name' => 'Pemeriksaan conveyor',
                    'description' => 'Checklist dasar untuk pemeriksaan conveyor.',
                    'lines' => [
                        ['line_number' => 1, 'type' => 'header', 'name' => 'Pemeriksaan conveyor'],
                        ['line_number' => 2, 'type' => 'text', 'name' => 'Periksa ketegangan belt', 'mandatory' => true, 'instructions' => 'Pastikan belt tidak terlalu longgar atau terlalu kencang.'],
                        ['line_number' => 3, 'type' => 'measurement', 'unit' => 'cm', 'name' => 'Celah roller', 'mandatory' => true, 'instructions' => 'Masukkan hasil pengukuran dalam sentimeter.'],
                        ['line_number' => 4, 'type' => 'variable', 'variable_key' => 'id:maintenance:variable:kualitas-oli:v1', 'name' => 'Kualitas oli', 'mandatory' => true, 'instructions' => 'Pilih kondisi oli yang ditemukan.'],
                    ],
                ],
            ],
            'defaults' => [
                ['template_key' => 'id:maintenance:default:inspeksi:mingguan:v1', 'name' => 'Inspeksi mingguan', 'job_type_key' => 'id:maintenance:job-type:inspeksi:v1', 'variant_key' => 'id:maintenance:variant:mingguan:v1', 'checklist_template_key' => 'id:maintenance:template:pemeriksaan-conveyor:v1', 'trade' => 'Mekanik', 'hours' => 2],
                ['template_key' => 'id:maintenance:default:preventif:tahunan:v1', 'name' => 'Preventif tahunan', 'job_type_key' => 'id:maintenance:job-type:preventif:v1', 'variant_key' => 'id:maintenance:variant:tahunan:v1', 'checklist_template_key' => 'id:maintenance:template:pemeriksaan-conveyor:v1', 'trade' => 'Teknisi umum', 'hours' => 4],
            ],
            /*
              * Aturan validasi perpindahan status work order. Seluruh kombinasi status x
              * aturan disemai dan aktif secara bawaan, supaya tenant langsung mendapat
              * alur maintenance yang lengkap. Tenant tetap dapat mematikan aturan yang
              * tidak dipakai dari halaman Syarat penyelesaian.
              */
            'status_validations' => [
                ['status' => 'dijadwalkan', 'aturan' => 'checklist_wajib', 'aktif' => true, 'keparahan' => 'informasi'],
                ['status' => 'dijadwalkan', 'aturan' => 'sebab_kerusakan', 'aktif' => true, 'keparahan' => 'informasi'],
                ['status' => 'dijadwalkan', 'aturan' => 'tindakan_perbaikan', 'aktif' => true, 'keparahan' => 'informasi'],
                ['status' => 'dikerjakan', 'aturan' => 'checklist_wajib', 'aktif' => true, 'keparahan' => 'informasi'],
                ['status' => 'dikerjakan', 'aturan' => 'sebab_kerusakan', 'aktif' => true, 'keparahan' => 'informasi'],
                ['status' => 'dikerjakan', 'aturan' => 'tindakan_perbaikan', 'aktif' => true, 'keparahan' => 'informasi'],
                ['status' => 'selesai', 'aturan' => 'checklist_wajib', 'aktif' => true, 'keparahan' => 'error'],
                ['status' => 'selesai', 'aturan' => 'sebab_kerusakan', 'aktif' => true, 'keparahan' => 'peringatan'],
                ['status' => 'selesai', 'aturan' => 'tindakan_perbaikan', 'aktif' => true, 'keparahan' => 'peringatan'],
                ['status' => 'ditutup', 'aturan' => 'checklist_wajib', 'aktif' => true, 'keparahan' => 'error'],
                ['status' => 'ditutup', 'aturan' => 'sebab_kerusakan', 'aktif' => true, 'keparahan' => 'error'],
                ['status' => 'ditutup', 'aturan' => 'tindakan_perbaikan', 'aktif' => true, 'keparahan' => 'error'],
            ],
        ],
    ],
];
