# MGA-R0-009 — Proposal Scope MVP dan Matriks Akses Awal (BUKAN FINAL)

**Tanggal:** 24 November 2026 | **Status:** IN PROGRESS — hanya proposal teknis.
**Dependencies:** hasil review `MGA-R0-004`; keputusan D-01/D-10/D-11; SOP lain sesuai RPD.
**Batas implementasi:** **tidak ada permission, role, routes atau migration baru yang dipasang**.

## 1. Usulan pembagian MVP

| Irisan | FR / sumber | Candidate deliverable (setelah sign-off) | Blocker | Status |
|---|---|---|---|---|
| Fondasi R1 | FR-01; AST-01/02 | Master aset, kode register, relasi PT dan audit | D-01, D-10, D-11 | BLOCKED untuk implementasi |
| Kendaraan R1 | FR-02/03; VEH-01/02/05 | Profil kendaraan/alat berat, catatan KM/jam, koreksi beralasan | D-01, D-03, D-10, D-11 | BLOCKED untuk migration |
| Preventive R2 | FR-04/05; VEH-03/04/06/07/08/09 | Jadwal servis + dokumen private dan jatuh tempo | D-02, D-03, D-04, D-10 | BLOCKED |
| Requests dan WO R2 | FR-06/07; VEH-10, AST-03/06 | Request manual, WO, audit; integrasi external terpisah | D-10, D-13 | BLOCKED |
| ATK/Inventory R3 | FR-13/14/15; ATK-01/02/03 | Report ATK dari Inventory, stock opname via StockService, usulan PO | D-08, D-09, D-10, D-12 bila spare part | BLOCKED untuk aksi stok |
| Audit & laporan | FR-17; lintas domain | Audit append-only, laporan terotorisasi per PT/aktor | D-10, D-11 | Persiapan skenario boleh |
| R4/R5 lanjutan | FR-08..12, FR-16; VEH-11, AST-04/05, FAC-01..03 | Loan/transfer/disposal, fasilitas, sewa, dashboard dan import | D-05..07, D-10, D-11 + SOP | Deferred sampai keputusan |

**Catatan:** Prioritas P1/P2 di RPD merupakan rekomendasi teknis; tabel ini bukan jadwal yang disahkan GA.

## 2. Matriks aksi → kandidat permission (SEMUA USULAN)

| Domain/aksi (belum ada route) | Kandidat permission | Aktor usulan untuk divalidasi GA | Batas data / guard teknis |
|---|---|---|---|
| Asset list/detail | `maintenance.assets.view` | Petugas GA / supervisor / auditor | Hanya aset PT diizinkan, bukan otomatis `default_company_id` |
| Asset create/update | `maintenance.assets.manage` | GA Operator / Supervisor | Company owner & status locked bila perlu |
| Asset numbering | `maintenance.assets.number` | GA | Kode unik dan audit; format setelah D-01/D-11 |
| Vehicle list/detail | `maintenance.vehicles.view` | GA / teknisi berizin | PT asset owner |
| Input KM/jam | `maintenance.meters.record` | GA Operator | Tanggal, unit, kronologi, anti duplikasi |
| Koreksi KM/jam | `maintenance.meters.correct` | GA Supervisor | Reason wajib + audit before/after |
| Plan & service | `maintenance.plans.manage` | GA Supervisor | Formula due setelah D-02/D-03 |
| Dokumen upload | `maintenance.documents.manage` | GA Operator | Upload private, tipe/ukuran dan scope PT |
| Dokumen download | `maintenance.documents.view` | GA / auditor berizin | Route terproteksi, bukan URL storage publik |
| Repair request create | `maintenance.requests.create` | Pemohon sesuai D-10 | Hanya asset PT berhak dan record miliknya |
| Repair request view | `maintenance.requests.view` | Pemohon/GA/supervisor | Ownership dan PT scope |
| Approval/transition request | `maintenance.requests.approve` | Approver hasil D-10 | Maker-checker, state disahkan D-13 |
| WO assign/transition | `maintenance.work-orders.manage` | GA Supervisor / teknisi sesuai aksi | State, actor, status dan rollback |
| ATK read-only report | `maintenance.atk.view` | GA/Auditor yang disetujui | Membaca source Inventory; jangan punya saldo baru |
| ATK adjustment/stock issue | **Tidak ditetapkan terpisah dulu** | Warehouse/GA perlu SOP | StockService existing, D-08/D-12, lock dan idempotensi |
| Admin permission | Spatie `roles.manage` existing | Super Admin sesuai kebijakan existing | Tidak membuat role GA otomatis |

**Catatan otorisasi:** Pemetaan permission ke peran dan pemilik record masih usulan. Semua endpoint baru nantinya memakai Sanctum, `EnsureActiveAccount`, permission backend dan query PT-scope; 401/403 wajib diuji. Jangan menyimpulkan bahwa menu visible sama dengan izin API.

## 3. Draft acceptance criteria yang aman diuji nanti

| Kategori | Candidate test (belum dijalankan) |
|---|---|
| Guest API | Request tanpa token menghasilkan HTTP 401 |
| Forbidden role | User valid tanpa permission yang tepat menghasilkan HTTP 403 |
| Cross-PT | Data PT yang tidak diizinkan tidak terlihat atau dapat diubah, termasuk via ID langsung; kebijakan 403/404 final disepakati |
| Duplicate asset | Kode aset duplikat ditolak sesuai global/per-PT uniqueness final |
| Meter reading | Reading turun/kembar tanpa koreksi beralasan ditolak; koreksi punya audit |
| Documents | File di private disk, MIME/size dicek, download lintas PT ditolak |
| Stock separation | Tidak ada penulisan `items.stock_quantity` dari Maintenance; bila nanti part-issue, hanya melalui StockService |
| Regressions | PO approval/Order, GRN pending-confirmed, Distribution Sent, Finance tetap lulus |
| Migration | Up/down migration additive diuji pada MySQL test isolated, bukan production |

## 4. Yang boleh dikerjakan tanpa sign-off

1. Verifikasi 23 baris pada `MAINTENANCE_R0_004_GA_VALIDATION_MATRIX.md` dan siapkan agenda rapat.
2. Tulis opsi desain ERD, matriks ownership dan test scenario, **tanpa mengunci nama field/FK sebelum D-01/D-11**.
3. Kumpulkan SOP D-05/D-06 dan sumber D-07, tanpa membuat integrasi eksternal.
4. Jaga baseline 346 passed (2.722 assertions) sebagai referensi; angka test baru diumumkan hanya setelah CI menjalankannya.

## 5. Gate pengesahan

- **GA** meninjau cakupan FR, frequency dan matrix aktor; **technical lead** menyetujui risiko teknis.
- D-01/D-10/D-11 memiliki keputusan final pada register; D-02/D-03/D-04/D-08/D-09/D-12/D-13 disahkan sebelum feature masing-masing.
- Setelah itu buat ERD R1, migration additive, API v1, Form Requests, tests dan PR terpisah.
