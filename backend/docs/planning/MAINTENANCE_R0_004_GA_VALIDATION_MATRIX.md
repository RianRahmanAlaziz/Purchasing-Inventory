# MGA-R0-004 — Matriks Validasi Kebutuhan untuk General Affairs (DRAFT)

**Tanggal persiapan:** 24 November 2026 | **Task:** MGA-R0-004 | **Status:** IN PROGRESS — belum divalidasi GA.
**Dasar:** RPD_Maintenance_GA_v1.0.md (draft 23 Nov 2026) dan MASTER TODO v1.2.
**Cakupan:** 23 kebutuhan sumber; 11 VEH, 6 AST, 3 FAC, 3 ATK. Semua istilah FR adalah *usulan teknis*.
**Makna status:** PREPARED = baris siap dibahas, bukan disetujui. Tidak ada 23 baris yang diberi status CONFIRMED sebelum rapat GA.

## 1. Instruksi validasi

Pada setiap baris GA perlu memilih **CONFIRMED / CLARIFY / OUT OF SCOPE / DEFERRED**, melampirkan bukti (SOP/format sheet/sample tersamarkan), pemilik keputusan, tanggal, dan tanda setuju. Jangan mengubah frekuensi sumber diam-diam. Bila usulan berubah, catat Change Request dan ID yang terkena.

Kolom pertanyaan dan bukti berikut **bukan keputusan yang sudah dibuat**.

## 2. Matriks lengkap 23 kebutuhan

### Kendaraan operasional & alat berat (11)

| ID | Kebutuhan sumber | Frekuensi sumber | Sistem sumber | FR usulan | Pertanyaan untuk GA | Bukti/contoh yang diminta | Decision blocker |
|---|---|---|---|---|---|---|---|
| VEH-01 | Cek KM kendaraan Indorack/SMT/JRP | Daily (kelompok No. 1) | Google Sheet | FR-02, FR-03 | Unit mana wajib dicatat, oleh siapa, kapan cutoff hari dan bagaimana sumber KM? | 3 contoh baris KM per PT + aturan koreksi | D-01, D-03, D-10, D-11 |
| VEH-02 | Pastikan operational control terbarui, ingatkan admin | Daily | Google Sheet | FR-03, FR-16 | Apa definisi belum input, siapa admin penerima, dan kapan dianggap terlambat? | Contoh hari kerja/libur dan keterlambatan | D-10, D-11 |
| VEH-03 | Jadwalkan servis rutin kendaraan | Tidak tertulis | Whatsup (teks sumber) | FR-04, FR-07 | Siapa menjadwalkan/menyetujui dan apakah kalender atau KM menjadi sumber? | 1 contoh jadwal + PIC dan kanal yang sebenarnya | D-02, D-07, D-10 |
| VEH-04 | Servis berdasarkan batas rujukan maks. 10.000 KM | Berdasarkan KM; bukan jadwal waktu | Google Sheet | FR-04 | Apakah 10.000 KM interval sejak servis terakhir, nilai absolut, atau kebijakan lain? | Odometer servis terakhir dan contoh jatuh tempo | D-02 |
| VEH-05 | Gunakan tampilan KM RIS untuk menyusun servis | Tidak tertulis | Google Sheet / dashboard KM RIS | FR-02, FR-04 | Data KM RIS dibaca manual, diekspor atau memiliki API resmi? | Contoh format kolom / ekspor nonrahasia | D-07, D-11 |
| VEH-06 | Servis alat berat dari data maintenance | 2 Bulan | Google Sheet | FR-02, FR-04, FR-07 | Berdasarkan 2 bulan, hourmeter, atau gabungan; semua alat berat sama? | Contoh 2 alat, jam operasi & riwayat servis | D-01, D-03 |
| VEH-07 | Pastikan KIR aktif | 6 Bulan | Google Sheet | FR-05 | Enam bulan jadwal cek atau durasi berlaku; tanggal real dan siapa akses file? | 1 contoh masa berlaku + jadwal pengecekan | D-04, D-10, D-11 |
| VEH-08 | Pastikan STNK aktif | 1 Tahun | Google Sheet | FR-05 | Satu tahun jadwal cek atau masa berlaku; dokumen apa yang disimpan? | 1 contoh STNK tersamarkan dan expiry aktual | D-04, D-10, D-11 |
| VEH-09 | Pastikan asuransi kendaraan | 1 Tahun | Google Sheet | FR-05 | Satu tahun jadwal cek atau renewal; pemilik polis dan akses file? | 1 contoh polis tersamarkan + jatuh tempo | D-04, D-10, D-11 |
| VEH-10 | Rekap request maintenance kendaraan Smart Salary | Daily | Smart Salary & Google Sheet | FR-06 | Siapa menginput, nomor referensi unik, dan request dapat ditutup tanpa WO? | Contoh data request dan identitas sistem sumber | D-07, D-10, D-13 |
| VEH-11 | Peminjaman kendaraan operasional | Tidak tertulis | Google Sheet | FR-08 | Siapa pemohon, approver, pemberi kunci, penerima; booking bentrok dan pengembalian? | SOP/pola formulir pinjam-kembali | D-05, D-10, D-11 |

### Asset Management (6)

| ID | Kebutuhan sumber | Frekuensi sumber | Sistem sumber | FR usulan | Pertanyaan untuk GA | Bukti/contoh yang diminta | Decision blocker |
|---|---|---|---|---|---|---|---|
| AST-01 | Update database aset sesudah pembelian Purchasing sesuai PT | 1 Hari | Google Sheet | FR-01 | Kriteria aset vs SKU, siapa pemilik PT, apakah terhubung PO/GRN? | 2 contoh bukti beli dan register per PT | D-01, D-11 |
| AST-02 | Penomoran aset sesudah pembelian/pemeriksaan | 3 Bulan | Google Sheet | FR-01 | Format kode, unik global/per PT, dan waktu nomor dibuat? | 3 contoh kode aset dan format stiker | D-01, D-11 |
| AST-03 | Log request perbaikan aset Smart Salary | 1 Minggu | Google Sheet / Smart Salary | FR-06 | Requester, identifikasi aset, referensi unik, dan status yang dibutuhkan? | 2 contoh tiket tersamarkan | D-07, D-10, D-13 |
| AST-04 | Administrasi mutasi aset menurut SOP GA | 1 Bulan | Google Sheet | FR-09 | Persetujuan dan perpindahan antar PT/lokasi; owner berubah atau tidak? | SOP dan berita acara mutasi | D-06, D-10, D-11 |
| AST-05 | Penjualan/disposal aset sesuai SOP GA | 1 Bulan | Google Sheet | FR-09 | Kondisi eligible, siapa menyetujui dan dokumen bukti? | SOP penjualan/penghapusan + contoh berkas | D-06, D-10, D-11 |
| AST-06 | Pemeliharaan aset/fasilitas berkala | 3 Bulan | Google Sheet | FR-07 | Jenis aset, interval, teknisi, bukti pengerjaan dan alur WO? | Contoh checklist pemeliharaan | D-01, D-10, D-13 |

### Building & Facility (3)

| ID | Kebutuhan sumber | Frekuensi sumber | Sistem sumber | FR usulan | Pertanyaan untuk GA | Bukti/contoh yang diminta | Decision blocker |
|---|---|---|---|---|---|---|---|
| FAC-01 | Monitoring kebersihan dan jobdesk office boy | 1 Minggu | Google Sheet | FR-10 | Daftar area, pengawas, bukti cek, dan tindak lanjut? | Checklist mingguan / area gedung | D-01, D-10, D-11 |
| FAC-02 | Pemeriksaan/maintenance kondisi gedung | 3 Bulan | Google Sheet | FR-07, FR-11 | Area/gedung, tipe temuan, PIC, dan kapan dibuat WO? | Contoh inspeksi + temuan | D-01, D-10, D-11 |
| FAC-03 | Periksa jatuh tempo sewa gedung | 1 Bulan | Google Sheet | FR-12 | PT penyewa, kontrak, termin jatuh tempo, akses dokumen? | Contoh jadwal sewa yang disamarkan | D-10, D-11 |

### Maintenance ATK (3)

| ID | Kebutuhan sumber | Frekuensi sumber | Sistem sumber | FR usulan | Pertanyaan untuk GA | Bukti/contoh yang diminta | Decision blocker |
|---|---|---|---|---|---|---|---|
| ATK-01 | Catat barang masuk/keluar | Daily | Google Sheet | FR-13 | SKU dan transaksi masuk/keluar mana yang harus terlaporkan? | Contoh SKU ATK serta GRN/Distribution terkait | D-01, D-10 |
| ATK-02 | Stock opname ATK | Daily vs 1 Bulan (konflik sumber) | Google Sheet | FR-14 | Pemeriksaan harian atau opname bulanan; siapa boleh adjustment? | SOP opname + contoh selisih dan alasan | D-08, D-10 |
| ATK-03 | Pengadaan ATK saat stok menipis | Daily vs 1 Bulan (konflik sumber) | Google Sheet | FR-15 | Monitor stok harian atau ajukan PO bulanan; approval PO existing tetap? | Contoh permintaan ATK dan threshold SKU | D-09, D-10 |



## 3. Kebutuhan lintas kategori (bukan 24-25)

- **FR-16 Dashboard GA**: tentukan KPI dan batas data/akses dari kumpulan 23 baris; ini **usulan lintas kategori**, bukan baris tambahan dari foto.
- **FR-17 Audit & ekspor**: tentukan siapa melihat/mengunduh data, filter per PT/periode, retensi audit dan data sensitif.
- Istilah **Whatsup** disalin apa adanya dari RPD; belum dipastikan bahwa yang dimaksud adalah WhatsApp.
- Lampiran gambar asli disebut di RPD tetapi **belum tersedia sebagai berkas gambar pada repo**; pastikan gambar `rpd_maintenance_ga_assets/sumber-jobdesk-maintenance-ga.jpeg` disertakan saat verifikasi terhadap foto asli.

## 4. Lembar hasil rapat / sign-off (BELUM DIISI)

| Informasi | Isian dari GA |
|---|---|
| Tanggal rapat dan peserta | PENDING |
| Dokumen SOP / screenshot / sample terlampir | PENDING |
| Hasil 23 baris: confirmed / clarify / deferred / out of scope | PENDING |
| Konflik frekuensi ATK-02/ATK-03 dan solusi D-08/D-09 | PENDING |
| Ruang lingkup R1/R2/R3/R4/R5 | PENDING |
| PIC/penyetuju GA (nama-jabatan) | PENDING |
| Tanggal/tanda persetujuan | PENDING |
| CR yang dibuat dan ID terdampak | Belum ada |

## 5. Kriteria menyelesaikan MGA-R0-004

- Semua 23 ID punya keputusan review per baris, bukti yang dapat dirujuk, serta PIC; tidak ada kegiatan sumber yang hilang.
- Frekuensi ambigu, SOP yang belum tersedia, dan layanan eksternal ditandai eksplisit; bukan disimpulkan otomatis.
- Hasil rapat/validasi GA terdokumentasi, disetujui, dan diperbarui di Master TODO serta Decision Log.
- Setelah syarat terpenuhi saja, ubah `MGA-R0-004` menjadi DONE.
