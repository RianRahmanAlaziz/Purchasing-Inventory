# MGA-R1-005 — Kontrak API Registrasi Aset dari GRN (Draft v0.4)

**Tanggal:** 24 November 2026  
**Branch tujuan:** `feat/maintenance-backend` (penempatan manual; tidak diubah otomatis)  
**Status:** PROPOSAL, belum sign-off GA; **bukan endpoint/tabel yang sudah tersedia**.  
**Rujukan:** RPD Maintenance GA v1.0 (AST-01/02, FR-01), Master TODO MGA-R0-010/R1-005, `MAINTENANCE_R0_010_ERD_R1_REVISI_DRAFT_v0.8.md`.  
**Keputusan pengguna (sementara):** opsi B (GRN confirmed parsial boleh menjadi sumber) + opsi A (registrasi dipicu manual oleh petugas berizin) + **registrasi sebagian pada satu GRN item boleh dilakukan dalam beberapa batch**. Tidak ada perubahan flow GRN/PO/Order.

## 1. Kontrak bisnis / invariants

1. `ConfirmGoodsReceipt` dan `OrderStatusSync` bekerja sebagaimana existing; `GRN confirmed` **tidak otomatis** membuat aset. SKU dari Master Barang harus memiliki klasifikasi `is_asset_trackable = true` agar bisa menjadi calon aset (opsi C, belum sign-off).
2. **GET daftar calon aset** hanyalah pembacaan, bukan mutasi. Satu baris GRN berhak maksimal `qty_received - qty_damaged` unit baik, dikurangi unit yang **pernah** diregistrasi dari baris tersebut. **Satu baris GRN boleh diregistrasi dalam banyak batch** (contoh 2 lalu 4 unit dari kuota 6).
3. **POST secara eksplisit** oleh user berizin membuat satu record aset per unit fisik, dengan `asset_code` baru dan `company_id` yang berasal dari PO terkait.
4. Order boleh `partially_received`; **bukan** syarat `completed`. GRN pending tidak eligible.
5. Registrasi tidak memanggil StockService dan tidak mengubah saldo Item, StockMovement maupun status GRN/Order.
6. Daftar aset tetap mencatat sumber dan audit saat dipinjam/dipakai PT lain; `company_id` pemilik tidak berubah tanpa prosedur mutasi legal.
7. Jika PO tidak mempunyai `company_id` yang dapat diverifikasi, registrasi dari GRN diblokir; jalur legacy manual tidak boleh menjadi bypass.
8. Jumlah aset selalu bilangan unit utuh; `DECIMAL(18,3)` GRN hanya diterima untuk registrasi bila setelah konversi yang *disepakati* jumlahnya integral. **Tidak boleh membulatkan pecahan**.

## 2. Endpoint yang DIUSULKAN (belum ada di route existing)

| Method | Path | Tujuan | Kandidat permission | Sifat |
|---|---|---|---|---|
| GET | `/api/v1/maintenance/asset-candidates` | Cari GRN confirmed dengan kuota dan `items.is_asset_trackable = true` | `maintenance.assets.candidates.view` | Read-only |
| PATCH | `/api/v1/maintenance/item-classifications/{item}` | Menandai SKU eligible/non-eligible pada Master Barang | `maintenance.asset-items.classify` | Transaksi + audit; bukan Item CRUD umum |
| POST | `/api/v1/maintenance/asset-registrations/from-grn` | Registrasi N unit oleh petugas | `maintenance.assets.register-from-receipt` | Transaction + idempotensi |
| GET | `/api/v1/maintenance/asset-registrations/{registration}` | Lihat hasil batch & replay | `maintenance.assets.view` + PT/record scope | Read-only |
| GET | `/api/v1/maintenance/assets` | Daftar aset individu yang sudah dibuat | `maintenance.assets.view` | Read-only |
| POST | `/api/v1/maintenance/asset-registrations/legacy` | Aset lama tanpa PO/GRN, input HR berizin | `maintenance.assets.register-legacy` | Transaksi khusus; SOP bukti PT belum final |

**Pola middleware:** seluruh route privat berada dalam group `auth:sanctum` + `EnsureActiveAccount`; permission melalui `can:...`, tambahan policy untuk PT dan ownership. Role existing `super_admin`, `hr`, `staff_hr`, `general_manager` **tidak otomatis diberi** izin baru; grant setelah D-10. Visibility `erp.applications` hanya untuk menu, bukan mekanisme keamanan backend.

**Scopes:** `maintenance.assets.view` saja tidak berarti melihat semua PT. Kandidat `maintenance.assets.view-all-companies` memperluas jangkauan lihat, dan `maintenance.assets.manage-all-companies` untuk perubahan lintas PT bila disahkan. Semua aksi pemeriksaan PT ditetapkan di backend, tidak berdasarkan query parameter saja.

## 3. GET calon aset (read-only)

Contoh: `GET /api/v1/maintenance/asset-candidates?company_id=7&per_page=15`

**Respons ilustratif** (`ApiResponse`, bukan snapshot endpoint existing):

```json
{
  "success": true,
  "data": [
    {
      "goods_receipt_item_id": 480,
      "grn_number": "GRN-EXAMPLE-001",
      "po_number": "PO-EXAMPLE-012",
      "company_id": 7,
      "item_id": 33,
      "sku": "LAPTOP-EXAMPLE",
      "unit": "pcs",
      "qty_received": "7.000",
      "qty_damaged": "1.000",
      "usable_units": 6,
      "registered_units": 2,
      "remaining_units": 4,
      "is_asset_eligible": true
    }
  ],
  "meta": {"pagination": {"current_page": 1, "last_page": 1, "total": 1}}
}
```

**Perilaku:** exclude GRN pending, tidak otomatis menciptakan aset, tidak mengirim notifikasi/scheduler. Jika company_id belum ditentukan, kandidat dapat ditampilkan sebagai `needs_company_verification` hanya kepada petugas berizin; **tidak** boleh POST sampai tervalidasi. Field sensitif GRN di-filter sesuai scope PT. `remaining_units` adalah indikasi saat GET; **harus dihitung ulang pada POST dalam transaction**.

**Klasifikasi SKU (opsi C dipilih pengguna):** eligibility berasal dari Master Barang `items.is_asset_trackable` yang diusulkan melalui migration baru (default false), **bukan** tabel mapping eligibility atau keputusan ad-hoc pada GRN. Masih perlu pengesahan D-01/D-10. GET kandidat dan POST registrasi memvalidasi nilai tersebut, dan perubahan klasifikasi diaudit oleh aktor berizin.

## 4. POST registrasi manual setelah GRN confirmed

`POST /api/v1/maintenance/asset-registrations/from-grn`

```json
{
  "goods_receipt_item_id": 480,
  "idempotency_key": "0e70f54b-f8c0-4d8f-8e42-febd7a07e660",
  "units": [
    {"asset_name": "Laptop Operasional A", "asset_type": "office_equipment", "serial_number": "SN-EX-A"},
    {"asset_name": "Laptop Operasional B", "asset_type": "office_equipment", "serial_number": "SN-EX-B"}
  ]
}
```

**Validasi usulan:**

- `items.is_asset_trackable` harus true saat POST; kondisi dievaluasi ulang **di dalam transaksi** saat Item terkunci. Flag pada GET bukan jaminan POST akan tetap eligible.

- `goods_receipt_item_id`: wajib, integer, FK valid; ownership/GRN confirmed dicek dalam *Action*, bukan sekadar `Rule::exists`.
- `idempotency_key`: wajib UUID, unique global pada tabel batch.
- `units`: wajib array N (1..batas batch yang disepakati, **contoh** 1..50), tiap item metadata yang divalidasi; hitung unit dari jumlah elemen, **tidak percaya jumlah yang dikirim terpisah**.
- `asset_name`: string yang tervalidasi, `asset_type` harus bagian kategori aset yang disetujui, `serial_number` optional dan uniqueness kebutuhannya masih pending.
- `company_id`: **jangan menerima nilai bebas** untuk jalur ini; **ambil dari** `purchase_orders.company_id` dan cek scope. Jika field `company_id` diberikan, tolak atau abaikan sesuai kontrak final (lebih aman ditolak 422).
- Penolakan unit pecahan (`qty_received - qty_damaged`) tanpa konversi resmi; ID Item harus eligible; duplikasi/habis kuota 409.

**Respons 201** (contoh batch yang mendaftarkan dua unit setelah dua unit sebelumnya tercatat; prefix dan nomor urut masih ilustratif):

```json
{
  "success": true,
  "message": "Registrasi aset berhasil.",
  "data": {
    "registration_id": 120,
    "source": "confirmed_grn",
    "goods_receipt_item_id": 480,
    "company_id": 7,
    "registered_count": 2,
    "remaining_units": 2,
    "assets": [
      {"id": 801, "asset_code": "IND-AST-000001", "asset_name": "Laptop Operasional A"},
      {"id": 802, "asset_code": "IND-AST-000002", "asset_name": "Laptop Operasional B"}
    ]
  }
}
```

**Idempotensi per batch:** batch berikutnya dari GRN item yang sama harus memiliki **UUID baru**, sedangkan retry batch yang sama harus memakai UUID lama. Key sama + payload identik + aktor yang memiliki izin → `200` dengan IDs/kode **sama**, bukan penambahan. Key sama + payload berbeda → `409`. Validasi role/PT harus tetap dilakukan *sebelum* menampilkan respons replay. Fingerprint mencakup actor, goods_receipt_item_id, normalisasi urutan dan isi `units` agar key tidak dapat dibajak. Key yang sama antar-aktor harus ditolak/dianggap konflik, tidak membocorkan data.

## 4.1. Contoh dua batch yang sah dari baris GRN sama

GRN item #480 telah dikonfirmasi: `qty_received=6.000`, `qty_damaged=0.000`, `remaining_units=6`.

**Batch pertama**:

```json
{
  "goods_receipt_item_id": 480,
  "idempotency_key": "0e70f54b-f8c0-4d8f-8e42-febd7a07e660",
  "units": [
    {"asset_name": "Laptop A", "asset_type": "office_equipment", "serial_number": "SN-A"},
    {"asset_name": "Laptop B", "asset_type": "office_equipment", "serial_number": "SN-B"}
  ]
}
```

Hasil: **201**, `registered_count=2`, `registered_units=2`, `remaining_units=4`. Dua unit berikutnya **tidak perlu menunggu GRN/Order baru**.

**Batch kedua** (boleh hari berbeda, UUID berbeda):

```json
{
  "goods_receipt_item_id": 480,
  "idempotency_key": "97e8ad5f-b7c2-445c-83a9-720f71c36d2f",
  "units": [
    {"asset_name": "Laptop C", "asset_type": "office_equipment", "serial_number": "SN-C"},
    {"asset_name": "Laptop D", "asset_type": "office_equipment", "serial_number": "SN-D"},
    {"asset_name": "Laptop E", "asset_type": "office_equipment", "serial_number": "SN-E"},
    {"asset_name": "Laptop F", "asset_type": "office_equipment", "serial_number": "SN-F"}
  ]
}
```

Hasil: **201**, `registered_count=4`, `registered_units=6`, `remaining_units=0`. Request baru 1 unit dari GRN item #480 → **409**. Jika batch pertama diulang dengan UUID pertama dan payload identik → **200**, bukan empat atau dua aset tambahan.

**Penting:** `registered_count` = jumlah unit dalam **batch saat ini**, bukan total dari seluruh batch; tambahkan `registered_units` (kumulatif per GRN item) supaya konsumen API tidak keliru menghitung sisa. Semua perhitungan final dilakukan ulang pada POST di bawah lock.

## 4.2. Respons sukses kumulatif (usulan penyempurnaan kontrak)

```json
{
  "success": true,
  "message": "Registrasi aset berhasil.",
  "data": {
    "registration_id": 121,
    "goods_receipt_item_id": 480,
    "company_id": 7,
    "registered_count": 4,
    "registered_units": 6,
    "remaining_units": 0,
    "assets": [
      {"id": 803, "asset_code": "IND-AST-000003"},
      {"id": 804, "asset_code": "IND-AST-000004"},
      {"id": 805, "asset_code": "IND-AST-000005"},
      {"id": 806, "asset_code": "IND-AST-000006"}
    ]
  }
}
```

Nomor kode/prefix hanyalah contoh dan menunggu D-11. Untuk **replay**, kembalikan hasil batch yang identik; jika saldo kuota kumulatif sudah berubah sejak batch lama, pilih dan dokumentasikan kebijakan respons replay (disarankan `remaining_units` snapshot dari saat batch disimpan agar payload replay stabil), jangan secara diam-diam mencampur hasil lama dengan metadata kuota baru.

## 5. Rancangan transaksi & locking (pseudocode, BUKAN PHP produksi)

```text
begin DB::transaction(attempts: 3)
  identify GRN/PO/Order IDs via nonlocking preliminary read
  authorize current actor and PT scope (verify again after lock)
  inspect registration idempotency_key; if prior exists replayOrConflict
  lock purchase_orders row (consistent with GRN confirm)
  lock orders row for PO (if required by lock protocol)
  lock goods_receipts row
  lock goods_receipt_items row
  revalidate confirmed_at, status, foreign keys, item eligibility, company_id
  usableMilli = parse(qty_received) - parse(qty_damaged) using StockDecimal
  assert usableMilli > 0 AND usableMilli % 1000 == 0
  usableUnits = usableMilli / 1000
  priorUnits = count(ALL assets from ALL previous batches bound to this goods_receipt_item_id,
                     INCLUDING soft-deleted/retired records)
  requestedUnits = count(units)
  assert priorUnits + requestedUnits <= usableUnits
  lock maintenance_asset_sequences for selected company_id
  create batch registration with unique idempotency_key/fingerprint
  for each unit:
    assign next receipt_unit_ordinal (never reuse reserved slots)
    allocate next PT sequence number; compose global unique asset_code
    create maintenance_assets (one asset per unit)
    create append-only audit event/record for register action
  commit
on any failure rollback ALL batch/sequence/asset writes
on unique key race: fetch prior and replayOrConflict safely
```

**DB constraints yang disarankan:** unique batch `idempotency_key`; unique `asset_code`; unique `(company_id, sequence_number)`; unique `(source_goods_receipt_item_id, receipt_unit_ordinal)`; unique `company_id` serta `prefix` pada sequence (jika prefix dipakai). Tabel audit harus dirancang append-only. **Jangan andalkan count tanpa GRN row lock**, jangan `MAX(sequence)+1`, jangan menulis Item stock di Action ini. Perbedaan kebutuhan `receipt_unit_ordinal` untuk legacy perlu constraint terpisah (field null dan sumber jelas).

**Konkurensi kasus:** dua POST berbeda sekaligus ingin 6+6 aset dari GRN berkuota 6. Hanya salah satu batch berhasil, atau keduanya sukses dengan jumlah gabungan ≤ 6; tidak boleh terbuat 12 aset. Counter per PT tidak boleh mengeluarkan kode duplikat.

## 6. Legacy manual oleh HR (terpisah dari jalur GRN)

Aset lama yang memang tidak mempunyai catatan Purchasing ERP diajukan melalui jalur *legacy_manual*. Harus ada PT pemilik yang dipilih eksplisit + dokumen/alasan asal-usul + pengguna HR atau pemegang permission resmi. Jika maksud istilah “aset HR” adalah *departemen pemakai* (bukan aktor HR), ini perlu diklarifikasi agar owner aset tetap PT, bukan HR.

**Penting:** GRN yang memiliki PO lama dengan `company_id` null **tidak boleh diam-diam dialihkan** ke jalur legacy untuk melewati verifikasi kepemilikan. Usulan: prosedur review koreksi identitas PO/owner disertai bukti, tetapi tidak mengubah flow atau histori tanpa SOP.

## 7. Role, izin, dan error contract (semua usulan)

| Kasus | HTTP | Expected |
|---|---:|---|
| Tidak login | 401 | JSON konsisten `ApiResponse` |
| Login tanpa permission | 403 | Tidak memuat detail GRN/PT |
| Tidak berhak PT | 403/404 | Dipilih konsisten saat kontrak final, cegah enumeration |
| Payload tidak valid | 422 | Form Request / field errors |
| GRN masih pending / unconfirmed | 409 | Tidak registrasi aset |
| SKU tidak eligible / kuota sudah habis | 409 | Tidak registrasi aset |
| idempotency key sama, payload berbeda | 409 | Tidak registrasi aset baru |
| Replay identik dan berizin | 200 | Registrasi lama dikembalikan |
| Registrasi baru sukses | 201 | N aset baru dan sisa kuota |

## 8. Rencana feature tests (belum dijalankan)

| Test ID | Skenario | Harapan |
|---|---|---|
| T-01 | Confirm GRN tanpa POST Maintenance | **0 asset records** dibuat otomatis |
| T-02 | GRN pending, POST register | 409 dan tidak ada perubahan |
| T-03 | GRN confirmed 6 unit, Order partially_received | PETUGAS berizin berhasil mendaftarkan subset atau seluruh 6 sesuai kebijakan |
| T-04 | GRN confirmed 7 unit, 1 damaged | Maksimal 6 aset |
| T-05 | GET candidates saja | Tidak mengubah DB, stock movement, atau nomor aset |
| T-06 | POST 2 unit, lalu POST 4 unit dengan key berbeda (registrasi parsial sudah dipilih) | Total tepat 6, ordinal unik |
| T-07 | POST key sama payload sama | Replay 200, kode tidak berubah |
| T-08 | POST key sama payload beda | 409 |
| T-09 | Dua POST berbeda race menghabiskan kuota sama | Tidak over-register |
| T-10 | Error unit kedua/batch ke-N | Semua registrasi/kode/counter rollback |
| T-11 | PO.company_id null | Tidak menciptakan aset atau memilih PT otomatis |
| T-12 | SKU ATK yang tidak eligible | Tidak muncul sebagai kandidat / 409 saat POST |
| T-13 | Role salah vs guest | 403 / 401 |
| T-14 | PT lintas izin | Tidak bocor di GET atau replay; POST ditolak |
| T-15 | Aset diterbitkan | `stock_quantity`, `stock_movements` tidak berubah oleh registrasi |
| T-16 | GRN-001 confirmed 6 dan GRN-002 confirmed 4 | 10 aset unik, tanpa menunggu Order completed saat tahap 6 |
| T-17 | Aset legacy oleh HR berizin | Memiliki `company_id` dan audit, tanpa FK GRN |

**Regresi wajib:** PO approval → Order, konfirmasi GRN termasuk rusak/paruh, Inventory StockService, Distribution hanya mengurangi saat Sent, finance/payment, documents. **Baseline sebelumnya:** 346 passed / 2.722 assertions pada CI existing; **bukan** hasil tes rancangan ini.

## 9. Gate yang masih harus disetujui sebelum coding

1. D-01: klasifikasi SKU/jenis aset; **registrasi sebagian telah dipilih sebagai preferensi awal** tetapi masih menunggu sign-off GA.
2. D-10: role existing mana memiliki setiap permission; apakah “aset HR” berarti diinput HR atau dipakai departemen HR.
3. D-11: scope PT, bukti PT pemilik, prosedur PO lama tanpa company_id, format prefix PT.
4. Format kode: misalnya `IND-AST-000001` hanyalah contoh; apakah angka di-reset tahunan masih perlu keputusan.
5. Status/histori aset, kebijakan delete/void, serial number, batas maksimum unit per batch.
6. Prosedur pembagian fisik barang aset setelah registrasi (bila perlu serah-terima) **tidak boleh** mengubah aturan Distribution existing.

**Tidak ada migration, endpoint, role, permission, test, PR, atau perubahan GitHub yang dibuat oleh dokumen ini.**

## 10. Catatan versi

| Versi | Perubahan |
|---|---|
| v0.1 | Kontrak awal registrasi manual dari GRN confirmed, anti-duplikasi dan idempotensi. |
| v0.2 | Registrasi sebagian disepakati sebagai preferensi awal; contoh dua POST (2+4), kuota kumulatif, replay, dan respons `registered_units` dirinci. |
| v0.3 | Opsi C: klasifikasi calon aset berasal dari Master Barang, usulan flag `items.is_asset_trackable` default false; endpoint klasifikasi, permission terpisah, audit dan invalidation kandidat ditambahkan. |
| v0.4 | Pilihan kewenangan klasifikasi: hanya `super_admin` atau `hr` **dan** permission `maintenance.asset-items.classify`, role+permission dicek di backend; no auto-grant. |


## 11. Addendum API v0.3 — Kelola klasifikasi pada Master Barang (opsi C)

**Preferensi sementara**, bukan kebijakan GA final. Rancangan field baru: `items.is_asset_trackable` boolean default false (migration baru); bukan perubahan pada kolom stok, SKU maupun kategori existing. Tidak ada endpoint berikut yang sudah tersedia sekarang.

### PATCH klasifikasi (kandidat)

```http
PATCH /api/v1/maintenance/item-classifications/33
Authorization: Bearer <token>
Content-Type: application/json
```

```json
{
  "is_asset_trackable": true,
  "reason": "SKU laptop termasuk aset fisik yang harus diregistrasi per unit"
}
```

**Calon respons 200** menggunakan `ApiResponse`:

```json
{
  "success": true,
  "message": "Klasifikasi aset barang diperbarui.",
  "data": {
    "item_id": 33,
    "sku": "LAPTOP-EXAMPLE",
    "is_asset_trackable": true
  }
}
```

- Endpoint ini berada di group `auth:sanctum`, `EnsureActiveAccount`, dan `can:maintenance.asset-items.classify` + **policy allowlist role `super_admin`/`hr`** + pemeriksaan scope sesuai keputusan D-10/D-11.
- Field `is_asset_trackable` **prohibited** pada `POST /api/v1/items` dan `PATCH /api/v1/items/{item}` yang existing, sehingga izin `items.create`/`items.manage` tidak otomatis bisa mengubah klasifikasi.
- Bila flag berubah, simpan actor, alasan, waktu, nilai sebelum dan sesudah pada audit yang disetujui. Perubahan flag **tidak** memodifikasi aset yang sudah didaftarkan atau penggunaan kuota baris GRN; tidak menyentuh Inventory stock.
- GET `/api/v1/maintenance/asset-candidates` menampilkan hanya Item dengan `is_asset_trackable = true`, GRN confirmed dan kuota tersisa. Batasi akses PT. Flag true tidak memicu proses apa pun secara otomatis.
- POST `from-grn` memeriksa ulang flag ketika memperoleh lock Item, disertai GRN confirmed, PT owner dan kuota; jika sudah false, kembalikan konflik 409 tanpa perubahan.
- **Concurrency:** kunci Item ketika klasifikasi berubah dan ketika registrasi diperiksa, dan pakai urutan lock yang disepakati dengan GRN (usulan PO → Order → GRN → GRN Item → Item → sequence) untuk menghindari race/deadlock.

### Tambahan skenario tes

| ID | Skenario | Ekspektasi |
|---|---|---|
| TC-18 | Item lama tidak punya flag sebelum migration | Sesudah migration, default `false` |
| TC-19 | staff_hr boleh `items.create` tetapi tidak memiliki izin klasifikasi | PATCH klasifikasi 403 |
| TC-20 | Tidak login mengubah klasifikasi | 401 |
| TC-21 | SKU ATK flag false memiliki GRN confirmed | Tidak muncul sebagai kandidat, POST 409 |
| TC-22 | SKU laptop flag true GRN confirmed 6 baik | Registrasi manual parsial 2 lalu 4 tetap bisa |
| TC-23 | Re-klasifikasi true→false setelah 2 aset terdaftar | 2 aset tetap, sisa 4 tidak eligible sampai kebijakan berubah |
| TC-24 | Gagal menulis audit perubahan flag | Transaction rollback, flag tetap |
| TC-25 | Race toggle off vs POST registrasi | Hasil serialisable secara logis; tidak ada aset baru setelah toggle off commit lebih dahulu |

**Tersisa untuk persetujuan GA:** pengesahan preferensi role `super_admin`/`hr` + permission klasifikasi, kebijakan PT, unit eligible, format prefix PT, serta tindakan pada SKU historis yang perlu diklasifikasi. Tidak membuat perubahan pada API atau DB saat menyusun dokumen.


## 12. Addendum v0.4 — Pembatasan role + permission untuk endpoint klasifikasi

**Keputusan desain sementara:** klasifikasi hanya boleh oleh **`super_admin` atau `hr`** dengan permission **`maintenance.asset-items.classify`**. **Belum sign-off GA**. Semua role lain tetap 403, termasuk bila assignment permission tersebut keliru.

**Contoh endpoint yang diusulkan, bukan route yang sudah berjalan:**

```http
PATCH /api/v1/maintenance/item-classifications/{item}
Authorization: Bearer <token>
Content-Type: application/json
```

```json
{"is_asset_trackable":true,"reason":"Laptop dibeli sebagai aset yang dicatat per unit"}
```

**Urutan pemeriksaan pada backend:**

1. `auth:sanctum` → guest 401; `EnsureActiveAccount` → user nonaktif 403.
2. `can:maintenance.asset-items.classify` → permission tidak ada 403.
3. **Policy** → role bukan `super_admin`/`hr` 403, meskipun permission telah ditambahkan.
4. Form Request → `is_asset_trackable` boolean, `reason` wajib dan dibatasi panjangnya; payload invalid 422.
5. Transaction + Item lock → validasi kondisi perubahan dan audit append-only; respon menggunakan `ApiResponse`.
6. **Tidak** mengubah `items.stock_quantity`, `stock_movements`, GRN, Order, atau daftar aset yang sudah didaftarkan.

**Invarian keamanan:** pengguna `staff_hr` yang boleh membuat Item tetap tidak bisa menyisipkan `is_asset_trackable` pada `POST /api/v1/items` atau `PATCH /api/v1/items/{item}`. Field tetap `prohibited` di kedua Form Request tersebut. Gunakan dedicated action/policy; jangan menambahkan field klasifikasi ke mass assignment umum `SaveItem::EDITABLE`.

| Test | Peran dan permission | Expected |
|---|---|---|
| CL-01 | `super_admin` + classify | 200 |
| CL-02 | `hr` + classify | 200 |
| CL-03 | `super_admin` tanpa classify | 403 |
| CL-04 | `hr` tanpa classify | 403 |
| CL-05 | `staff_hr` + classify | 403 |
| CL-06 | `general_manager` + classify | 403 |
| CL-07 | Guest | 401 |
| CL-08 | Role nonaktif / user nonaktif | 403 menurut guard existing |
| CL-09 | Pembuatan Item existing mencoba set flag | 422 |
| CL-10 | Perubahan klasifikasi gagal saat audit | Rollback penuh |

Pendaftaran aset sendiri tetap menggunakan permission terpisah `maintenance.assets.register-from-receipt` dan pemeriksaan akses PT; izin `classify` **tidak** otomatis memberikan izin registrasi.

**Belum final:** perlakuan terhadap calon aset dari penerimaan historis ketika flag berubah dari false→true; pengesahan D-10; kebijakan PT D-11; hasil validasi 23 kebutuhan oleh GA.