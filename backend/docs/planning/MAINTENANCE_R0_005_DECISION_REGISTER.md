# MGA-R0-005 — Register Keputusan GA (D-01 s.d. D-13)

**Tanggal:** 24 November 2026 | **Status:** DRAFT UNTUK REVIEW | **Owner keputusan:** GA / product owner (belum ditetapkan).
**Dokumen sumber:** RPD Maintenance GA v1.0; Master TODO v1.3. **TIDAK ADA keputusan D-01..D-13 yang disahkan.**

## 1. Pemisahan keputusan

- **Fakta dari RPD:** kegiatan dan frekuensi pada foto, PIC tertulis GA; RPD berstatus draft.
- **Preferensi awal pengguna:** pengguna kini telah memilih sejumlah opsi rancangan sementara untuk D-01/D-10/D-11 dan registrasi aset; **belum disahkan GA**. Rincian lengkap ada di `MAINTENANCE_R0_R1_REKAP_DISKUSI_KEPUTUSAN_SEMENTARA_2026-11-24.md`.
- **Usulan teknis:** opsi arsitektur/workflow untuk dibahas; dapat berubah.
- **Keputusan resmi:** hanya dicatat bila ada pilihan final, tanggal, nama/jabatan penyetuju, bukti rapat/SOP dan task terdampak. Saat ini **tidak ada**.

## 2. Register semua keputusan

| ID | Isu | Alternatif/usulan pembahasan — bukan final | Dampak bila pending | Bukti minimal dari GA | Status formal |
|---|---|---|---|---|---|
| D-01 | Kelompok aset GA | Alternatif: kendaraan/alat berat, kantor, bangunan dalam Maintenance; ATK sebagai Item Inventory atau dipisahkan lebih lanjut. | Master aset & batas domain R1 | Daftar kategori + contoh aset/SKU dengan PT pemilik | PENDING — belum disetujui |
| D-02 | Makna Max 10.000 KM | Jangan menghitung due sebelum diputuskan interval setelah servis terakhir vs ambang lain. | Service due R2 | 2 kasus KM sebelum/sesudah servis | PENDING — belum disetujui |
| D-03 | Alat berat setiap 2 bulan/jam operasi | Simpan opsi meter KM/HOUR untuk rancangan; interval final menunggu GA. | Profil meter R1 dan preventive R2 | Jenis unit dan 2 contoh kalender/hourmeter | PENDING — belum disetujui |
| D-04 | KIR 6 bulan, STNK/asuransi 1 tahun | Usulan pisahkan frekuensi pemeriksaan dari tanggal expiry aktual. | R2 dokumen/reminder | Contoh dokumen tersamarkan dengan expiry riil | PENDING — belum disetujui |
| D-05 | Peminjaman kendaraan | Proses request→approval→hand-over→return hanya skenario rapat, belum status aplikasi. | R4 loan, peminjam, collision | SOP booking, overlap dan serah terima | PENDING — belum disetujui |
| D-06 | Mutasi/penjualan/disposal aset | Jangan membuat state dan approval disposal sebelum SOP. | R4 movement/disposal | SOP, pihak approve, berita acara | PENDING — belum disetujui |
| D-07 | Whatsup, Smart Salary, KM RIS | Jangan asumsikan API atau nama platform; prioritas input manual/import yang disetujui. | R5 integration dan R2 requests | Owner sistem, spec API resmi bila ada | PENDING — belum disetujui |
| D-08 | Frekuensi opname ATK konflik Daily/1 bulan | Jangan buat scheduler; audit kebutuhan lapangan dahulu. | R3 stock opname | SOP opname dan periode contoh | PENDING — belum disetujui |
| D-09 | Pengadaan ATK konflik Daily/1 bulan | Usulan low-stock suggestion via Purchasing, tanpa auto-PO. | R3 replenishment | SOP pembelian, threshold SKU | PENDING — belum disetujui |
| D-10 | Aktor dan matriks aksi | Usulan permission granular, role baru tidak otomatis dibuat, role existing tidak berubah. | RBAC R1 / workflows R2 | Organigram, approval limits, matrix aktor | PENDING — belum disetujui |
| D-11 | PT pemilik, akses & mutasi lintas PT | Usulan asset belongsTo Company + kebijakan visibility eksplisit; default_company_id bukan bukti hak. | R1 asset schema/authorization | Contoh PT/asset, actor→allowed PT, SOP transfer | PENDING — belum disetujui |
| D-12 | Titik stok keluar spare part & retur | Usulan saat fisik keluar gudang, melalui StockService; retur per transaksi audit. | R3 stock part issue | SOP issue/return dan PIC warehouse | PENDING — belum disetujui |
| D-13 | Request Smart Salary → WO | Usulan request bisa dideduplikasi; aturan tutup tanpa WO harus disahkan. | R2 state machine + R5 import | Contoh kasus request rejected/completed | PENDING — belum disetujui |

## 3. Fokus rapat pertama: MGA-R0-005

### D-01 — Kelompok aset
1. Apakah kendaraan, alat berat, peralatan kantor dan bangunan merupakan satu master aset? Tentukan kategori yang masuk / di luar R1.
2. Apakah ATK hanya SKU konsumsi Inventory atau ada objek aset tahan lama? Pisahkan contoh nyata.
3. Apakah satu aset bisa dimiliki beberapa PT? Bedakan kepemilikan dan lokasi penggunaan.

### D-10 — Role, pemilik proses, hak akses
1. Siapa **pemohon**, petugas GA input, supervisor, teknisi, approver, warehouse dan auditor?
2. Apakah organisasi menginginkan role Spatie baru atau permission tambahan pada role existing? Perlu daftar jabatan dan batas tindakan.
3. Siapa boleh melakukan koreksi KM, akses dokumen kendaraan, approval, dan membaca data per PT?

### D-11 — Perusahaan dan isolasi data
1. Apakah setiap aset wajib terhubung ke tepat satu `companies.id`? Bagaimana aset bersama?
2. Siapa yang bisa melihat, memperbarui, dan mengunduh data aset PT lain?
3. Apakah `default_company_id` hanya PT yang dipilih saat bekerja, atau ada daftar PT yang diizinkan? Jangan samakan tanpa kontrol eksplisit.
4. Apakah pemindahan lintas PT mengubah owner, hanya lokasi pemakaian, atau membuat entri mutasi terpisah?

## 4. Form keputusan resmi (isi setelah diskusi)

| Field | Nilai |
|---|---|
| Decision ID | D-__ |
| Alternatif yang dipilih / redaksi keputusan final | PENDING |
| Ruang lingkup PT / aktor / asset type | PENDING |
| Contoh kasus valid dan invalid | PENDING |
| Disetujui oleh (nama + jabatan) | PENDING |
| Tanggal dan bukti SOP/berita acara | PENDING |
| Task/FR terkait, dampak DB/API/RBAC | PENDING |
| CR ID (bila mengubah RPD/requirement lama) | Belum ada |
| Status | PENDING (bukan APPROVED) |

## 5. Gate dan dampak

- **MGA-R0-005 BLOCKED:** tidak boleh menjadi DONE sampai D-01/D-10/D-11 disahkan.
- **MGA-R1-003** (master aset migration), **MGA-R1-002** (permission matrix final), dan **MGA-R0-010** (ERD final) belum boleh diperlakukan siap implementasi.
- D-02/D-03/D-04/D-08/D-09/D-12/D-13 dan SOP R4/R5 tetap PENDING, bukan otomatis disetujui oleh keputusan R0-005.
- Tidak ada izin, role, tabel, endpoint, scheduler, atau mutasi stok baru yang dijalankan oleh dokumen ini.


## 6. Addendum preferensi desain pengguna — 24 November 2026 (BUKAN keputusan formal)

| ID / topik | Preferensi pengguna | Status persetujuan GA |
|---|---|---|
| D-01 | Kendaraan, alat berat, kantor, gedung/fasilitas; ATK tetap Inventory; bedakan gedung milik dengan sewa | **PENDING** |
| D-10 | Semua role existing dapat memakai fitur Maintenance sesuai permission aksi; **khusus klasifikasi barang** hanya `super_admin` atau `hr` **dan** permission `maintenance.asset-items.classify` | **PENDING** |
| D-11 | PT pembeli menjadi pemilik aset; pemakaian di PT lain tidak mengalihkan kepemilikan; akses lintas PT melalui permission | **PENDING** |
| Registrasi dari GRN | Unit baik GRN confirmed eligible tanpa Order completed; petugas berizin melakukan registrasi manual; boleh batch 2 lalu 4; kode otomatis unik per unit, sequence per PT | **PENDING** |
| Item trackability | Klasifikasi eligibility berasal dari Master Barang; kandidat kolom `items.is_asset_trackable` default false, perubahan terpisah dan diaudit | **PENDING** |
| Aset historis | Input manual oleh HR berizin, dengan verifikasi PT pemilik dan bukti | **PENDING** |

**Belum diputuskan:** role yang boleh melakukan registrasi aset dari GRN, prefix kode perusahaan dan reset counter, penanganan klasifikasi yang berubah sesudah registrasi. Semuanya tidak boleh diasumsikan sebagai keputusan GA.

**Rujukan:** `MAINTENANCE_R0_R1_REKAP_DISKUSI_KEPUTUSAN_SEMENTARA_2026-11-24.md`, `MAINTENANCE_R0_010_ERD_R1_REVISI_DRAFT_v0.8.md`, `MAINTENANCE_R1_002_RBAC_MATRIX_DRAFT_v0.1.md`.
