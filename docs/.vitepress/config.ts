import { withMermaid } from 'vitepress-plugin-mermaid'

// Sidebar disusun menurut pekerjaan, bukan menurut nomor file.
// Nomor file dipertahankan apa adanya karena repo app lain menautkannya
// lewat nama file, termasuk relative path lintas repo.
export default withMermaid({
  lang: 'id-ID',
  title: 'CoreERP',
  description: 'Dokumentasi platform ERP modular API-first',
  // KOnsepawal.md dan konsepDirectory.md di root docs/ adalah duplikat
  // byte-identik dari salinan di references/. Berkasnya dibiarkan agar tautan
  // lama tidak putus, tapi hanya salinan references/ yang dirender jadi halaman.
  // apps/_template adalah cetakan untuk disalin, bukan halaman situs.
  srcExclude: [
    '**/node_modules/**',
    'KOnsepawal.md',
    'konsepDirectory.md',
    'apps/_template/**',
  ],
  cleanUrls: true,

  // Tanggal "terakhir diperbarui" dibaca dari git log. Build image memakai
  // context `docs/` yang tidak membawa `.git`, jadi dimatikan di sana lewat
  // DOCS_NO_GIT=true. Di host dan CI (yang punya repo) tetap menyala.
  lastUpdated: process.env.DOCS_NO_GIT !== 'true',

  // Berkas indeks tetap bernama README.md karena repo app lain menautkannya
  // lewat nama itu. Rewrite hanya mengubah URL-nya, bukan nama berkasnya.
  rewrites: {
    'dev/README.md': 'dev/index.md',
    'todo/README.md': 'todo/index.md',
    'todo/managementaset/README.md': 'todo/managementaset/index.md',
    'todo/addNewRulestoCoreforFleksibilitas/README.md':
      'todo/addNewRulestoCoreforFleksibilitas/index.md',
    'todo/entrypointPERMISSIONprevilage/README.md':
      'todo/entrypointPERMISSIONprevilage/index.md',
  },

  // Tautan yang memang bukan halaman dokumen. Selain pola ini, tautan mati
  // sengaja menggagalkan build supaya jembatan antar dokumen tidak diam-diam putus.
  ignoreDeadLinks: [
    /^\/settings\//, // rute aplikasi Control Plane
    /\.(php|tsx?|drawio|ya?ml|sh|png|pdf)$/, // berkas sumber dan aset
    /\.agents\//, // berkas skill di luar docs/
  ],

  themeConfig: {
    outline: { level: [2, 3], label: 'Di halaman ini' },

    nav: [
      { text: 'Mulai di sini', link: '/onboarding/' },
      { text: 'App', link: '/apps/' },
      { text: 'Desain kanonik', link: '/dev/' },
      { text: 'Referensi', link: '/references/' },
      { text: 'Backlog', link: '/todo/' },
    ],

    sidebar: {
      '/onboarding/': [
        {
          text: 'Mulai di sini',
          items: [
            { text: 'Selamat datang', link: '/onboarding/' },
            { text: 'Hari pertama', link: '/onboarding/hari-pertama' },
            { text: 'Menyiapkan lingkungan lokal', link: '/onboarding/setup' },
            { text: 'Glosarium', link: '/onboarding/glosarium' },
          ],
        },
        {
          text: 'Memahami sistem',
          items: [
            { text: 'Peta kode ke dokumen', link: '/onboarding/peta-kode' },
            { text: 'Alur end-to-end', link: '/onboarding/alur-end-to-end' },
            { text: 'Empat kebenaran lifecycle', link: '/onboarding/empat-kebenaran' },
          ],
        },
        {
          text: 'Ikut mengerjakan',
          items: [
            { text: 'Cara berkontribusi', link: '/onboarding/kontribusi' },
            { text: 'Definition of done', link: '/onboarding/definition-of-done' },
          ],
        },
      ],

      '/apps/': [
        {
          text: 'App',
          items: [
            { text: 'Katalog app', link: '/apps/' },
            { text: 'Membangun app baru', link: '/apps/membangun-app-baru' },
          ],
        },
        {
          text: 'App yang ada',
          items: [
            { text: 'Management Aset', link: '/apps/management-aset/' },
            { text: 'Human Resources', link: '/apps/human-resources/' },
          ],
        },
        {
          text: 'Management Aset — arsitektur',
          items: [
            { text: 'Peta modul', link: '/apps/management-aset/arsitektur/' },
            { text: 'Batas tenant dan organisasi', link: '/apps/management-aset/arsitektur/batas-tenant-dan-organisasi' },
            { text: 'Integrasi dengan Core', link: '/apps/management-aset/arsitektur/integrasi-core' },
            { text: 'Kontrak', link: '/apps/management-aset/arsitektur/kontrak' },
            { text: 'Database dan migration', link: '/apps/management-aset/arsitektur/database' },
            { text: 'Pengujian', link: '/apps/management-aset/arsitektur/pengujian' },
          ],
        },
        {
          text: 'Management Aset — master',
          items: [
            { text: 'Master data', link: '/apps/management-aset/master/' },
            { text: 'Group aset', link: '/apps/management-aset/master/groupaset/' },
            { text: 'Jenis aset dan atribut', link: '/apps/management-aset/master/jenisaset/' },
            { text: 'Pabrikan dan model', link: '/apps/management-aset/master/katalog-model/' },
            { text: 'Lokasi dan dimensi keuangan', link: '/apps/management-aset/master/lokasi/' },
            { text: 'Penyusutan: profil dan buku', link: '/apps/management-aset/master/depresiasi/' },
            { text: 'Setup maintenance', link: '/apps/management-aset/master/maintenance/' },
            { text: 'Master work order', link: '/apps/management-aset/master/work-order/' },
          ],
        },
        {
          text: 'Management Aset — transaksi',
          items: [
            { text: 'Perencanaan aset', link: '/apps/management-aset/transaction/perencanaan-aset/' },
            { text: 'Register aset', link: '/apps/management-aset/transaction/register-aset/' },
            { text: 'Penempatan dan mutasi', link: '/apps/management-aset/transaction/penempatan/' },
            { text: 'Proses penyusutan', link: '/apps/management-aset/transaction/penyusutan/' },
            { text: 'Pemeliharaan aset', link: '/apps/management-aset/transaction/pemeliharaan-aset/' },
            { text: 'Dokumen siklus aset', link: '/apps/management-aset/transaction/siklus-aset/' },
            { text: 'Monitoring dan layar kosong', link: '/apps/management-aset/transaction/monitoring/' },
          ],
        },
        {
          text: 'Menulis dokumen',
          items: [
            { text: 'Pola dokumen fitur', link: '/apps/management-aset/pola-dokumen' },
          ],
        },
      ],

      '/dev/': [
        {
          text: 'Desain kanonik',
          items: [{ text: 'Indeks dan peta dokumen', link: '/dev/' }],
        },
        {
          text: 'Fondasi platform',
          collapsed: false,
          items: [
            { text: 'Grand design dan boundary', link: '/dev/01-grand-design' },
            { text: 'Tenant dan hierarki organisasi', link: '/dev/01a-tenant-and-org-hierarchy' },
            { text: 'Query scope dan schema', link: '/dev/08-query-scopes-and-schema' },
            { text: 'Identity dan access', link: '/dev/09-identity-and-access' },
            { text: 'Gate fondasi Core', link: '/dev/10-core-foundation-gates' },
          ],
        },
        {
          text: 'Membangun app',
          collapsed: false,
          items: [
            { text: 'Jalur membangun app baru', link: '/apps/membangun-app-baru' },
            { text: 'Gate penemuan dan keputusan', link: '/dev/18-module-discovery-and-decision-gate' },
            { text: 'Standar module', link: '/dev/02-module-standard' },
            { text: 'Rantai keamanan modul transaksi', link: '/dev/19-transaction-security-chain' },
            { text: 'Visual workflow engine', link: '/dev/21-visual-workflow-engine' },
            { text: 'API dan integration bridge', link: '/dev/04-api-and-integration' },
            { text: 'Integrasi sistem eksternal', link: '/dev/12-external-module-integration' },
            { text: 'Kustomisasi dan addon', link: '/dev/05-customization-and-addons' },
          ],
        },
        {
          text: 'Reference data platform',
          collapsed: true,
          items: [
            { text: 'Number sequence', link: '/dev/14-number-sequences' },
            { text: 'Kalender fiskal', link: '/dev/15-fiscal-calendars' },
            { text: 'Satuan ukur', link: '/dev/16-units-of-measure' },
          ],
        },
        {
          text: 'Rilis dan operasi',
          collapsed: true,
          items: [
            { text: 'Release dan on-prem', link: '/dev/03-release-and-on-prem' },
            { text: 'Menerbitkan release app', link: '/dev/13-publishing-an-app-release' },
            { text: 'Development stack lokal', link: '/dev/11-local-docker-development' },
            { text: 'Reporting dan read replica', link: '/dev/07-reporting-and-replicas' },
            { text: 'Target worktree', link: '/dev/06-worktree-target' },
          ],
        },
        {
          text: 'Gate kualitas',
          collapsed: true,
          items: [
            { text: 'Load dan concurrency testing', link: '/dev/20-load-and-concurrency-testing' },
          ],
        },
        {
          text: 'Domain',
          collapsed: true,
          items: [
            { text: 'Healthcare finance subledger', link: '/dev/17-healthcare-finance-subledger' },
          ],
        },
      ],

      '/todo/': [
        {
          text: 'Backlog — bukan desain kanonik',
          items: [
            { text: 'Cara membaca folder ini', link: '/todo/' },
            {
              text: 'Audit fondasi terhadap D365',
              collapsed: false,
              items: [
                { text: 'Ringkasan dan jalur kritis', link: '/todo/general/00-ringkasan' },
                { text: 'Organisasi dan konsolidasi', link: '/todo/general/01-organisasi-dan-konsolidasi' },
                { text: 'Keamanan dan akses', link: '/todo/general/02-keamanan-dan-akses' },
                { text: 'Lifecycle dan deployment', link: '/todo/general/03-lifecycle-dan-deployment' },
                { text: 'Layanan platform', link: '/todo/general/04-layanan-platform' },
                { text: 'Fondasi finansial', link: '/todo/general/05-fondasi-finansial' },
                { text: 'App management aset', link: '/todo/general/06-app-management-aset' },
                { text: 'App procurement', link: '/todo/general/07-app-procurement' },
                { text: 'Peta app', link: '/todo/general/08-peta-app' },
                { text: 'Sudah dikerjakan', link: '/todo/general/99-sudah-dikerjakan' },
              ],
            },
            {
              text: 'Management aset',
              collapsed: true,
              items: [
                { text: 'Ikhtisar', link: '/todo/managementaset/' },
                { text: 'Keputusan arsitektur', link: '/todo/managementaset/00-keputusan-arsitektur' },
                { text: 'Workflow approval visual', link: '/todo/managementaset/01-core-workflow-approval-visual' },
                { text: 'Transaksi aset v1', link: '/todo/managementaset/02-transaksi-aset-v1' },
                { text: 'Penyusutan dan bridge', link: '/todo/managementaset/03-penyusutan-dan-bridge-backoffice' },
                { text: 'UI monitoring dan gate', link: '/todo/managementaset/04-ui-monitoring-laporan-dan-gate' },
              ],
            },
            {
              text: 'Usulan lain',
              collapsed: true,
              items: [
                { text: 'Aturan fleksibilitas Core', link: '/todo/addNewRulestoCoreforFleksibilitas/' },
                { text: 'Entrypoint permission', link: '/todo/entrypointPERMISSIONprevilage/' },
                { text: 'Dekomisioning aset', link: '/todo/entrypointPERMISSIONprevilage/dekomisioning-aset' },
              ],
            },
          ],
        },
      ],

      '/references/': [
        {
          text: 'Referensi eksternal',
          items: [
            { text: 'Daftar referensi', link: '/references/' },
            { text: 'Model organisasi Dynamics 365', link: '/references/dynamics-365-organization-model' },
            { text: 'Konsep awal (historis)', link: '/references/KOnsepawal' },
            { text: 'Konsep directory (historis)', link: '/references/konsepDirectory' },
          ],
        },
      ],
    },

    search: {
      provider: 'local',
      options: {
        translations: {
          button: { buttonText: 'Cari', buttonAriaLabel: 'Cari dokumen' },
          modal: {
            displayDetails: 'Tampilkan detail',
            resetButtonTitle: 'Hapus pencarian',
            backButtonTitle: 'Tutup',
            noResultsText: 'Tidak ada hasil untuk',
            footer: {
              selectText: 'pilih',
              navigateText: 'navigasi',
              closeText: 'tutup',
            },
          },
        },
      },
    },

    docFooter: { prev: 'Sebelumnya', next: 'Berikutnya' },
    darkModeSwitchLabel: 'Tampilan',
    returnToTopLabel: 'Kembali ke atas',
    sidebarMenuLabel: 'Menu',
    outlineTitle: 'Di halaman ini',
    lastUpdatedText: 'Terakhir diperbarui',
  },
})
