# MGA-R1-002 — Matriks RBAC Maintenance R1 (v0.1, DRAFT)

**Tanggal:** 24 November 2026 | **Status:** DRAFT UNTUK SIGN-OFF GA, bukan izin yang sudah aktif.
**Sumber:** Master TODO R1-002, RPD GA v1.0, keputusan rancangan sementara pengguna tentang role existing, kepemilikan PT, registrasi manual dan klasifikasi Item.

## 1. Pembedaan keputusan

| Materi | Preferensi desain pengguna | Apakah disahkan GA? |
|---|---|---|
| Role existing | Tetap `super_admin`, `hr`, `staff_hr`, `general_manager`, permission granular | Belum |
| Klasifikasi `is_asset_trackable` | Hanya `super_admin` atau `hr` **dengan** permission khusus | Belum |
| Registrasi dari GRN | Petugas berizin, manual, setelah GRN confirmed, boleh parsial | Belum |
| Aset lama | Diinput manual oleh HR berizin | Belum |
| PT pemilik | PT pembeli; pemakaian lintas PT tidak mengubah ownership | Belum |
| Akses lintas PT | Dibatasi permission tersendiri, bukan semua role otomatis | Belum |

D-10 **belum selesai**, karena role untuk registrasi, koreksi meter, audit, dokumen dan approval belum diputuskan formal. D-01 dan D-11 juga masih perlu sign-off.

## 2. Rule wajib untuk klasifikasi Item

**Akses = (role `super_admin` ATAU `hr`) DAN permission `maintenance.asset-items.classify`.**

- Gunakan Sanctum, `EnsureActiveAccount`, middleware permission dan policy. Middleware permission **saja tidak cukup**, karena akan membiarkan `staff_hr` yang salah diberi permission khusus melewati pemeriksaan.
- Tidak memberikan role baru dan tidak otomatis menambahkan permission pada role existing; `ModulePermissionRegistrar::register()` hanya membuat permission guard `web`, tidak memberi grant.
- `super_admin` **tetap 403 tanpa permission** sesuai keputusan ini.
- `staff_hr` boleh membuat Item sesuai kebijakan ERP existing, tetapi tidak boleh mengubah flag eligibility melalui endpoint Item umum maupun endpoint klasifikasi.
- `hr` tanpa permission khusus meskipun memiliki `items.manage` tetap 403.
- Untuk pengguna dengan beberapa role: cukup salah satu role di allowlist dan permission sah.

### Matriks klasifikasi

| Role existing | classify permission | Boleh klasifikasi? | HTTP perkiraan |
|---|---|---|---|
| `super_admin` | Ya | Ya | 200 jika valid |
| `super_admin` | Tidak | Tidak | 403 |
| `hr` | Ya | Ya | 200 jika valid |
| `hr` | Tidak | Tidak | 403 |
| `staff_hr` | Ya / tidak | Tidak | 403 |
| `general_manager` | Ya / tidak | Tidak | 403 |
| Tidak login | — | Tidak | 401 |
| User nonaktif | Ya | Tidak | 403 |

## 3. Matriks endpoint → permission → penanggung jawab (usulan)

| Aksi API usulan | Kandidat permission (guard `web`) | Role/kewenangan sementara | Ownership dan 403 |
|---|---|---|---|
| PATCH `maintenance/item-classifications/{item}` | `maintenance.asset-items.classify` | **Hanya `super_admin`/`hr` + permission** (preferensi dipilih) | Role AND permission; audit perubahan wajib |
| GET `maintenance/asset-candidates` | `maintenance.assets.candidates.view` | Role mana saja **jika** diberi izin resmi | Hanya data PT berizin; `view-all-companies` terpisah |
| POST `maintenance/asset-registrations/from-grn` | `maintenance.assets.register-from-receipt` | **PENDING** actor; jangan asumsi semua role | Scope PT + GRN confirmed + Item eligible + kuota |
| GET `maintenance/assets` | `maintenance.assets.view` | Role mana saja jika diberi izin | Scope PT berdasarkan policy |
| GET `maintenance/asset-registrations/{registration}` | `maintenance.assets.view` | Role mana saja jika diberi izin | Scope PT, batasi replay/result ID enumeration |
| POST `maintenance/asset-registrations/legacy` | `maintenance.assets.register-legacy` | **HR berizin** (preferensi), super_admin perlu keputusan | Bukti PT, alasan sumber historis, audit |
| PATCH `maintenance/assets/{asset}` | `maintenance.assets.manage` | PENDING | PT dan status aset dibatasi |
| POST `maintenance/meters` | `maintenance.meters.record` | PENDING | Aset kendaraan berizin, integritas meter |
| POST `maintenance/meters/{log}/correct` | `maintenance.meters.correct` | PENDING | Alasan & audit; tidak dari role view |
| GET dokumen aset | `maintenance.documents.view` | PENDING | File private, PT & record scope |
| PATCH dokumen aset | `maintenance.documents.manage` | PENDING | File private, validasi MIME/size |
| Admin lintas-PT lihat | `maintenance.assets.view-all-companies` | PENDING | **Tambahan**, bukan implicit dari view |
| Admin lintas-PT ubah | `maintenance.assets.manage-all-companies` | PENDING | **Tambahan**, bukan implicit dari manage |

**Penting:** contoh route di atas belum dibuat. Permission granular harus dibahas satu per satu; tidak berarti semua role menerima semua izin. Read-only untuk semua PT juga tidak otomatis memberi izin mutate seluruh PT.

## 4. Pemisahan tanggung jawab Master Barang dan Maintenance

- `items.create` dan `items.manage` adalah permission existing. Jangan menambah `is_asset_trackable` ke `SaveItem::EDITABLE` atau mass assignment biasa.
- Klasifikasi dilakukan melalui endpoint tersendiri dan dedicated Action; request harus memuat boolean dan alasan; audit actor/before/after/waktu.
- Proses GET kandidat membaca klasifikasi sebagai salah satu filter, bukan untuk memutuskan aktor boleh mengubahnya.
- Proses registrasi menggunakan permission yang berbeda dari klasifikasi; orang yang memberi label SKU eligible belum tentu boleh menerbitkan nomor aset.
- Perubahan flag false→true terhadap GRN historis perlu policy revisi/approval; jangan otomatis membuka seluruh histori.

## 5. Implementasi izin sesudah sign-off (BELUM DIJALANKAN)

1. Ratifikasi D-10 untuk semua aksi. Tentukan role yang diberi permission, termasuk akses lintas PT dan asset legacy.
2. Pilih permission final dan validasi naming; panggil `ModulePermissionRegistrar::register([...])` melalui migrasi data/command terkontrol jika disahkan. **Tidak** menjalankan `syncPermissions` untuk role existing sembarangan.
3. Lakukan grant dengan proses administrator yang diaudit; jangan mengandalkan `RolePermissionSeeder` existing untuk memperbarui role produksi karena seeder dapat melewati inisialisasi existing.
4. Pasang route `auth:sanctum`, `EnsureActiveAccount` dan `can:...`; tambah policy **role+permission** dan query-scope PT di server.
5. Feature tests untuk 401/403/422, cross-PT, booleans, audit, concurrency dan regression, pada database testing MySQL terisolasi.

## 6. Rencana kasus uji akses

| ID | Skenario | Expected |
|---|---|---|
| RB-01 | `super_admin` + classify | 200, audit terjadi |
| RB-02 | `hr` + classify | 200, audit terjadi |
| RB-03 | `super_admin` tidak punya classify | 403 |
| RB-04 | `hr` tidak punya classify | 403 |
| RB-05 | `staff_hr` diberikan classify | Tetap 403 |
| RB-06 | `general_manager` diberikan classify | Tetap 403 |
| RB-07 | User `hr` + `items.manage` tanpa classify | 403 |
| RB-08 | User `staff_hr` + `items.create` mencoba flag pada POST Item | 422 |
| RB-09 | User `hr` melakukan PATCH Item biasa dengan flag | 422 |
| RB-10 | User tidak login / inactive | 401 / 403 |
| RB-11 | Klasifikasi berubah dari true ke false setelah ada aset | Aset lama tetap, calon registrasi baru tertahan |
| RB-12 | Dua request classify dan register bersamaan | Tidak ada POST registrasi melewati perubahan flag yang commit lebih dulu |
| RB-13 | User boleh view PT sendiri, bukan PT lain | List/detail/download scoped; larangan juga berlaku untuk POST |
| RB-14 | Replay registrasi oleh user tanpa akses sumber PT | 403/404, tidak mengungkap payload atau kode aset |

## 7. Gate sebelum implementasi

- D-10 sign-off GA untuk klasifikasi **dan** semua aksi Maintenance; D-01/D-11 untuk desain aset/PT.
- Formulir permission mapping role → action → PT scope ditandatangani pemilik proses.
- R1-002 **belum DONE**: dokumen ini hanya draft, tidak ada grant role atau permission baru.