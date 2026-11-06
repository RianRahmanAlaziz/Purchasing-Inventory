# RIS Purchasing

Antarmuka Next.js untuk API Laravel Purchasing & Inventory. Semua halaman operasional menggunakan data server dan izin dari akun yang masuk.

## Menjalankan aplikasi

Jalankan Laravel dan MySQL terlebih dahulu, lalu dari direktori frontend:

~~~powershell
npm ci
npm run dev
~~~

Buka [halaman masuk](http://localhost:3000/login). Gateway menggunakan Laravel di http://127.0.0.1:8000. Untuk alamat lain, isi LARAVEL_API_URL pada .env.local dengan origin Laravel tanpa /api/v1. Untuk reverse proxy, APP_ORIGIN harus sesuai origin publik Next.js.

Sesi memakai cookie HttpOnly. Permintaan JSON dan unggah dokumen melewati gateway Next.js; browser tidak menerima bearer token Laravel.

## Modul terhubung

- Profil Perusahaan: tambah, edit, hapus, logo, dan pilihan PT aktif per akun.
- Master supplier, kategori, barang, departemen, dan karyawan.
- Jenis dan tarif pajak, termasuk masa berlaku serta pajak tambahan/potongan.
- Purchase Order: tambah, baca, edit Draft, hapus sesuai status, persetujuan, pengiriman, konfirmasi supplier, dan cetak.
- Order, penerimaan barang, stok, mutasi stok, dan distribusi.
- Tagihan Supplier, pembayaran bertahap, dan verifikasi pembayaran.
- Kuitansi & Dokumen: CRUD, unggah privat, unduh sesuai izin, kirim, terima, dan verifikasi.
- Dashboard, pengguna, role, permission, dan audit RBAC.

Isi master PT, kategori, serta karyawan/departemen sebelum membuat PO. Supplier dan barang dapat diketik bebas atau dipilih dari sugesti riwayat PO/stok. Nama baru disimpan bersama PO sesuai hak akses; barang baru memerlukan kategori. Pemilihan sugesti menautkan ID dan satuan stok yang sesuai. Angka desimal dikirim sebagai string; nilai akhir dihitung ulang oleh Laravel.

Form PO mempertahankan tampilan dokumen, dropdown pajak/satuan, serta textarea alamat dan catatan. Kop surat memakai snapshot perusahaan pada PO sehingga perubahan atau penghapusan profil PT tidak mengubah surat lama.

## Data browser dari versi sebelumnya

Pengelola dapat memakai tombol Impor PT dari browser pada Profil Perusahaan. Impor menambahkan profil yang belum ada dan mempertahankan salinan lama. Draft PO lama dimuat saat membuka form baru; lengkapi pilihan master sebelum menyimpannya. Salinan draft dihapus hanya setelah penyimpanan server berhasil.

Data demo pembayaran/dokumen lama tidak otomatis menjadi transaksi server. Key penyimpanan lama tetap dipertahankan. Tidak ada data contoh yang ditambahkan otomatis ke database operasional.

## Pemeriksaan

~~~powershell
npm run lint
npx tsc --noEmit
node --test (rg --files -g '*.test.mjs' -g '!node_modules')
npm run build
~~~

Lihat [panduan proyek](../README.md) dan [kontrak modul terhubung](../docs/api/CONNECTED_MODULES.md).
