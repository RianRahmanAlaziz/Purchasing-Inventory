# MASTER TODO — Backend ERP Maintenance & General Affairs

> **Dokumen patokan utama (living document)** untuk modul Maintenance & GA, Laravel 13 + MySQL.  
> **Versi:** 1.3 | **Dibuat:** 23 November 2026 | **Diperbarui:** 24 November 2026 | **Status:** AKTIF — fase R0  
> **Lokasi yang disarankan di repo:** `backend/docs/planning/MAINTENANCE_BACKEND_MASTER_TODO.md`  
> **Sumber kebutuhan:** `RPD_Maintenance_GA_v1.0.md` (draft 23 Nov 2026) dan foto tabel jobdesk GA (23 Nov 2026).  
> **Baseline awal yang dilaporkan pengguna:** 338 tests, 2.669 assertions. **Baseline terbaru terverifikasi GitHub Actions:** 346 tests, 2.722 assertions pada commit `9583abd` (24 November 2026). Belum ada tests khusus domain Maintenance.  
> **Status approval kebutuhan:** belum disahkan oleh GA. Seluruh nama tabel, API, permission, status Maintenance di bawah = **usulan teknis**, bukan aturan dari manual Purchasing & Inventory.

## 0. Cara menggunakan checklist ini

- `[ ]` = TODO; `[x]` = DONE; untuk pekerjaan berlangsung/tertahan, tambahkan `**IN PROGRESS**` / `**BLOCKED: alasan**` setelah item, tetapi jangan menghapus ID.
- Kerjakan **satu task kecil atau satu paket dependensi** per iterasi. Simpan hash commit/PR atau hasil tes dalam catatan pengerjaan.
- Tugas dianggap **DONE** hanya bila artefaknya tersedia, acceptance criteria terpenuhi, dan tes yang relevan lulus. Jangan checklist hanya karena kode sudah ditulis.
- ID tugas (`MGA-R1-005`, dst.) **tidak berubah** meskipun deskripsi direvisi. Jangan daur ulang ID; gunakan ID baru untuk pekerjaan tambahan.
- Setiap revisi mencatat entri di **Change Log**, memperbarui **Decision Log**, mengidentifikasi ID terdampak, dan menambah/memperbarui test.
- Prioritas: `P0` = prasyarat/kritis; `P1` = inti MVP rekomendasi RPD; `P2` = lanjutan. Prioritas ini merupakan perencanaan teknis, **bukan** berasal langsung dari gambar.
- Untuk task yang bergantung pada SOP atau keputusan bisnis, status harus **BLOCKED** sampai keputusan disetujui. Jangan menyamakan usulan dengan keputusan final.

### Aturan yang dilindungi dari backend existing

1. Tetap menggunakan Laravel 13, MySQL, `app/Domain`, Sanctum, Spatie Permission dengan guard `web` saat ini, dan API JSON v1.
2. **Jangan mengganti role existing** `super_admin`, `hr`, `staff_hr`, `general_manager` atau flow approval PO tanpa keputusan eksplisit. PIC `GA` pada gambar bukan otomatis role Spatie.
3. PO approval, Order otomatis, GRN Pending tanpa mutasi, GRN confirmed `diterima - rusak`, distribusi mengurangi stok **hanya ketika Sent**, finance/payment, dan dokumen tetap mengikuti aturan existing.
4. Satu-satunya pemilik saldo Item ialah domain Inventory melalui **StockService**; jangan `Item::decrement()` dari Maintenance. Kuantitas `DECIMAL(18,3)` dan StockDecimal existing dipertahankan.
5. Migration existing jangan diedit. Setiap migration baru harus aman untuk data berjalan; sediakan pemeriksaan rollback/backup. Semua endpoint baru wajib auth + permission + ownership/Company scope bila disetujui, termasuk HTTP 401/403.
6. Pembacaan / impor Smart Salary, KM RIS dan “Whatsup” belum boleh diasumsikan mempunyai API. Tidak membuat auto-sync tanpa kontrak yang disetujui.

## 1. Peta fase dan gate

| Fase | Sasaran | Gate untuk selesai |
|---|---|---|
| **R0** | Finalisasi kebutuhan, SOP, ownership, aturan domain & scope MVP | Keputusan relevan tercatat dan disetujui GA / product owner |
| **R1** | ERP Core GA + master aset, kendaraan, log KM | ERD + migration + API + RBAC + tests lulus; batas data per PT jelas |
| **R2** | Jadwal servis, KIR/STNK/asuransi, Maintenance Request, Work Order | Aturan due dan status tervalidasi; audit, privasi dokumen, 403 diuji |
| **R3** | Spare part via Inventory, ATK, pengadaan via Purchasing | Tidak ada stok ganda; lock + idempotensi + rollback + regresi lulus |
| **R4** | Peminjaman, mutasi/disposal aset, fasilitas/gedung, sewa, dashboard GA | SOP & otorisasi disetujui; audit dan anti-overlap booking lulus |
| **R5** | Impor data, integrasi yang layak, laporan, UAT, deployment | Data terverifikasi, seluruh regression tests lulus, sign-off operasional |

**Urutan fleksibel:** task antarfase boleh diparalelkan jika dependensi terpenuhi; keputusan yang belum ada tetap menjadi blocker. Memajukan/menunda scope dicatat lewat CR, bukan menghapus task.

## 2. Keputusan bisnis — BLOCKER TERLACAK (RPD D-01 s.d. D-13)

Semua `PENDING` sampai ada jawaban dari pemilik proses GA. Kelompok di bawah bukan hasil keputusan otomatis.

| ID | Hal yang harus diputuskan | Menghambat | Status |
|---|---|---|---|
| D-01 | Jenis aset: kendaraan, alat berat, kantor, bangunan, ATK masuk satu domain GA atau tidak | R1 master | PENDING |
| D-02 | Definisi `Max 10.000 KM`: interval dari servis terakhir atau parameter lain | R2 service due | PENDING |
| D-03 | Servis alat berat: setiap 2 bulan, jam operasi, atau gabungan | R1 meter / R2 plan | PENDING |
| D-04 | Frekuensi cek KIR 6 bulan, STNK 1 tahun, asuransi 1 tahun vs tanggal kedaluwarsa nyata | R2 dokumen & scheduler | PENDING |
| D-05 | Alur peminjaman kendaraan dan siapa menyetujui/serah-terima | R4 pinjam kendaraan | PENDING |
| D-06 | SOP mutasi, penjualan, dan disposal aset serta otorisasi | R4 asset movements | PENDING |
| D-07 | Identitas sistem tertulis “Whatsup”, akses API Smart Salary & KM RIS | R5 integration | PENDING |
| D-08 | Stock opname ATK: kolom Daily, deskripsi menyebut tiap 1 bulan | R3 scheduler/opname | PENDING |
| D-09 | Pengajuan pengadaan ATK: kolom Daily, deskripsi menyebut 1 bulan sekali | R3 pengadaan | PENDING |
| D-10 | Peran GA Operator/Supervisor/teknisi/pemohon serta matriks approval dan PIC | R1 RBAC / R2 workflow | PENDING |
| D-11 | Batas data per PT, relasi `Company` existing, dan mutasi lintas PT | R1 master aset | PENDING |
| D-12 | Titik stok berkurang saat spare part dipakai & aturan retur | R3 Inventory | PENDING |
| D-13 | Request Smart Salary: wajib menjadi WO atau boleh ditutup tanpa WO | R2 request & import | PENDING |

**Keputusan sementara untuk perencanaan (TIDAK FINAL):** D-12 usulan saat barang fisik keluar dari gudang, dengan retur sebagai transaksi tersendiri; D-04 simpan `expires_at` riil dan bedakan jadwal pengecekan; D-08/D-09 jangan membuat job scheduler sebelum disetujui.

## 3. R0 — Validasi bisnis dan baseline

- [x] **MGA-R0-001** `[P0]` Kumpulkan sumber kebutuhan: foto jobdesk GA dan RPD Maintenance GA v1.0 (draft). **Bukti:** dokumen dan foto tersedia.
- [x] **MGA-R0-002** `[P0]` Catat baseline tes Purchasing & Inventory. **Bukti:** laporan pengguna `338 passed (2669 assertions)`.
- [x] **MGA-R0-003** `[P0]` Audit source code terakhir sebelum implementasi: User/RBAC, Company, Item, StockService, StockMovement, routes, migration & tests. **Bukti:** `MAINTENANCE_R0_003_BASELINE_AUDIT.md`, commit acuan `9583abd`, GitHub Actions run `45316651290` sukses: 346 tests / 2.722 assertions. Audit statis + baseline existing; belum ada implementasi Maintenance.
- [ ] **MGA-R0-004** `[P0]` Review cakupan 23 kebutuhan sumber (`VEH-01..11`, `AST-01..06`, `FAC-01..03`, `ATK-01..03`) dengan GA. **IN PROGRESS — persiapan 23/23 baris siap, belum divalidasi GA.** **Artefak:** `MAINTENANCE_R0_004_GA_VALIDATION_MATRIX.md`. **DoD:** GA meninjau semua baris, bukti dan hasilnya dicatat.
- [ ] **MGA-R0-005** `[P0]` Finalisasi D-01, D-10, D-11: kelompok aset, PIC/approval, data per PT. **BLOCKED — seluruhnya PENDING, belum sign-off GA.** **Artefak:** `MAINTENANCE_R0_005_DECISION_REGISTER.md`. **DoD:** keputusan bertanggal, nama penyetuju dan bukti disimpan.
- [ ] **MGA-R0-006** `[P0]` Finalisasi D-02, D-03, D-04 dan D-13 sebelum jadwal servis/request. **DoD:** rumus due dan flow request ditulis dengan contoh kasus.
- [ ] **MGA-R0-007** `[P0]` Finalisasi D-08, D-09, D-12 sebelum implementasi stok/ATK. **DoD:** titik pengeluaran, retur, jadwal ATK disahkan.
- [ ] **MGA-R0-008** `[P1]` Kumpulkan SOP D-05, D-06 dan informasi sistem D-07; tandai blocked bila belum ada. **DoD:** kebutuhan R4/R5 bisa diprioritaskan tanpa mengarang SOP.
- [ ] **MGA-R0-009** `[P0]` Definisikan scope MVP final serta acceptance criteria per FR; buat matriks akses per aksi. **IN PROGRESS — proposal scope dan matriks API/permission disiapkan, belum final/sign-off.** **Artefak:** `MAINTENANCE_R0_009_MVP_RBAC_PROPOSAL.md`. **DoD:** keputusan D-01/D-10/D-11 + GA/technical lead sign-off dan tes disetujui.
- [ ] **MGA-R0-010** `[P0]` Buat ERD per irisan kecil (R1 dulu), bukan seluruh modul sekaligus. **IN PROGRESS (DRAFT v0.8) — ERD sementara dan skenario registrasi bertahap tersedia; finalisasi tetap BLOCKED D-01/D-11 dan sign-off GA.** **Artefak:** `MAINTENANCE_R0_010_ERD_R1_REVISI_DRAFT_v0.8.md`; `MAINTENANCE_R0_R1_REKAP_DISKUSI_KEPUTUSAN_SEMENTARA_2026-11-24.md`. **DoD:** FK, indeks, unique constraints, soft-delete, dan retensi dokumen jelas.

## 4. R1 — Master GA, aset dan kendaraan

- [ ] **MGA-R1-001** `[P0]` Tentukan batas domain `app/Domain/Maintenance` dan pola Actions/Models/Services mengikuti existing. **IN PROGRESS (proposal dokumentasi, bukan implementasi)**. **Artefak:** `MAINTENANCE_R1_001_DOMAIN_STRUCTURE_PROPOSAL_v0.1.md`. **Deps:** R0-003, R0-009. **DoD:** struktur kecil, tidak memindahkan domain lama.
- [ ] **MGA-R1-002** `[P0]` Tentukan permission usulan per action dan metode pemberian akses menggunakan `ModulePermissionRegistrar`; **jangan otomatis memberikan role GA**. **IN PROGRESS (draft matriks); BLOCKED untuk persetujuan D-10**. **Artefak:** `MAINTENANCE_R1_002_RBAC_MATRIX_DRAFT_v0.1.md`; klasifikasi Item hanya `super_admin`/`hr` DAN permission khusus. **Deps:** D-10. **DoD:** matrix API→permission→owner.
- [ ] **MGA-R1-003** `[P0]` ERD dan migration `maintenance_assets` terhubung ke `companies` sesuai D-11. **Deps:** D-01, D-11. **DoD:** constraint, uniqueness kode aset, dan rules integritas tercakup.
- [ ] **MGA-R1-004** `[P1]` Asset Model + Form Requests + API Resource + Actions + CRUD master aset (FR-01). **Deps:** R1-003. **DoD:** validasi, filter, pagination, aktif/nonaktif, akses 401/403/422.
- [ ] **MGA-R1-005** `[P1]` Asset numbering/registrasi setelah pembelian Purchasing (AST-01, AST-02). **IN PROGRESS (kontrak API draft saja), implementasi BLOCKED oleh R1-004 dan keputusan GA.** **Artefak:** `MAINTENANCE_R1_ASSET_REGISTRATION_API_CONTRACT_DRAFT_v0.4.md`; GRN confirmed, registrasi manual bertahap dan idempotensi. **Deps:** R1-004. **DoD:** tidak menduplikasi aset; referensi PO/GRN bersifat opsional jika disahkan.
- [ ] **MGA-R1-006** `[P1]` ERD dan migration profil kendaraan/alat berat (FR-02; VEH-01/05/06). **Deps:** D-03. **DoD:** mendukung pembacaan KM atau jam operasi bila relevan; bukan asumsi semua KM.
- [ ] **MGA-R1-007** `[P1]` API master kendaraan/alber: metadata, identitas, status, relasi aset. **Deps:** R1-006. **DoD:** validasi dan ownership diuji.
- [ ] **MGA-R1-008** `[P1]` ERD/migration histori odometer/hourmeter, input manual harian (FR-03; VEH-01). **Deps:** R1-006. **DoD:** nilai tidak menurun tanpa koreksi beralasan dan audit.
- [ ] **MGA-R1-009** `[P1]` API log KM/meter, riwayat, last-reading, pencegahan duplikasi, koreksi beralasan. **Deps:** R1-008. **DoD:** test chronological, duplicate, forbidden.
- [ ] **MGA-R1-010** `[P1]` Deteksi log operational control terlambat (VEH-02), manual reminder dulu. **Deps:** R1-009. **DoD:** definisi overdue disetujui; belum mengirim pengingat otomatis tanpa jadwal.
- [ ] **MGA-R1-011** `[P0]` Feature test R1: role/record ownership, data per PT, validasi kendaraan, audit KM, dan rollback. **DoD:** seluruh tes baru + regresi existing lulus.

## 5. R2 — Preventive maintenance, dokumen, requests, Work Order

- [ ] **MGA-R2-001** `[P0]` Finalisasi state machine Request & Work Order beserta role, creator, approver, teknisi. **Deps:** D-10, D-13. **DoD:** state diagram disahkan; status masih proposal sampai tahap ini.
- [ ] **MGA-R2-002** `[P1]` ERD/migration maintenance plan untuk kendaraan dan alat berat (FR-04; VEH-03..06). **Deps:** D-02, D-03. **DoD:** interval KM/jam/hari dapat dibedakan.
- [ ] **MGA-R2-003** `[P1]` Service penghitung jatuh tempo, toleransi, dan histori servis. **Deps:** R2-002. **DoD:** tes batas KM yang disahkan, kalender, repeat dan reset pascaservis.
- [ ] **MGA-R2-004** `[P1]` API jadwal servis + daftar due/overdue + rekaman servis selesai. **Deps:** R2-003. **DoD:** data terurut, filter, status & audit.
- [ ] **MGA-R2-005** `[P1]` ERD/migration dokumen kendaraan KIR/STNK/asuransi (FR-05; VEH-07..09). **Deps:** D-04. **DoD:** tanggal cek berbeda dengan expiry aktual.
- [ ] **MGA-R2-006** `[P0]` API upload/download dokumen private, validasi MIME/ukuran, izin dan audit. **Deps:** R2-005. **DoD:** unauthorized/forbidden download ditolak; path tidak terekspos.
- [ ] **MGA-R2-007** `[P1]` Notifikasi due dokumen dan jadwal servis dengan riwayat dan penanganan duplikasi. **Deps:** R2-003, R2-005. **DoD:** cadence dan ambang reminder disetujui; queue after commit.
- [ ] **MGA-R2-008** `[P1]` ERD/migration request perbaikan aset/kendaraan; dukung sumber Smart Salary + external reference unik bila tersedia (FR-06; VEH-10, AST-03). **Deps:** D-13. **DoD:** impor ulang tidak membuat tiket ganda.
- [ ] **MGA-R2-009** `[P1]` API request create/list/detail/transition sesuai status disahkan. **Deps:** R2-001, R2-008. **DoD:** maker/owner constraints dan forbidden role diuji.
- [ ] **MGA-R2-010** `[P1]` ERD/migration Work Order, penugasan, tanggal, biaya bila diperlukan, dokumen bukti dan activity log (FR-07; AST-06, VEH-03, FAC-02). **Deps:** R2-001. **DoD:** relasi dengan request/aset jelas.
- [ ] **MGA-R2-011** `[P1]` API Work Order create/assign/start/complete/close (hanya state disahkan). **Deps:** R2-010. **DoD:** transisi invalid 409, role salah 403, idempotensi penyelesaian.
- [ ] **MGA-R2-012** `[P1]` Event/audit append-only untuk request, perubahan jadwal, WO, pekerjaan dan dokumen (FR-17). **Deps:** R2-008..11. **DoD:** siapa/kapan/sebelum/sesudah terlacak.
- [ ] **MGA-R2-013** `[P0]` Feature test R2: due boundary, expired doc, forbidden file, external dedupe, invalid status, approval, rollback dan regression. **DoD:** test pass.

## 6. R3 — Integrasi stok, spare part dan ATK

- [ ] **MGA-R3-001** `[P0]` Putuskan apakah Work Order membutuhkan spare part, jenis transaksi issue, retur, dan aktor penerbit. **Deps:** D-12. **DoD:** approval/aturan mutasi disahkan.
- [ ] **MGA-R3-002** `[P0]` ERD/migration `maintenance_work_order_parts`, `maintenance_part_issues`, dan FK unik pada `stock_movements` **melalui migration baru**, bukan edit migration lama. **Deps:** R3-001. **DoD:** relasi dan constraint tidak merusak mutasi existing.
- [ ] **MGA-R3-003** `[P0]` Tambah operasi khusus **di Inventory StockService** untuk maintenance issue dengan lock konsisten, audit, idempotency key dan saldo nonnegatif. **Deps:** R3-002. **DoD:** replay tidak menggandakan stock movement.
- [ ] **MGA-R3-004** `[P1]` Service & API pengeluaran spare part dari WO, parsial dan batas kuantitas (FR-07). **Deps:** R3-003. **DoD:** tidak boleh issue pada status yang tidak mengizinkan.
- [ ] **MGA-R3-005** `[P1]` Rancang retur/reversal fisik spare part jika disetujui; jangan memakai stock adjustment tanpa alasan dan sumber bisnis. **Deps:** D-12. **DoD:** mutasi dan audit dua arah sesuai SOP.
- [ ] **MGA-R3-006** `[P1]` Mapping ATK ke SKU/kategori **Item Inventory existing** (FR-13; ATK-01). **DoD:** tidak ada saldo ATK baru; GRN/Distribution sesuai aturan lama.
- [ ] **MGA-R3-007** `[P1]` API ringkasan ATK masuk/keluar dari stock_movements existing; hak akses GA mengikuti keputusan D-10. **Deps:** R3-006. **DoD:** data tidak dimodifikasi oleh endpoint laporan.
- [ ] **MGA-R3-008** `[P1]` Stock opname ATK memakai Stock Adjustment existing dan `StockService::adjustTo`, dengan reason, expected balance dan idempotensi (FR-14; ATK-02). **Deps:** D-08. **DoD:** saldo berubah hanya melalui jalur existing; jadwal mengikuti keputusan.
- [ ] **MGA-R3-009** `[P1]` Low-stock ATK dan usulan pengadaan yang memanfaatkan PO Purchasing existing (FR-15; ATK-03). **Deps:** D-09. **DoD:** tidak membuat approval PO atau Order paralel; tidak men-trigger PO otomatis tanpa keputusan.
- [ ] **MGA-R3-010** `[P0]` Feature test simultan & rollback: issue dua request bersamaan, replay, kekurangan saldo, over-issue, ATK opname, PO/GRN/Distribution regression. **DoD:** hasil DB konsisten dan semua tes pass.

## 7. R4 — Operasional GA lanjutan

- [ ] **MGA-R4-001** `[P0]` Setujui SOP dan matriks izin peminjaman kendaraan (D-05). **DoD:** siapa request/approve/hand-over/return, overlap policy jelas.
- [ ] **MGA-R4-002** `[P2]` ERD/migration & API vehicle loan, tanggal mulai/akhir, penerima, km out/in, audit (FR-08; VEH-11). **Deps:** R4-001. **DoD:** dua booking bentrok ditolak dengan lock/constraint yang sesuai.
- [ ] **MGA-R4-003** `[P0]` Setujui SOP mutasi dan disposal/penjualan aset (D-06). **DoD:** approval, catatan PT, kondisi & transaksi ditetapkan.
- [ ] **MGA-R4-004** `[P2]` ERD/migration & API asset movement: dari/ke PT atau lokasi, bukti dan histori (AST-04). **Deps:** D-11, R4-003. **DoD:** audit lengkap; bukan stock movement SKU.
- [ ] **MGA-R4-005** `[P2]` ERD/migration & API disposal/penjualan aset dengan approval (AST-05). **Deps:** R4-003. **DoD:** tidak menyelundupkan jurnal accounting yang belum disepakati.
- [ ] **MGA-R4-006** `[P2]` ERD/migration & API inspeksi kebersihan, jadwal mingguan, checklist office boy (FR-10; FAC-01). **DoD:** hasil dan petugas terlacak.
- [ ] **MGA-R4-007** `[P2]` ERD/migration & API inspeksi kondisi gedung triwulanan, temuan dan tindak lanjut WO (FR-11; FAC-02). **DoD:** temuan terhubung WO bila perlu.
- [ ] **MGA-R4-008** `[P2]` ERD/migration & API daftar sewa gedung, jatuh tempo dan lampiran private (FR-12; FAC-03). **DoD:** tidak mengklaim payment Finance terintegrasi tanpa keputusan.
- [ ] **MGA-R4-009** `[P2]` Service dashboard GA ringkasan log KM terlambat, due servis/dokumen, WO, ATK, inspeksi, sewa (FR-16). **DoD:** query read-only + filter & scope.
- [ ] **MGA-R4-010** `[P0]` Feature test R4: loan overlap, approval asset movement, private file, jadwal inspeksi, sewa due, dashboard 403 dan regression. **DoD:** tests pass.

## 8. R5 — Migrasi, integrasi, laporan & rollout

- [ ] **MGA-R5-001** `[P0]` Petakan kolom Google Sheet ke asset/vehicle/KM/request; buat aturan cleansing dan dedupe. **DoD:** mapping, mandatory/optional & data ownership disetujui.
- [ ] **MGA-R5-002** `[P1]` Command/importer CSV/Excel secara staging/dry-run, validasi dan error report; jangan mengimpor langsung ke production tanpa preview. **Deps:** R5-001. **DoD:** retry aman & jejak sumber tersimpan.
- [ ] **MGA-R5-003** `[P2]` Integrasi Smart Salary untuk request jika D-07 disepakati dan akses resmi tersedia; kalau tidak, dokumentasi input manual/import. **DoD:** idempotent & no credential leakage.
- [ ] **MGA-R5-004** `[P2]` Integrasi KM RIS jika D-07/API resmi tersedia; kalau tidak, log KM manual tetap berlaku. **DoD:** sumber/validitas data tercatat.
- [ ] **MGA-R5-005** `[P2]` Evaluasi “Whatsup” hanya setelah identitas layanan dan tujuan valid; jangan buat dependency blocker untuk MVP. **Deps:** D-07.
- [ ] **MGA-R5-006** `[P1]` API ekspor laporan GA + audit/filter per PT & periode (FR-17). **DoD:** anti data-leak, pagination/export limit dan tests.
- [ ] **MGA-R5-007** `[P0]` Regression lengkap (mulai baseline 338 test) + concurrency, rollback, policies dan validasi migration up/down di DB terisolasi. **DoD:** seluruh test lulus; perubahan jumlah test dilacak.
- [ ] **MGA-R5-008** `[P0]` UAT dengan GA untuk 23 kebutuhan; bedakan pass/deferred/gap dan bukti. **DoD:** sign-off dan daftar defect.
- [ ] **MGA-R5-009** `[P0]` Backup, restore drill, deployment plan, feature rollout, monitoring reminder/queue, logging serta prosedur rollback. **DoD:** rencana operasional diterima.
- [ ] **MGA-R5-010** `[P1]` Dokumentasi backend untuk repo: README domain, ERD final, endpoint & permission matrix, runbook maintenance, Known Issues. **DoD:** bisa dioperasikan orang lain.

## 9. Matriks keterlacakan RPD — gambar → fitur → task

| Sumber foto / RPD | FR | Task utama |
|---|---|---|
| VEH-01, VEH-02 (KM/operational control) | FR-02, FR-03 | R1-006..010 |
| VEH-03..06 (jadwal servis kendaraan/alber) | FR-04, FR-07 | R2-002..004, R2-010..011 |
| VEH-07..09 (KIR/STNK/asuransi) | FR-05 | R2-005..007 |
| VEH-10 (request kendaraan Smart Salary) | FR-06 | R2-008..009, R5-003 |
| VEH-11 (peminjaman kendaraan) | FR-08 | R4-001..002 |
| AST-01..02 (database/nomor aset) | FR-01 | R1-003..005 |
| AST-03 (log request perbaikan aset) | FR-06 | R2-008..009 |
| AST-04..05 (mutasi/penjualan aset) | FR-09 | R4-003..005 |
| AST-06 (pemeliharaan aset) | FR-07 | R2-010..012 |
| FAC-01 (kebersihan) | FR-10 | R4-006 |
| FAC-02 (maintenance gedung) | FR-11 | R4-007 |
| FAC-03 (jatuh tempo sewa) | FR-12 | R4-008 |
| ATK-01 (barang masuk/keluar) | FR-13 | R3-006..007 |
| ATK-02 (stock opname) | FR-14 | R3-008 |
| ATK-03 (pengadaan stok menipis) | FR-15 | R3-009 |
| Lintas sumber (dashboard/audit) | FR-16, FR-17 | R2-012, R4-009, R5-006 |

> `VEH`, `AST`, `FAC`, `ATK`, `FR` adalah kode pelacakan dari RPD, bukan nama tabel. Tabel RPD versi 1.0 memiliki total 23 kebutuhan sumber (11 kendaraan, 6 aset, 3 fasilitas, 3 ATK) dan 17 FR teknis.

## 10. Template catatan implementasi per task

Salin template berikut ke bawah entri tugas bila mulai mengerjakan:

```text
Task ID       : MGA-Rx-xxx
Status        : TODO | IN PROGRESS | BLOCKED | DONE
Tanggal mulai : YYYY-MM-DD
Owner         : ...
Dependensi    : D-xx / MGA-Rx-xxx
File dibuat   : ...
File diubah   : ...
Migration     : ... (jika ada)
API           : ... (jika ada)
Tests         : php artisan test --filter=... -> ... passed
Regression    : php artisan test -> ... passed (... assertions)
Commit / PR   : ...
Catatan       : ...
```

## 11. Template permintaan revisi (Change Request)

**Jangan edit requirement lama diam-diam.** Untuk tiap perubahan:

```text
CR ID              : CR-YYYY-001
Tanggal            : YYYY-MM-DD
Pengusul           : GA / Product Owner / Technical Lead
Sumber perubahan   : SOP / RPD / hasil UAT / bug / permintaan baru
Ringkasan          : ...
Alasan             : ...
Tugas terdampak    : MGA-R1-xxx, MGA-R2-xxx
Keputusan terkait  : D-xx
Sebelum            : ...
Sesudah            : ...
Dampak DB/API/RBAC : ...
Dampak PO/GRN/Stok : ... (harus eksplisit)
Risiko / rollback  : ...
Test yang ditambah : ...
Disetujui oleh     : ...
Status             : PROPOSED | APPROVED | REJECTED | IMPLEMENTED
```

**Aturan versi:** `v1.0 → v1.1` untuk klarifikasi/perubahan tanpa mengganti struktur besar; `v2.0` untuk perubahan scope/flow besar. Catat tanggal dan perubahan di bawah. Ubah matriks keterlacakan dan DoD jika FR bergeser.

### Change Log

| Versi | Tanggal | CR / Keputusan | Perubahan | Dampak / Tes |
|---|---|---|---|---|
| 1.0 | 2026-11-23 | Baseline awal | Master TODO dari RPD v1.0 + gambar jobdesk GA, baseline 338 test | Belum mengubah code/database |
| 1.1 | 2026-11-24 | MGA-R0-003 | Audit baseline source, identifikasi batas domain/risiko, dokumentasi tersimpan di repo; baseline CI diperbarui menjadi 346 tests / 2.722 assertions | Dokumen saja, tidak ada perubahan schema/API/RBAC/production; D-01..D-13 tetap pending |
| 1.2 | 2026-11-24 | MGA-R0-004 / MGA-R0-005 / MGA-R0-009 | Matriks validasi 23 kebutuhan, register keputusan 13 isu, dan proposal MVP/RBAC dibuat | Dokumentasi saja; tidak ada implementasi; D-01..D-13 seluruhnya PENDING, tidak ada sign-off GA atau perubahan role/skema/API/stock |
| 1.3 | 2026-11-24 | MGA-R0-005 / R0-010 / R1-001 / R1-002 / R1-005 | Arsip preferensi diskusi pengguna; ERD R1 v0.8, kontrak API v0.4, proposal struktur domain dan matriks RBAC tersedia | Hanya dokumen; D-01/D-10/D-11 dan D lain tetap PENDING secara formal; tanpa migration/route/role/stock dan tes Maintenance belum dijalankan |

### Decision Log

| Tanggal | ID | Keputusan final | Disetujui oleh | Task terdampak |
|---|---|---|---|---|
| — | — | Belum ada keputusan bisnis final (termasuk D-01/D-10/D-11) | — | — |

## 12. Definition of Done untuk SETIAP fitur backend

- [ ] Kebutuhan RPD dan keputusan bisnis telah dikaitkan dengan ID tugas/FR.
- [ ] ERD dan migration baru aman untuk existing schema, FK/index/unique jelas.
- [ ] API JSON konsisten dengan `ApiResponse`, Form Request, Resource dan versi `/api/v1`.
- [ ] Role salah mendapat 403, guest 401, payload invalid 422, status konflik 409 (jika relevan); ownership/per PT diuji.
- [ ] Status dan perubahan penting diaudit; file private, aman diunduh.
- [ ] Transaksi kritis memakai lock, idempotensi, rollback dan audit mutasi stok bila relevan.
- [ ] Test positif-negatif, replay, race/concurrency bila relevan serta regression existing **semuanya lulus**.
- [ ] Catatan commit/PR, update checklist, serta change log tersedia.

## 13. NEXT TASK yang disarankan

**Lanjut `MGA-R0-004` dan `MGA-R0-005` bersama GA**: draft matriks 23/23 dan register D-01/D-10/D-11 sudah tersedia, dengan preferensi desain sementara pengguna tercatat di `MAINTENANCE_R0_R1_REKAP_DISKUSI_KEPUTUSAN_SEMENTARA_2026-11-24.md`, tetapi belum disetujui. Proposal `MGA-R0-009` boleh direview; belum boleh mengeksekusi migrasi master aset. Audit `MGA-R0-003` tersedia di `MAINTENANCE_R0_003_BASELINE_AUDIT.md`. Keputusan D-02/D-03/D-04, D-08/D-09, D-12 tidak boleh ditebak ketika mulai servis/ATK/stok. Selama belum disetujui, kita masih bisa menyiapkan proposal ERD, test skenario dan dokumentasi—tanpa migrasi production.