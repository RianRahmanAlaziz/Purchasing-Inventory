# Rekap Diskusi dan Keputusan Rancangan Sementara — Maintenance & General Affairs

**Tanggal:** 24 November 2026  
**Lingkup:** MGA-R0-004, MGA-R0-005, MGA-R0-009, MGA-R0-010, MGA-R1-001/002/003/005  
**Status:** **PREFERENSI AWAL PENGGUNA / DRAFT TEKNIS, BUKAN SIGN-OFF GA**  
**Acuan kebutuhan:** `RPD_Maintenance_GA_v1.0.md` (draft) dan `MAINTENANCE_BACKEND_MASTER_TODO.md`.  
**Acuan implementasi existing yang telah diaudit:** commit `9583abd61931587374490afc0834e0a977f87745`; tidak ada perubahan pada backend runtime.

## A. Hasil diskusi yang sudah dipilih pengguna (belum disahkan GA)

| Topik / ID | Preferensi yang telah dipilih | Batas yang harus dijaga |
|---|---|---|
| D-01 — Cakupan Maintenance | Kendaraan operasional (mobil, motor, truk), alat berat, aset kantor (laptop, AC, printer, meja, dll.), gedung/fasilitas; ATK **tetap menggunakan Inventory existing**. | Kategori, SOP dan scope final menunggu GA; gedung milik dan gedung sewa dipisahkan. |
| D-11 — Kepemilikan per PT | Aset yang dibeli PT Indorack menjadi milik PT Indorack. | PT pengguna/peminjam berbeda tidak otomatis mengubah PT pemilik. |
| D-11 — Akses lintas PT | Akses lintas PT **diatur oleh permission**, bukan otomatis bebas akses. | `users.default_company_id` bukan bukti seluruh hak akses lintas PT. |
| D-10 — Role existing | Pertahankan `super_admin`, `hr`, `staff_hr`, `general_manager`; setiap aksi Maintenance dibatasi oleh permission. | Tidak membuat role GA baru tanpa persetujuan. |
| Registrasi aset historis | Aset lama yang tidak mempunyai PO/GRN ERP dimasukkan manual oleh HR (pengguna menyebut “aset HR”). | Perlu bukti asal/identitas PT, audit, serta kewenangan final. Jangan gunakan jalur legacy untuk mengakali GRN/PO dengan PT kosong. |
| Waktu registrasi dari pembelian | **Opsi B:** boleh mendaftarkan unit layak dari GRN **confirmed**, meski Order baru `partially_received`; tidak wajib menunggu `completed`. | GRN Pending atau unit rusak tidak layak didaftarkan. |
| Cara memulai registrasi | **Opsi A:** petugas berizin memicu registrasi **manual** dari daftar calon aset; tidak ada auto-create saat GRN confirmed. | Registrasi tidak menulis saldo stok atau mengubah status PO/GRN/Order. |
| Kuota registrasi | Satu baris GRN confirmed dapat diregistrasi dalam beberapa batch (contoh: 2 unit dulu, 4 menyusul dari 6 unit layak). | Total aset terkait baris GRN tidak boleh melebihi `qty_received - qty_damaged` atau digandakan oleh retry/paralel. |
| Kode aset | **Satu unit fisik = satu kode aset berbeda**; sistem membuat kode otomatis dengan nomor urut per PT. | Pola prefix PT, apakah reset tahunan, dan format lengkap masih perlu persetujuan. |
| Klasifikasi Master Barang | **Opsi C:** penentuan apakah SKU bisa menjadi calon aset berasal dari Master Barang. Usulan field baru `items.is_asset_trackable`, default false. | Klasifikasi bukan aksi mutasi stok; tidak otomatis membuat aset. |
| Pembatasan klasifikasi | **Hanya role `super_admin` atau `hr` DAN permission `maintenance.asset-items.classify`**. | `staff_hr` atau `general_manager` tetap ditolak walaupun memiliki permission klasifikasi; `items.create`/`items.manage` bukan izin klasifikasi. |
| Pemakaian lintas PT | Aset milik Indorack yang dipakai SMT **tetap milik Indorack**, kecuali prosedur mutasi kepemilikan resmi mengubahnya. | Bedakan pemilik, lokasi pemakaian dan riwayat perpindahan. |
| Gedung dan fasilitas | Gedung milik perusahaan dan gedung sewa harus dibedakan. | Kontrak sewa tidak otomatis menjadi aset milik perusahaan. |

## B. Aturan anti-duplikasi yang direncanakan

1. Hitung kuota per **`goods_receipt_items.id`** yang telah confirmed, `usable = qty_received - qty_damaged` sesuai proses Receiving existing.
2. Daftar calon aset hanya *read-only*, berdasarkan Item yang diizinkan sebagai aset; Item/SKU tetap dikelola Inventory.
3. Registrasi manual mengunci sumber dan kuota, memastikan total unit historis yang pernah didaftarkan **tidak melebihi usable**, menyimpan satu row aset per unit fisik dan kode unik.
4. Retry dengan idempotency key sama serta payload identik mengembalikan batch sebelumnya; key sama untuk payload berbeda ditolak; request paralel tidak melampaui kuota.
5. Kode aset otomatis harus mengunci sequence per PT dan memiliki constraint database. Jangan mengandalkan `MAX(asset_code)+1` tanpa locking.
6. Data lama/historis memiliki alur registrasi terpisah dengan verifikasi PT pemilik. Aset yang sudah diterbitkan tidak otomatis “membebaskan kuota” ketika nonaktif/soft-deleted.
7. Tidak ada penambahan, pengurangan maupun penyesuaian saldo pada proses registrasi aset. StockService, GRN, Order dan Distribution berjalan seperti sebelumnya.

## C. Keputusan yang BELUM dijawab atau perlu disahkan

| Kode/topik | Keputusan berikutnya yang diperlukan | Kondisi |
|---|---|---|
| D-01/D-10/D-11 | Sign-off GA dan pemilik proses atas kelompok aset, actor/action matrix, dan kebijakan PT. | **PENDING** |
| **Registrasi GRN — aktor** | Dari pilihan terpisah: A) `super_admin`+ `hr`, B) plus `staff_hr`, C) setiap role dengan permission khusus. Pengguna **belum memilih** opsi ini. | **PENDING** |
| Nomor aset per PT | Prefix PT sumbernya dari mana? `companies` existing belum punya kode perusahaan; reset per tahun atau global per PT? | **PENDING** |
| Aset lama | Siapa yang boleh input, bagaimana persetujuan dan bukti pemilikan PT? | **PENDING** |
| Perubahan klasifikasi | Apakah SKU yang sudah pernah diregistrasi bisa dinonaktifkan klasifikasinya, dan bagaimana mencegah audit data historis berubah? | **PENDING** |
| D-02/D-03/D-04 | Rumus due KM 10.000, meter alat berat, dan perbedaan pengecekan dokumen vs expiry sebenarnya. | **PENDING** |
| D-05/D-06/D-07 | SOP pinjam, mutasi/disposal, kejelasan aplikasi “Whatsup”/Smart Salary/KM RIS. | **PENDING** |
| D-08/D-09/D-12/D-13 | Frekuensi ATK, kapan spare part mengurangi saldo/retur, dan aturan request→WO. | **PENDING** |

## D. Dokumen desain terkait

- `MAINTENANCE_R0_010_ERD_R1_REVISI_DRAFT_v0.8.md`: ERD dan addendum klasifikasi/ownership/registrasi bertahap.
- `MAINTENANCE_R1_ASSET_REGISTRATION_API_CONTRACT_DRAFT_v0.4.md`: kontrak API JSON R1, validasi, permission, idempotensi, dan test.
- `MAINTENANCE_R1_001_DOMAIN_STRUCTURE_PROPOSAL_v0.1.md`: batas domain Laravel dan usulan struktur source.
- `MAINTENANCE_R1_002_RBAC_MATRIX_DRAFT_v0.1.md`: matriks permission dan pengujian role+permission.
- `MAINTENANCE_R0_005_DECISION_REGISTER.md`: sumber status persetujuan resmi (tetap PENDING).
- `MAINTENANCE_R0_004_GA_VALIDATION_MATRIX.md`: daftar 23 kebutuhan untuk validasi dengan GA.

## E. Status implementasi dan pengujian

- **Rancangan/dokumentasi saja.** Tidak membuat migration, Model, Service, Controller, Form Request, route, role atau permission Maintenance.
- Tidak ada pengujian khusus Maintenance dijalankan pada tahap ini.
- Baseline existing sebelum modul Maintenance: GitHub Actions 24 Nov 2026, **346 passed / 2.722 assertions**, commit `9583abd`; bukan hasil pengujian perubahan ini.
- **Jangan tandai R0-010, R1-001, R1-002 atau R1-005 DONE** sampai DoD dan persetujuan terkait dipenuhi.
