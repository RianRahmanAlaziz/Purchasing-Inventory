# RIS Purchasing

Antarmuka Next.js untuk modul Purchasing & Inventory. Data saat ini berupa data demo; belum terhubung dengan Laravel, autentikasi, atau database.

Jalankan dari direktori `frontend`:

```powershell
npm.cmd run dev
```

Buka http://localhost:3000/dashboard. Untuk build produksi, gunakan `npm.cmd run build` dan `npm.cmd start`.

Struktur proyek:

```text
app/
  (cms)/
    layout.tsx
    dashboard/page.tsx
    purchase-orders/page.tsx
    orders/page.tsx
    goods-receipts/page.tsx
    payments/page.tsx
    receipts/page.tsx
    items/page.tsx
    stock-movements/page.tsx
    distributions/page.tsx
    suppliers/page.tsx
    categories/page.tsx
    users/page.tsx
    profile/page.tsx
    help/page.tsx
  layout.tsx
  page.tsx
  globals.css
components/
  layouts/     # DashboardShell, Sidebar, TopNav
  ui/          # Button, Badge, Icon, Modal
features/
  dashboard/   # components, constants, views
  purchase-orders/ # components, hooks, mocks, types, views
  orders/      # views
  goods-receipts/
  payments/
  receipts/
  items/
  stock-movements/
  distributions/
  suppliers/
  categories/
  users/
  modules/     # tampilan tabel bersama dan data demo tiap modul
lib/
  utils.ts     # format rupiah dan ekspor CSV
public/
```

Fitur interaktif: navigasi tiap modul, sidebar mobile, pencarian PO dari header, pencarian dan filter tabel, detail data, grafik berdasarkan periode, ekspor CSV, notifikasi, dan pembuatan draft PO. Draft disimpan pada localStorage browser dengan key `stockly-orders`. Menghapus key tersebut mengembalikan data contoh ketika halaman dimuat ulang.

Komponen bersama diletakkan di `components`; route memanggil view dari `features` masing-masing. Nama modul mengikuti referensi screenshot folder Laravel, sedangkan implementasinya memakai Next.js sesuai proyek yang tersedia.

Fitur profil PT dan surat PO:

- Menu **Master Data > Profil Perusahaan** (`/companies`) untuk menambah atau mengedit beberapa PT, mengunggah logo PNG/JPG/WebP (maksimal 1 MB), dan memilih PT aktif.
- Nama, logo, alamat, telepon, dan email disimpan dengan key `stockly-companies-v1` di localStorage. PT aktif menjadi pilihan awal pada form PO.
- Form PO mendukung beberapa baris barang, satuan, jumlah, harga, diskon, pajak, tanggal kedatangan, termin pembayaran, dan catatan. Pajak dihitung setelah diskon pada tiap baris.
- PO baru menyimpan salinan identitas perusahaan. Mengedit profil PT kemudian tidak mengubah kop surat pada PO sebelumnya.
- Tabel PO dapat difilter berdasarkan PT. Klik nomor PO atau ikon dokumen untuk membuka pratinjau surat. **Cetak / Simpan PDF** membuka dialog cetak browser; pilih A4 dan matikan header/footer bawaan browser.
- Data PO lama yang belum mempunyai identitas PT tetap ditampilkan tanpa otomatis dikaitkan ke PT tertentu. Pencetakan dinonaktifkan untuk data tersebut. Buat PO baru dengan PT penerbit untuk surat lengkap.
- Format surat mengikuti referensi PDF: kop perusahaan, supplier, nomor SPB, pembeli, tanggal pesanan/kedatangan, tabel deskripsi/jumlah/harga/diskon/pajak, total dan termin pembayaran.

Pengujian perhitungan total:

```powershell
node --experimental-strip-types --test features/purchase-orders/tests/totals.test.mjs
```

Ini masih prototipe lokal. Profil beberapa PT belum mencakup isolasi database, hak akses per PT, atau sinkronisasi antar pengguna/perangkat. Identitas perusahaan di PO disimpan sebagai salinan historis di browser, bukan tanda tangan atau arsip dokumen yang tidak dapat diubah.

Persetujuan PO (menggantikan Order Management, tetap pada `/orders`):

- Pada daftar **Purchase Order**, klik **Ajukan Persetujuan** pada PO Draft, kemudian konfirmasi. PT penerbit, supplier, rincian barang, dan termin pembayaran harus lengkap. PO lama yang belum lengkap dapat diedit saat masih Draft.
- PO menjadi **Pending Approval** dan tidak dapat diedit atau dihapus. Buka **Persetujuan PO** untuk memeriksa surat PO.
- Tersedia tab **Menunggu Persetujuan**, **Disetujui**, dan **Ditolak**, pencarian, filter PT, pagination, dan ekspor CSV hasil filter pada tab aktif.
- Klik **Setuju** atau **Tidak Setuju** langsung tanpa mengisi nama pemeriksa atau alasan. Keputusan langsung mengubah status PO yang sama, termasuk pada daftar PO dan jumlah menunggu persetujuan di dashboard.
- Status dan waktu keputusan tersimpan bersama PO di `stockly-orders`. Keputusan tidak dapat dilakukan ulang. Riwayat nama pemeriksa dan catatan pada data lama tetap dapat dibaca.
- Penyimpanan approval order demo sebelumnya (`stockly-order-approvals-v1`) tidak digunakan untuk memutuskan PO. Tidak ada perubahan otomatis pada status PO berdasarkan approval order lama.
- Ini masih prototipe lokal tanpa login approver, pembatasan role pada server, atau sinkronisasi antar perangkat. Identitas pemeriksa dari akun dan role akan dihubungkan ketika autentikasi tersedia.

Pengujian approval:

```powershell
node --experimental-strip-types --test features/orders/tests/approval.test.mjs
```

Purchase Order CRUD: tombol **Buat Purchase Order** menambah draft. Kolom **Aksi** pada tabel menyediakan **Lihat**, **Edit**, dan **Hapus**. Edit hanya untuk Draft; hapus hanya untuk Draft, Rejected, atau Cancelled dan memerlukan konfirmasi di modal. Batasan status diperiksa kembali saat mutasi data. Total dan jumlah item dihitung ulang setelah edit. Nomor PO dan status disimpan tetap saat edit; identitas PT lama dipertahankan jika PT penerbit tidak diganti. Data PO lama yang belum punya rincian harus dilengkapi sebelum menyimpan edit. Perubahan baru tampil setelah localStorage berhasil menyimpan.

```powershell
node --experimental-strip-types --test features/purchase-orders/tests/mutations.test.mjs features/purchase-orders/tests/totals.test.mjs features/orders/tests/approval.test.mjs
```

Pagination tersedia pada seluruh tabel daftar modul, PO terbaru pada dashboard, dan rincian barang pada pratinjau PO/approval. Pilihan 5, 10, 25, atau 50 baris per halaman; default 10 dan dashboard 5. Pencarian/filter mengatur ulang halaman pertama. Penghapusan yang mengurangi jumlah halaman otomatis membatasi halaman aktif. Ekspor CSV tetap memuat seluruh hasil filter. Cetak PDF memuat seluruh rincian barang dengan pagination dokumen browser, tanpa kontrol pagination tabel.

```powershell
node --experimental-strip-types --test lib/tests/pagination.test.mjs
```
