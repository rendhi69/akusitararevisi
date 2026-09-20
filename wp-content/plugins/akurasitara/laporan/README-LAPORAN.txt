Struktur modul laporan:

1. akurasitara-laporan-dsl.php
   Loader modul laporan. File ini dipanggil dari akurasitara.php.

2. akurasitara-ext-reports-dsl-role.php
   Isi fitur Laporan DSL, termasuk akses Admin, Kepala Unit, dan Pengolah Data.

3. assets/laporan-dsl.css
   CSS tambahan khusus halaman Laporan DSL.

Catatan:
- Plugin utama tetap berada di akurasitara/akurasitara.php.
- Modul laporan tetap dipisah di folder akurasitara/laporan/.
- Fitur terbaru (v2.2.1): Memiliki Template & Riwayat Rumus DSL (sehingga rumus manual dapat disimpan/dipakai ulang untuk laporan baru) serta Auto-Save pilihan mode perhitungan & responden di halaman Lihat Hasil.
- Jangan load file laporan lama lain yang memiliki class AkurasiTara_Ext_Reports_DSL secara bersamaan.

