# MGA-R0-003 — Audit Baseline Backend ERP Maintenance & GA

**Tanggal audit:** 24 November 2026  
**Branch acuan:** `feat/maintenance-backend`  
**Commit kode yang diaudit:** `9583abd61931587374490afc0834e0a977f87745`  
**Status:** DONE (audit sumber dan baseline CI); **belum ada migration/endpoint/permission Maintenance yang dipasang**.  
**Dasar:** `MAINTENANCE_BACKEND_MASTER_TODO.md` v1.1 dan `RPD_Maintenance_GA_v1.0.md` (draft belum disahkan GA). Seluruh rancangan modul Maintenance tetap usulan teknis.

## 1. Bukti baseline dan batas audit

- GitHub Actions **Backend Laravel Tests**, run **45316651290**, pada commit baseline `9583abd`: **346 passed (2.722 assertions)**, PHP 8.4 + MySQL 8, 24 November 2026.
- Baseline 338 tests / 2.669 assertions adalah laporan lebih lama; kedua angka tersebut tidak setara dengan pengujian modul Maintenance.
- Audit ini berupa **review sumber dan regresi CI existing**; tidak menjalankan migration baru, tidak menyentuh database production, dan tidak menjalankan UAT GA.

## 2. Peta batas domain existing

| Bagian | Sumber terverifikasi | Fakta / batas desain |
|---|---|---|
| Identity & RBAC | `app/Models/User.php`, `app/Http/Middleware/EnsureActiveAccount.php`, `database/seeders/RolePermissionSeeder.php` | User memakai Sanctum dan Spatie HasRoles guard `web`; role existing: `super_admin`, `hr`, `staff_hr`, `general_manager`. PIC GA **bukan** role Spatie otomatis. |
| Permintaan API | `routes/api.php`, `routes/api/v1.php`, `bootstrap/app.php` | API versi `/api/v1`; endpoint privat memakai `auth:sanctum`, `EnsureActiveAccount`, serta permission/role sesuai route. Tetapkan middleware yang sama untuk setiap endpoint Maintenance. |
| Katalog modul | `config/erp.php`, `app/Domain/Authorization/Services/ApplicationCatalog.php` | Katalog dapat dikonfigurasi dan bergantung permission; Maintenance belum terdaftar di katalog. Menampilkan menu bukan pengganti HTTP 403. |
| Registrasi permission | `app/Domain/Authorization/Services/ModulePermissionRegistrar.php` | Dapat membuat permission `guard_name=web` secara idempoten, **tidak memberikan permission ke role**. |
| Company & PT | `app/Domain/Company/Models/Company.php`, `2026_11_05_140001_create_companies_table.php`, `CompanyController.php` | Company soft-delete; `users.default_company_id` nullable. Pemilihan default PT **belum setara** dengan policy akses data lintas PT. |
| Purchasing | `2026_11_05_140002_extend_purchase_order_documents.php` | PO sudah mempunyai `company_id` nullable dan snapshot PT. Jika aset mengacu PO/GRN, pastikan aturan link/ownership disahkan. |
| Inventory SKU | `app/Domain/Item/Models/Item.php`, `2026_11_03_020005_create_items_table.php` | `items.stock_quantity` DECIMAL(18,3), SKU unique, Item tidak memiliki `company_id` pada skema yang diaudit. Aset fisik tidak otomatis sama dengan Item/SKU. |
| Stok & audit | `app/Domain/Inventory/Services/StockService.php`, `StockDecimal.php`, `StockMovement.php`, `2026_11_03_020018_create_stock_movements_table.php` | Layanan mengunci Item dengan `lockForUpdate`, transaksi, riwayat movement; saldo negatif ditolak di `StockDecimal::add`. Movement existing untuk GRN, Distribution dan Adjustment; jangan menambah mutasi Maintenance langsung melalui model Item. |
| Tests | `.github/workflows/backend-tests.yml`, `tests/Feature/Api/V1/RoleAccessTest.php`, `ModulePermissionRegistrarTest.php`, `StockServiceIntegrationTest.php` | CI menggunakan database testing terpisah. Tests untuk Maintenance harus dibuat baru setelah kontrak bisnis disahkan. |

## 3. Risiko dan gate keputusan

| Prioritas | Risiko | Tindakan |
|---|---|---|
| P0 | **D-01** kelompok aset belum diputuskan (kendaraan/alat berat/kantor/bangunan/ATK). | Tahan ERD final dan migration `maintenance_assets` sampai ruang lingkup aset disahkan. |
| P0 | **D-10** matrix GA Operator/Supervisor/teknisi/pemohon belum disahkan. | Jangan membuat role baru atau memberikan permission GA otomatis; siapkan matriks CRUD/approval untuk sign-off. |
| P0 | **D-11** pemilik data per PT belum disahkan; `default_company_id` hanya pilihan PT dan `items` tidak memiliki company_id. | Tentukan ownership aset, pembatasan query, pemilik dokumen, admin lintas PT, dan SOP mutasi sebelum FK/policy final. |
| P0 | **D-12** titik pengeluaran spare part dan retur belum disahkan. | Larang stock issue Maintenance sampai integrasi StockService, locking, source FK, audit dan idempotensi lengkap. |
| P1 | D-02/D-03/D-04/D-08/D-09/D-13 belum diputuskan. | Jangan membuat due scheduler, interval tetap, state machine request/WO, atau frekuensi ATK sebagai ketentuan final. |
| P1 | RPD mereferensikan gambar `rpd_maintenance_ga_assets/sumber-jobdesk-maintenance-ga.jpeg`, tetapi gambar tidak ikut dalam dua file Markdown yang diterima. | Simpan gambar sumber pada jalur relatif tersebut sebelum menyatakan bukti gambar lengkap di repository. |
| P1 | RPD adalah draft, bukan sign-off GA. | Buat matriks 23 kebutuhan untuk review GA sebelum implementasi domain. |

## 4. Kontrak yang harus dilindungi

1. Tidak mengubah role existing, approval PO/Order, GRN, Payment, distribusi dan dokumen tanpa persetujuan.
2. GRN hanya menambah stok saat confirmed; stok masuk = diterima − rusak; distribusi mengurangi stok hanya ketika Sent.
3. `StockService` satu-satunya jalur saldo Item, `StockMovement` satu sumber audit mutasi, `StockDecimal` DECIMAL(18,3), dan pengurangan saldo tidak boleh negatif.
4. Semua migration Maintenance baru dan additive; tidak mengedit migration existing; uji up/down pada DB terisolasi.
5. Setiap API baru mengembalikan guest 401, forbidden 403, validation 422, status conflict 409 jika disepakati; ownership per PT tidak boleh ditebak.
6. Tidak membuat sync otomatis dengan Smart Salary/KM RIS/“Whatsup” tanpa kontrak API resmi.

## 5. Langkah berikutnya (bukan pekerjaan yang sudah selesai)

1. **MGA-R0-004** — review cakupan **23 kebutuhan** (`VEH-01..11`, `AST-01..06`, `FAC-01..03`, `ATK-01..03`) bersama pemilik proses GA.
2. **MGA-R0-005** — minta sign-off **D-01 / D-10 / D-11**; syarat utama untuk master aset, izin dan data PT.
3. **MGA-R0-009** — susun cakupan MVP dan matriks action → permission → PT scope setelah keputusan.
4. **MGA-R0-010** — rancangan ERD kecil khusus R1; migration baru baru boleh dibuat setelah dependency disetujui.
5. **MGA-R1-001** dan seterusnya — implementasi bertahap beserta feature tests, tanpa menyentuh production selama fase rancangan.

## 6. Catatan audit

**Artefak:** berkas ini + Master TODO v1.1.  
**Perubahan database / endpoint / stock / role:** tidak ada.  
**Bukti tests existing:** GitHub Actions run 45316651290, 346 passed / 2.722 assertions.  
**Keputusan bisnis:** D-01 sampai D-13 tetap **PENDING** (tidak ada keputusan GA yang dibuat oleh audit ini).  
**Bukti UAT/sign-off GA:** belum ada.
