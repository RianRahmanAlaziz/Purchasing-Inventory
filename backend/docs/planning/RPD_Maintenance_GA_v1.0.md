# RPD — Rencana Pengembangan Digital Modul Maintenance & General Affairs

**Versi:** 1.0 (Draft kebutuhan)  
**Tanggal:** 23 November 2026  
**Konteks:** ERP Purchasing & Inventory — backend Laravel 13 + MySQL  
**Dasar:** Screenshot tabel jobdesk GA yang diberikan pengguna pada 23 November 2026  
**Status:** Draft untuk review operasional; belum merupakan spesifikasi yang disetujui untuk implementasi production.

## 1. Ringkasan eksekutif

RPD ini merumuskan digitalisasi aktivitas General Affairs (GA) dalam empat ranah: **Kendaraan Operasional & Alat Berat, Asset Management, Building & Facility, dan Maintenance ATK**. Tabel sumber memuat **8 kelompok jobdesk** yang diturunkan menjadi **23 kebutuhan terlacak**. Sistem saat ini menggunakan kombinasi Google Sheet, Smart Salary, dashboard KM RIS, dan alat yang ditulis “Whatsup” pada gambar. Tujuan pengembangan ialah memusatkan pencatatan, penjadwalan, pengingat, persetujuan, dan audit tanpa menimpa sistem yang sudah berjalan.

**Prinsip utama:** Purchasing & Inventory tetap memiliki aturan bisnisnya sendiri. ERP Maintenance/GA menjadi domain baru yang menggunakan autentikasi, otorisasi dan layanan stok existing, bukan menyalin atau menulis saldo stok sendiri. Proses GA yang belum jelas dalam sumber tidak ditetapkan sebagai fakta; rekomendasi teknis dipisahkan dari kebutuhan yang teramati.

## 2. Konteks dan sumber kebutuhan

Tabel sumber memiliki kolom **No, Kategori, Aktivitas / Jobdesk, Detail, Frekuensi, PIC, Tools / System**. Seluruh kategori yang tampak menunjuk **GA** sebagai PIC. Delapan kelompok aktivitas tersebut adalah: (1) Monitoring KM & Operational Control; (2) Penjadwalan service kendaraan & alat berat; (3) Surat-surat kendaraan; (4) Log request maintenance kendaraan; (5) Peminjaman kendaraan; (6) Asset Management; (7) Building & Facility; (8) Maintenance ATK.

**Batasan pembacaan:** Teks yang tidak terisi di gambar dinyatakan “Tidak tertulis”, bukan diisi menggunakan asumsi. Frekuensi ATK yang kontradiktif dipertahankan apa adanya dan masuk daftar keputusan. Teks “Whatsup” dipertahankan dari sumber; penyedia aplikasi yang dimaksud perlu dikonfirmasi.

## 3. Sasaran bisnis dan manfaat

- Mengurangi pencatatan ganda di Google Sheet dan kehilangan histori aktivitas.
- Memastikan KM kendaraan serta kondisi dokumen dipantau dengan pengingat yang dapat dilacak.
- Menyediakan kalender servis, pemeriksaan gedung, pemeliharaan aset, dan jatuh tempo sewa.
- Menghubungkan hasil pembelian aset dari Purchasing dengan registrasi aset GA berdasarkan PT yang bersangkutan.
- Menangani request perbaikan secara tercatat, dari penerimaan hingga penyelesaian dan audit.
- Menggunakan layanan Inventory yang sudah ada untuk transaksi ATK dan spare part, tanpa stok ganda.
- Mendukung pelaporan kinerja GA secara periodik.

## 4. Cakupan dan batasan

**Masuk lingkup:** master aset, data kendaraan/alat berat, log KM, jadwal servis, legalitas kendaraan, request maintenance, Work Order, peminjaman kendaraan, daftar aset per PT, mutasi dan penjualan aset berdasarkan SOP, inspeksi gedung, kontrak sewa, data ATK dan pengajuan pengadaan, dashboard serta notifikasi.

**Di luar MVP awal:** integrasi otomatis dengan Smart Salary atau KM RIS tanpa kepastian API; integrasi pesan “Whatsup” tanpa identitas platform; otomatisasi penjualan aset sebelum SOP tersedia; akuntansi aset dan penyusutan penuh; integrasi GPS/telematika kendaraan; multi-warehouse atau multi-tenant baru tanpa kebutuhan yang disetujui.

## 5. Inventaris kebutuhan dari gambar (baseline sumber)

PIC yang terbaca untuk seluruh baris adalah **GA**. Tabel berikut mempertahankan frekuensi dan sistem asal; **ID RPD adalah usulan penelusuran teknis**.

### 5.1 Kendaraan Operasional & Alat Berat

| ID | Aktivitas / detail dari gambar | Frekuensi sumber | Tools / System |
|---|---|---|---|
| `VEH-01` | Cek KM kendaraan operasional (Indorack, SMT, JRP). | Daily (kelompok No. 1) | Google Sheet |
| `VEH-02` | Pastikan operational control terbarui; ingatkan admin jika belum mencatat. | Daily | Google Sheet |
| `VEH-03` | Penjadwalan servis rutin kendaraan operasional. | Tidak tertulis | Whatsup (teks sumber) |
| `VEH-04` | Servis rutin berdasarkan kilometer kendaraan; batas rujukan maksimal 10.000 KM. | Berdasarkan KM; bukan jadwal waktu | Google Sheet |
| `VEH-05` | Gunakan tampilan KM mobil pada dashboard KM RIS untuk menyusun jadwal servis. | Tidak tertulis | Google Sheet / dashboard KM RIS |
| `VEH-06` | Jadwal servis alat berat berdasarkan data maintenance kendaraan dan alat berat. | 2 Bulan | Google Sheet |
| `VEH-07` | Memastikan surat KIR masih aktif. | 6 Bulan | Google Sheet |
| `VEH-08` | Memastikan STNK masih aktif. | 1 Tahun | Google Sheet |
| `VEH-09` | Memastikan kendaraan terlindungi asuransi. | 1 Tahun | Google Sheet |
| `VEH-10` | Rekap log permintaan maintenance kendaraan dari aplikasi Smart Salary. | Daily | Smart Salary & Google Sheet |
| `VEH-11` | Peminjaman kendaraan operasional; detail prosedur belum tertulis pada gambar. | Tidak tertulis | Google Sheet |

### 5.2 Asset Management

| ID | Aktivitas / detail dari gambar | Frekuensi sumber | Tools / System |
|---|---|---|---|
| `AST-01` | Update database aset setelah pembelian oleh Purchasing; GA mencatat sesuai PT/perusahaan. | 1 Hari | Google Sheet |
| `AST-02` | Penomoran aset ketika ada pembelian atau pemeriksaan berkala. | 3 Bulan | Google Sheet |
| `AST-03` | Pencatatan log request perbaikan aset dari Smart Salary. | 1 Minggu | Google Sheet / Smart Salary (disebut pada detail) |
| `AST-04` | Administrasi mutasi aset sesuai SOP GA. | 1 Bulan | Google Sheet |
| `AST-05` | Penjualan aset yang tidak terpakai, rusak, atau tak dapat digunakan sesuai SOP GA. | 1 Bulan | Google Sheet |
| `AST-06` | Pemeliharaan aset berkala dan pemantauan aset serta fasilitas perusahaan. | 3 Bulan | Google Sheet |

### 5.3 Building & Facility

| ID | Aktivitas / detail dari gambar | Frekuensi sumber | Tools / System |
|---|---|---|---|
| `FAC-01` | Monitoring kebersihan seluruh area gedung dan jobdesk office boy. | 1 Minggu | Google Sheet |
| `FAC-02` | Pemeriksaan/maintenance kondisi gedung berkala. | 3 Bulan | Google Sheet |
| `FAC-03` | Memeriksa tanggal jatuh tempo pembayaran sewa gedung. | 1 Bulan | Google Sheet |

### 5.4 Maintenance ATK

| ID | Aktivitas / detail dari gambar | Frekuensi sumber | Tools / System |
|---|---|---|---|
| `ATK-01` | Mencatat barang masuk dan keluar secara rapi serta detail. | Daily | Google Sheet |
| `ATK-02` | Stock opname ATK; detail menyebut setiap 1 bulan, kolom Frekuensi tertulis Daily. | Daily vs 1 Bulan (konflik sumber) | Google Sheet |
| `ATK-03` | Pengajuan pembelian ATK jika stok menipis; detail menyebut 1 bulan sekali, frekuensi tertulis Daily. | Daily vs 1 Bulan (konflik sumber) | Google Sheet |

## 6. Kebutuhan fungsional sistem (usulan implementasi)

| Kode | Kapabilitas ERP yang diusulkan | Bukti kebutuhan | Prioritas |
|---|---|---|---|
| FR-01 | Register aset per PT: kode, kategori, lokasi, penanggung jawab, status, histori pembelian. | AST-01, AST-02 | P1 |
| FR-02 | Daftar kendaraan/alat berat dengan profil, nomor polisi, KM terakhir dan histori pembacaan. | VEH-01, VEH-05 | P1 |
| FR-03 | Form input KM dan validasi nilai kronologis serta pengingat pencatatan harian yang terlewat. | VEH-01, VEH-02 | P1 |
| FR-04 | Jadwal servis berdasar KM dan kalender; rujukan maksimum 10.000 KM perlu rumus pasti. | VEH-03 s.d. VEH-06 | P1 |
| FR-05 | Arsip KIR, STNK, asuransi dengan tanggal berakhir, pemilik dokumen, dan notifikasi. | VEH-07 s.d. VEH-09 | P1 |
| FR-06 | Pencatatan/import request kendaraan atau aset dari Smart Salary dan tracking statusnya. | VEH-10, AST-03 | P1 |
| FR-07 | Work Order perawatan dan histori aktivitas, petugas, jadwal, bukti pekerjaan. | VEH-03, AST-06, FAC-02 | P1 |
| FR-08 | Alur peminjaman kendaraan (ajukan, setujui, serahkan, kembalikan, histori). | VEH-11 | P2, SOP dibutuhkan |
| FR-09 | Register mutasi aset dan penjualan/disposal dengan bukti & persetujuan. | AST-04, AST-05 | P2, SOP dibutuhkan |
| FR-10 | Jadwal inspeksi kebersihan gedung dan daftar pemeriksaan office boy. | FAC-01 | P2 |
| FR-11 | Jadwal inspeksi kondisi gedung, temuan dan tindak lanjut perbaikan. | FAC-02 | P2 |
| FR-12 | Register sewa gedung, tanggal jatuh tempo, nilai dan pengingat. | FAC-03 | P2 |
| FR-13 | Tampilan ATK masuk/keluar terhubung transaksi Inventory existing. | ATK-01 | P1 |
| FR-14 | Form stock opname ATK dan koreksi stok hanya melalui Stock Service/Adjustment beralasan. | ATK-02 | P1, frekuensi perlu validasi |
| FR-15 | Usulan pengadaan ATK dari stok rendah menggunakan flow Purchasing existing. | ATK-03 | P1, frekuensi perlu validasi |
| FR-16 | Dashboard GA: keterlambatan log KM, due servis, expiry dokumen, request, aset, fasilitas dan ATK. | Seluruh kelompok | P2 |
| FR-17 | Ekspor laporan dan audit siapa melakukan apa, kapan, nilai sebelum/sesudah. | Seluruh kelompok | P1 |

Prioritas **P1** berarti MVP awal yang direkomendasikan, sedangkan **P2** merupakan gelombang pengembangan berikutnya; keduanya merupakan penilaian teknis, bukan prioritas yang tertulis pada gambar.

## 7. Aktor dan hak akses

**Yang dikonfirmasi dari sumber:** GA adalah PIC kegiatan. Gambar **tidak** menetapkan daftar role aplikasi, matriks persetujuan, ataupun siapa yang bertindak sebagai teknisi, peminjam, atau penyetuju.

**Usulan pembagian tanggung jawab:**

| Aktor bisnis | Contoh kewenangan yang perlu disahkan |
|---|---|
| GA Operator | Input KM, master aset, jadwal pemeriksaan, data dokumen, rekap request, ATK. |
| GA Supervisor | Review perubahan penting, penugasan dan approval sesuai SOP. |
| Teknisi / petugas | Menangani Work Order, melampirkan bukti, menyatakan pekerjaan selesai. |
| Pemohon / karyawan | Mengajukan kebutuhan perbaikan atau peminjaman dan memantau miliknya. |
| Purchasing existing | Proses PO terkait pengadaan aset dan ATK; alur PO tidak berubah. |
| Warehouse existing | Penerimaan, pengeluaran, adjustment serta audit mutasi menurut aturan stok existing. |
| Admin existing | Administrasi akses sesuai permission RBAC yang benar-benar diberikan. |

**Catatan arsitektur:** Nama role baru (`ga_operator`, `ga_supervisor`, `maintenance_technician`) hanyalah kandidat. Jangan otomatis menambahkannya ke Spatie atau mengganti role existing (`super_admin`, `hr`, `staff_hr`, `general_manager`) sebelum keputusan organisasi disetujui. Backend harus mengembalikan 403 bila permission tidak memadai, dan membatasi pemohon pada data miliknya bila disyaratkan.

## 8. Proses bisnis target (usulan)

### 8.1 Monitoring KM dan servis kendaraan

1. GA mencatat pembacaan KM per kendaraan dan tanggal pencatatan.
2. Sistem memeriksa konsistensi pembacaan terhadap histori; koreksi nilai harus diberi alasan dan diaudit.
3. Sistem menandai kendaraan yang belum dicatat sesuai kewajiban harian yang berlaku.
4. Jadwal servis dihitung dari KM terakhir servis dan interval yang disahkan (gambar menyebut maksimal 10.000 KM), serta jadwal kalender jika diberlakukan.
5. GA membuat/menerima Work Order untuk servis; penyelesaian merekam KM saat servis, tanggal, tindakan, biaya bila diaktifkan, dan lampiran.
6. Untuk alat berat, aturan inspeksi/servis dua bulanan dari gambar dipetakan ke maintenance plan tersendiri.

**Belum final:** apakah 10.000 KM adalah interval antarlayanan, batas KM kendaraan, atau ambang tertentu; siapa menyetujui servis; bagaimana data KM RIS diambil.

### 8.2 Dokumen KIR, STNK dan asuransi

1. GA memasukkan dokumen, nomor, pemilik kendaraan, tanggal terbit dan tanggal jatuh tempo.
2. Dokumen tersimpan private dan dapat diunduh oleh pengguna berizin.
3. Sistem menampilkan daftar akan jatuh tempo/terlambat serta log pengingat.
4. Frekuensi pemeriksaan sumber: KIR 6 bulan, STNK 1 tahun, asuransi 1 tahun. Masa berlaku legal sebenarnya mengikuti tanggal pada dokumen, bukan disamakan otomatis dengan frekuensi pemeriksaan.

### 8.3 Request perbaikan dan Work Order

**Status usulan Request:** `requested → reviewed → approved → converted_to_work_order`, dengan alternatif `rejected/cancelled` setelah disahkan.

**Status usulan Work Order:** `draft → assigned → in_progress → completed → closed`, dengan alternatif pembatalan sesuai SOP.

Request dari Smart Salary tidak langsung dianggap telah terintegrasi. Penerimaan data dapat dimulai dengan input manual atau impor CSV, disertai nomor referensi eksternal dan deduplikasi.

### 8.4 Pembelian, mutasi dan penjualan aset

1. Purchasing menyelesaikan proses pembelian menurut flow PO/GRN existing.
2. GA menerima informasi pembelian dan mendaftarkan aset berdasarkan PT/perusahaan, dengan referensi dokumen purchasing bila tersedia.
3. Penomoran aset dan pengujian duplikasi dilakukan saat registrasi/pemeriksaan berkala.
4. Mutasi mencatat aset, perusahaan/lokasi asal, perusahaan/lokasi tujuan, pemohon, penyetuju, waktu dan berita acara.
5. Penjualan/disposal hanya dapat diproses setelah SOP GA, pejabat persetujuan, serta implikasi akuntansi ditetapkan.

### 8.5 Gedung, kebersihan dan sewa

1. GA membuat daftar inspeksi kebersihan area serta penugasan office boy, dengan jadwal mingguan.
2. Inspeksi gedung berkala menggunakan checklist dan tindak lanjut yang dapat menjadi Work Order.
3. Perjanjian sewa disimpan beserta tanggal jatuh tempo; pengecekan bulanan dan pengingat tersimpan sebagai histori.
4. Pembayaran sewa dan jurnal akuntansi **tidak** otomatis menjadi proses Finance existing sampai dipetakan melalui keputusan terpisah.

### 8.6 ATK dan pengadaan

1. SKU ATK ditempatkan pada model `Item` Inventory existing; tidak membuat saldo ATK terpisah.
2. Penerimaan barang mengikuti GRN dan konfirmasi yang sah. Pengeluaran kepada karyawan mengikuti alur Distribution existing bila sesuai konteks.
3. Stock opname yang menyesuaikan saldo menggunakan Stock Adjustment existing dengan alasan, optimistic balance check dan audit.
4. Ketika stok mencapai/melewati batas minimum sesuai ketentuan Inventory, sistem menghasilkan indikasi kebutuhan pengadaan; proses PO tetap menggunakan alur Purchasing.
5. Jadwal stock opname dan pengadaan masih menunggu klarifikasi konflik “Daily” vs “1 bulan sekali” dari gambar.

## 9. Rancangan entitas data (usulan, bukan tabel existing)

| Tabel kandidat | Field minimum / relasi penting | Catatan |
|---|---|---|
| `maintenance_assets` | asset_code unique, company_id?, type, name, status, location, acquired_at?, purchasing_ref? | Aset fisik berbeda dengan SKU Item; satu PT perlu konfirmasi skema company. |
| `maintenance_vehicle_profiles` | asset_id unique, registration_number?, odometer_unit, last_service_km?, asset_identifier? | Jenis alat berat mungkin butuh meter-jam, jangan paksa KM. |
| `maintenance_odometer_logs` | asset_id, reading, recorded_at, recorded_by, source, correction_reason? | Hindari pembacaan turun diam-diam; histori audit. |
| `maintenance_plans` | asset_id, schedule_type, interval_days?, interval_km?, last_completed_at?, last_completed_km? | Aturan jadwal fleksibel. |
| `maintenance_documents` | asset_id, doc_type, document_number?, expires_at, file_path, uploaded_by | KIR/STNK/asuransi; file private. |
| `maintenance_requests` | request_no unique, asset_id?, requester_id, source_system?, external_ref?, description, priority, status | Bisa dari input lokal atau impor Smart Salary. |
| `maintenance_work_orders` | work_order_no unique, request_id?, asset_id?, assigned_employee_id?, status, planned_at?, completed_at? | Penugasan teknisi dan histori. |
| `maintenance_work_order_parts` | work_order_id, item_id, qty_requested, qty_issued | Kebutuhan spare part; belum mengubah stok. |
| `maintenance_part_issues` | work_order_part_id, qty, actor_id, issued_at, idempotency_key unique | Pengeluaran fisik melalui Stock Service. |
| `maintenance_asset_movements` | asset_id, from_company/location, to_company/location, requested_by, approved_by?, moved_at | Mutasi aset bukan mutasi stok barang. |
| `maintenance_vehicle_loans` | asset_id, borrower_id, start_at, end_at?, km_out?, km_in?, status | Alur approval/serah-terima perlu SOP. |
| `maintenance_facility_checks` | asset_id?, location, check_type, checklist, status, checked_by, checked_at | Kebersihan dan pemeriksaan gedung. |
| `maintenance_leases` | location/asset_id?, provider, agreement_no?, due_date, amount?, file_path? | Tentukan apakah satu kontrak memiliki banyak termin. |
| `maintenance_events` | subject_type, subject_id, actor_id, action, from_status?, to_status?, created_at | Audit; kontrak event lintas entitas perlu ketentuan. |

**Relasi inti:** Maintenance Asset mempunyai banyak Maintenance Plan, Log KM (jika kendaraan), Dokumen, Request, dan Work Order. Work Order mempunyai banyak Part; setiap Part dapat mempunyai banyak Issue. `maintenance_part_issues` mengarah pada satu mutasi `stock_movements` yang dapat ditelusuri.

**Batasan:** tidak semua tabel di atas perlu dibuat pada MVP pertama. Nama tabel/kolom/endpoint adalah usulan teknis dan harus diturunkan menjadi ERD serta migration terverifikasi sebelum coding.

## 10. Integrasi ke backend ERP existing

| Sistem/domain | Rekomendasi integrasi | Syarat penting |
|---|---|---|
| Authentication + RBAC | Gunakan User, Sanctum dan Spatie existing. | Tidak mengubah guard `web` atau aturan role tanpa persetujuan. |
| `erp.applications` | Daftarkan Maintenance hanya setelah permission dan endpoint tersedia. | Visibility katalog bukan pengganti 403 backend. |
| Purchasing / PO / GRN | Referensi pembelian aset dan kebutuhan ATK; bukan approval PO baru. | Workflow lama tetap berlaku. |
| Inventory / `StockService` | Satu-satunya pintu saldo dan stock movement. | Transaksi, lock, idempotensi, audit dan saldo tidak negatif. |
| Google Sheet | Import baseline CSV/Excel dan verifikasi terhadap catatan asli. | Tidak menganggap data selalu bersih atau real-time. |
| Smart Salary | Awali referensi manual/impor log request. | Integrasi API hanya bila akses dan spesifikasi tersedia. |
| KM RIS | Referensi untuk log KM dan jadwal kendaraan. | Tentukan apakah read-only, impor, atau sinkronisasi API. |
| “Whatsup” | Kanal koordinasi/penjadwalan berdasarkan tulisan sumber. | Identitas layanan, izin dan metode integrasi perlu konfirmasi. |

### 10.1 Aturan integritas mutasi stok

- Jangan menulis `items.stock_quantity` dari Maintenance Controller atau Work Order Service.
- Definisikan aksi pengeluaran spare part yang memanggil `StockService` Inventory; pengeluaran hanya pada peristiwa bisnis yang disepakati (usulan: fisik keluar gudang).
- Tambahkan FK unik `stock_movements.maintenance_part_issue_id` melalui migration baru, hanya saat modul siap diimplementasikan.
- Pastikan transaksi penerbitan Part Issue dan mutasi stok berada dalam satu `DB::transaction`, dengan locking konsisten dan idempotency key.
- Penjualan/mutasi aset bukan pengeluaran SKU: jangan catat sebagai `distribution_out` tanpa kecocokan bisnis.

## 11. Nonfungsional, keamanan, dan mutu

- **Auditability:** semua pengajuan, perubahan jadwal, status, KM, mutasi aset, pengeluaran spare part, dan verifikasi dicatat dengan aktor serta timestamp.
- **Authorization:** API mengembalikan 401 ketika belum login, 403 ketika role/permission salah, 422 untuk validasi, dan 409 untuk konflik status bila sesuai kontrak existing.
- **File protection:** KIR, STNK, polis asuransi, kontrak sewa, dan berita acara disimpan private, divalidasi tipe/ukuran, diunduh hanya melalui endpoint berizin.
- **Data integrity:** FK, unique constraints, status guards, `DB::transaction`, dan row-level locks sesuai kebutuhan.
- **Observability:** error log, audit peristiwa, dan histori notifikasi tanpa menyimpan kredensial pihak ketiga.
- **Migration safety:** jangan mengubah migration lama yang sudah berjalan; tambahkan migration baru dan backup sebelum deployment.
- **Testing:** feature test positif/negatif, forbidden role, replay, rollback, pemakaian berlebih, concurrency, dan regression penuh.

## 12. Dashboard dan KPI (rekomendasi)

| KPI | Definisi konseptual |
|---|---|
| Kepatuhan input KM | Persentase kendaraan yang mendapat log KM sesuai hari wajib. |
| Kendaraan jatuh tempo servis | Jumlah kendaraan dengan jadwal/ambang KM yang telah tercapai. |
| Dokumen akan kedaluwarsa | KIR, STNK, asuransi yang akan/habis masa berlakunya. |
| Work Order terbuka | Request/WO yang belum diselesaikan. |
| Pemeliharaan tepat waktu | Persentase pekerjaan preventive yang selesai sebelum due date. |
| Kelengkapan register aset | Persentase aset dengan nomor dan penempatan yang lengkap. |
| Pemeriksaan fasilitas terlaksana | Inspeksi kebersihan dan gedung sesuai jadwal. |
| Kontrak sewa jatuh tempo | Jumlah tagihan sewa mendekati due date. |
| ATK stok menipis | SKU ATK yang memenuhi aturan low-stock Inventory existing. |

Ambang notifikasi (misalnya H-30/H-14/H-7), SLA perbaikan, dan target KPI harus disetujui oleh GA terlebih dahulu.

## 13. Roadmap implementasi

| Fase | Output kerja | Kriteria selesai |
|---|---|---|
| **R0 — Validasi bisnis** | Konfirmasi SOP GA, jenis aset, tanggung jawab, ambang KM, frekuensi ATK dan dokumen. | Dokumen keputusan disahkan. |
| **R1 — Fondasi GA** | ERD dan migration aset, kode per PT, permission, API master aset, log KM, audit. | Role salah 403, data terisolasi sesuai PT, test lulus. |
| **R2 — Preventive & Requests** | Jadwal servis, dokumen kendaraan, request/Work Order, notifikasi dan histori. | Perhitungan due dan transisi diuji. |
| **R3 — Integrasi Inventori & ATK** | Pencatatan ATK, permintaan procurement, spare part via Stock Service. | Tidak ada mutasi ganda; GRN/PO/distribusi tetap lulus. |
| **R4 — GA Lanjutan** | Pinjam kendaraan, mutasi/disposal aset, inspeksi gedung, sewa, dashboard. | SOP disahkan, approval & audit lengkap. |
| **R5 — Data migration & rollout** | Import Google Sheet, mapping Smart Salary/KM RIS, UAT GA, pelatihan. | Data tervalidasi dan sign-off operasional. |

## 14. Contoh acceptance criteria dan pengujian

1. GA dapat mencatat KM untuk aset kendaraan yang aktif; pembacaan tidak boleh berkurang tanpa koreksi beralasan.
2. Sistem menghasilkan kondisi servis due berdasarkan aturan interval KM atau kalender yang telah disetujui; 10.000 KM tidak ditafsirkan sebelum aturan final.
3. KIR, STNK, dan asuransi memiliki histori dan tanggal kedaluwarsa yang dapat ditelusuri.
4. Request dari Smart Salary tidak menjadi dua ticket bila diimpor ulang dengan referensi sama.
5. Peminjaman kendaraan tidak dapat beririsan pada unit/waktu yang sama setelah aturan booking disepakati.
6. Aset yang dimutasi atau dijual mempunyai approval dan audit sesuai SOP GA.
7. Stock opname ATK tidak pernah mengubah stok secara langsung; hanya Stock Service Inventory.
8. Pemakaian spare part melalui Work Order tidak dapat diproses dua kali dan tidak pernah membuat saldo negatif.
9. User tanpa permission mendapat 403; data sensitif tidak bocor dari endpoint download.
10. Seluruh **338 test / 2.669 assertions** existing tetap menjadi baseline regression berdasarkan hasil yang dilaporkan pengguna; semua test baru harus lulus juga.

## 15. Keputusan bisnis yang harus dikonfirmasi

| ID | Isu dari sumber atau rancangan | Dampak jika belum diputuskan |
|---|---|---|
| D-01 | Apakah sistem mencakup kendaraan, alat berat, aset kantor, bangunan, dan ATK dalam satu domain GA? | Batas modul, master data dan menu. |
| D-02 | “Max 10.000 KM” berarti interval servis sejak KM servis terakhir atau ambang lain? | Perhitungan due servis. |
| D-03 | Untuk alat berat, apakah servis berdasar 2 bulan, jam operasi, atau keduanya? | Tipe meter dan maintenance plan. |
| D-04 | Apakah pengecekan KIR/STNK/asuransi hanya periodik atau menggunakan expiry aktual? | Reminder dan validitas dokumen. |
| D-05 | Bagaimana proses peminjaman kendaraan: pengaju, approval, serah-terima, pengembalian? | Workflow vehicle loan. |
| D-06 | Apa SOP GA untuk mutasi serta penjualan/disposal aset? | Otorisasi dan status. |
| D-07 | Teks “Whatsup” merujuk ke platform apa? Apakah Smart Salary/KM RIS memiliki API? | Integrasi eksternal. |
| D-08 | Frekuensi stock opname ATK: Daily atau 1 bulan sekali? | Scheduler, SLA, KPI. |
| D-09 | Frekuensi pengadaan ATK: Daily atau 1 bulan sekali? | Reminder dan auto-suggestion. |
| D-10 | Siapa yang berperan GA Operator, GA Supervisor, teknisi, dan penyetuju? | Permission dan kebijakan 403. |
| D-11 | Bagaimana relasi aset terhadap PT (Company existing) dan transfer antar-PT? | FK, data ownership, tenancy. |
| D-12 | Kapan spare part dianggap keluar dari Inventory, dan bagaimana retur dicatat? | Integritas mutasi stok. |
| D-13 | Apakah request dari Smart Salary wajib menjadi Work Order atau bisa langsung ditutup? | State machine dan migrasi historis. |

## 16. Definition of Done RPD

RPD dianggap siap diterjemahkan ke ERD dan migration ketika semua isu D-01 sampai D-13 yang relevan untuk MVP sudah diputuskan, penanggung jawab bisnis menyetujui workflow, batas data antar domain disahkan, dan tidak ada perubahan tak sengaja pada Purchasing & Inventory.

## Lampiran A — Gambar sumber jobdesk GA

Gambar berikut merupakan bukti sumber yang dikirim pengguna dan disertakan tanpa perubahan isi. Keterangan detail ditranskripsi pada Bagian 5. Jika dokumen ini dipindahkan sebagai Markdown ke repository, simpan gambar pada jalur relatif yang sama.

![Gambar asli tabel aktivitas dan jobdesk GA](rpd_maintenance_ga_assets/sumber-jobdesk-maintenance-ga.jpeg)