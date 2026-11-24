# MGA-R1-001 — Proposal Struktur Domain Maintenance (v0.1)

**Dibuat:** 24 November 2026 | **Target branch:** `feat/maintenance-backend` | **Status:** DRAFT PERENCANAAN.
**Dependensi:** MGA-R0-003 audit tersedia; MGA-R0-009 scope MVP masih draft; D-01/D-10/D-11 belum sign-off.
**Penting:** Berkas ini hanya desain, bukan daftar file PHP yang sudah dibuat.

## 1. Batas domain dan keputusan sementara

- Laravel 13 + MySQL + Eloquent + API JSON v1; pertahankan pola `app/Domain`, Form Request, `ApiResponse`, Sanctum dan Spatie guard `web`.
- Purchasing tetap pemilik Purchase Order, Order dan GRN melalui domain Purchasing/Receiving. Maintenance membaca GRN confirmed, **tidak memicu** proses konfirmasi.
- Inventory tetap pemilik saldo `items.stock_quantity` dan `StockService`; proses registrasi aset **tidak** mengubah saldo atau stock movement.
- Master Barang (`App\Domain\Item`) tetap pemilik SKU, kategori, dan flag kelayakan `is_asset_trackable` (usulan migration baru). Proses perubahan flag hendaknya berada dalam action terkontrol yang tidak melewati `SaveItem` CRUD umum.
- Maintenance mengelola **aset fisik per unit**, registrasi batch, nomor aset per PT, log KM/jam operasi, dan audit Maintenance; bukan saldo Inventory kedua.
- Gedung milik vs sewa dipisahkan dalam pemodelan nanti; sewa tidak otomatis diperlakukan sebagai aset milik.
- Role klasifikasi sementara: hanya `super_admin` atau `hr` dengan permission khusus. Action Maintenance lain disetujui terpisah (D-10).

## 2. Pohon file Laravel yang diusulkan (belum ada)

```text
backend/
├── app/
│   ├── Domain/
│   │   ├── Item/
│   │   │   ├── Models/Item.php                         [existing; tambah cast nanti]
│   │   │   └── Actions/SetAssetTrackability.php        [baru; controlled mutation]
│   │   ├── Authorization/
│   │   │   └── Services/ModulePermissionRegistrar.php  [existing; dipakai, tidak diubah]
│   │   └── Maintenance/                             [folder domain baru]
│   │       ├── Models/
│   │       │   ├── MaintenanceAsset.php
│   │       │   ├── MaintenanceAssetRegistration.php
│   │       │   ├── MaintenanceAssetSequence.php
│   │       │   └── MaintenanceAssetClassificationEvent.php  [usulan audit]
│   │       ├── Queries/
│   │       │   └── ListAssetCandidates.php
│   │       ├── Actions/
│   │       │   ├── RegisterAssetsFromConfirmedGrn.php
│   │       │   └── RegisterLegacyAsset.php
│   │       ├── Policies/
│   │       │   ├── AssetClassificationPolicy.php
│   │       │   └── MaintenanceAssetPolicy.php
│   │       └── Services/
│   │           ├── AssetNumberAllocator.php
│   │           └── AssetRegistrationQuota.php
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── MaintenanceItemClassificationController.php
│   │   │   ├── MaintenanceAssetCandidateController.php
│   │   │   └── MaintenanceAssetRegistrationController.php
│   │   ├── Requests/V1/Maintenance/
│   │   │   ├── SetItemAssetClassificationRequest.php
│   │   │   ├── RegisterAssetsFromGrnRequest.php
│   │   │   └── RegisterLegacyAssetRequest.php
│   │   └── Resources/Api/V1/
│   │       ├── MaintenanceAssetResource.php
│   │       └── MaintenanceAssetCandidateResource.php
│   └── ...
├── routes/api/v1.php                                  [existing; tambah group setelah gate]
├── config/erp.php                                     [existing; daftarkan menu setelah API benar-benar tersedia]
└── tests/Feature/Api/V1/
    ├── MaintenanceItemClassificationTest.php
    ├── MaintenanceAssetRegistrationTest.php
    └── MaintenanceAssetRbacTest.php
```

Semua file di atas **nama/path usulan**, tidak berasal dari manual dan tidak harus semuanya dibangun dalam satu PR.

## 3. Pembagian tanggung jawab

| Lapisan | Tanggung jawab | Tidak boleh |
|---|---|---|
| `SetAssetTrackability` (Item Action) | Lock Item, ubah flag saja setelah validasi, tulis audit atomik | Mutasi stok, mengubah klasifikasi lewat `SaveItem` biasa |
| `AssetClassificationPolicy` | **Role (`super_admin`/`hr`) DAN permission `maintenance.asset-items.classify`**; guard web | Meloloskan `staff_hr` hanya karena diberi permission |
| `ListAssetCandidates` (Maintenance Query) | GRN confirmed, usable units, klasifikasi Item, PT, remaining quota; read-only | Membuat aset saat GET atau menambah saldo |
| `RegisterAssetsFromConfirmedGrn` | Penguncian, cek ulang PT/status/flag/kuota, allocation code, asset batch, idempotency, audit | Mengganti status GRN/Order, menulis Item stock |
| `AssetNumberAllocator` | Sequence unik per PT, reservation di transaksi yang sama | Membuat nomor berdasarkan `max(asset_code)+1` tanpa lock |
| `AssetRegistrationQuota` | Hitung unit baik dari satu GRN item, konsumsi slot satu kali walau asset nonaktif | Melepas slot karena soft delete atau mutasi PT |
| `RegisterLegacyAsset` | Aset historis tanpa GRN dengan bukti ownership PT, jalur terpisah | Menggunakan jalur legacy untuk bypass PO yang `company_id` kosong |
| Controllers/Requests/Resources | Thin adapters: auth, input, response JSON | Menyimpan business transaction multi-langkah di controller |

## 4. Batas transaksi dan locking (perancangan)

- POST registrasi: pemeriksaan idempotency, lock sumber GRN item dan Item sesuai urutan yang konsisten dengan konfirmasi GRN, hitung sisa kuota, lock sequence PT, buat batch dan N aset dalam satu `DB::transaction(..., attempts: 3)`.
- Aksi toggle flag harus menggunakan lock Item; POST registrasi **memeriksa flag ulang di bawah lock**. Patuhi urutan kunci global; uji concurrency di MySQL, jangan mengandalkan tes SQLite.
- Satu `goods_receipt_items.id` dapat menaut ke banyak `maintenance_assets`; setiap unit punya ordinal unik (termasuk aset soft-deleted).
- Idempotency key dipakai untuk replay payload yang sama; key sama payload beda → 409. Key baru hanya untuk batch baru dengan kuota sisa.
- `companies.id` pemilik berasal dari `purchase_orders.company_id` yang sudah diverifikasi; jika `NULL` tidak otomatis memilih PT aktif user. Pengguna telah menyatakan kepemilikan berdasarkan PT pembeli.
- Setiap perubahan dapat diuji rollback dan audit; tidak perlu menambah stock movement saat aset didaftarkan.

## 5. Irisan implementasi setelah business gate

1. **R1-001 desain selesai untuk direview** (dokumen ini); status task belum DONE karena MVP/ownership masih perlu sign-off.
2. **R1-002 matrix akses** (dokumen terkait) dan review GA; belum ada permission yang dibuat di DB.
3. **R1-003** migration additive Item flag dan Maintenance asset registration/sequence (hanya setelah D-01/D-11); audit klasifikasi juga membutuhkan migration baru.
4. **R1-004/R1-005** CRUD dan registrasi manual + tests. Jangan mengubah migration lama.
5. **R1-006..009** kendaraan, alat berat, meter reading dan audit setelah D-03.

## 6. Kontrak penerimaan (belum dijalankan)

- Auth 401, role/permission 403, input 422, konflik kuota/idempotency 409.
- Tidak ada role/permission otomatis, actor berizin dapat diaudit.
- GRN confirmed 6 usable, registrasi 2+4 → tepat 6 aset berbeda; retry tidak membuat duplikasi, parallel over-quota ditolak.
- ATK/SKU `is_asset_trackable=false` tidak muncul di kandidat.
- Semua test Purchasing & Inventory existing tetap hijau; CI baseline pada 24 Nov 2026 adalah 346 passed / 2.722 assertions **sebelum** pengembangan Maintenance.

**Status:** Perencanaan saja, tanpa migration, endpoint, test baru, atau perubahan GitHub.