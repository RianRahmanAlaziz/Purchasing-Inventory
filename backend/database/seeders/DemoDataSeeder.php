<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data dummy untuk demo/pengembangan. Jalankan:
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Seeder ini dilewati jika data demo sudah pernah dibuat (cek supplier SUP-001).
 * Password semua akun demo: Password123!
 */
final class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    private ?object $ppn = null;

    /** @var array<int, float> saldo stok berjalan per item_id */
    private array $balance = [];

    /**
     * Staff HR (Aida): Pembuatan PO + Order Management.
     * items.view, suppliers.*, taxes.view, companies.view dipakai sebagai lookup di form PO.
     */
    private const STAFF_HR_PERMISSIONS = [
        'companies.view', 'suppliers.view', 'suppliers.create', 'items.view', 'items.create', 'taxes.view',
        'po.view', 'po.create', 'po.update', 'po.submit', 'po.send', 'po.supplier-confirm', 'orders.view',
    ];

    /**
     * Staff HR GA (Farhan): Penerimaan Barang, Stok Barang, Mutasi Stok, Distribusi Barang.
     * po.view dipakai sebagai lookup PO di form penerimaan barang.
     */
    private const STAFF_HR_GA_PERMISSIONS = [
        'po.view', 'grn.view', 'grn.create', 'items.view', 'inventory.view',
        'distribution.view', 'distribution.prepare', 'distribution.send',
    ];

    public function run(): void
    {
        DB::transaction(fn () => $this->staffRoles());

        if (DB::table('suppliers')->where('code', 'SUP-001')->exists()) {
            DB::transaction(fn () => $this->upgradeExistingDemo());
            $this->command?->info('Data demo sudah ada; akun Aida & Farhan diperbarui.');

            return;
        }

        $this->ppn = DB::table('tax_rates')
            ->join('tax_types', 'tax_types.id', '=', 'tax_rates.tax_type_id')
            ->where('tax_types.code', 'PPN_NON_MEWAH')
            ->select('tax_rates.*', 'tax_types.code', 'tax_types.direction')
            ->first();

        DB::transaction(function (): void {
            $users = $this->users();
            $companyId = $this->company();
            $departments = $this->departments();
            $employees = $this->employees($departments, $users);
            $suppliers = $this->suppliers();
            $items = $this->items($this->categories());

            $this->openingStock($items, $users['hr']);
            $pos = $this->purchaseOrders($companyId, $suppliers, $items, $employees, $departments, $users);
            $this->distributions($items, $employees, $users);
            $this->finance($suppliers, $pos, $users);
        });

        $this->command?->info('Data demo berhasil dibuat. Login: admin@demo.test / hr@demo.test / gm@demo.test / aida@demo.test / farhan@demo.test (password: '.self::PASSWORD.')');
    }

    private function staffRoles(): void
    {
        if (! Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
            return; // RBAC belum diinisialisasi.
        }

        $permission = fn (string $name) => Permission::findOrCreate($name, 'web');
        Role::findOrCreate('staff_hr', 'web')->syncPermissions(array_map($permission, self::STAFF_HR_PERMISSIONS));
        Role::findOrCreate('staff_hr_ga', 'web')->syncPermissions(array_map($permission, self::STAFF_HR_GA_PERMISSIONS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Menyesuaikan data demo lama (akun staff@demo.test) ke akun Aida & Farhan. */
    private function upgradeExistingDemo(): void
    {
        $legacy = User::query()->where('email', 'staff@demo.test')->first();
        if ($legacy !== null && ! User::query()->where('email', 'aida@demo.test')->exists()) {
            $legacy->update(['name' => 'Aida', 'email' => 'aida@demo.test']);
        }
        DB::table('employees')->where('employee_number', 'EMP-002')
            ->update(['name' => 'Aida', 'email' => 'aida@ris-demo.co.id', 'user_id' => null]);

        $users = $this->users();
        $hrga = DB::table('departments')->where('code', 'HRGA')->value('id');
        if ($hrga !== null && ! DB::table('employees')->where('employee_number', 'EMP-011')->exists()) {
            DB::table('employees')->insert([
                'department_id' => $hrga, 'user_id' => null, 'employee_number' => 'EMP-011', 'name' => 'Farhan',
                'email' => 'farhan@ris-demo.co.id', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Pekerjaan gudang (GRN, distribusi, mutasi stok terkait) dipindahkan ke Farhan.
        [$aida, $farhan] = [$users['staff'], $users['ga']];
        DB::table('goods_receipts')->where('created_by', $aida)->update(['created_by' => $farhan]);
        foreach (['requested_by', 'sent_by', 'received_by'] as $column) {
            DB::table('distributions')->where($column, $aida)->update([$column => $farhan]);
        }
        DB::table('distribution_events')->where('actor_id', $aida)->update(['actor_id' => $farhan]);
        DB::table('stock_movements')->where('created_by', $aida)
            ->where(fn ($q) => $q->whereNotNull('goods_receipt_item_id')->orWhereNotNull('distribution_item_id'))
            ->update(['created_by' => $farhan]);
    }

    /** @return array<string, int> */
    private function users(): array
    {
        $accounts = [
            'admin' => ['Admin Demo', 'admin@demo.test', 'super_admin'],
            'gm' => ['Budi Santoso', 'gm@demo.test', 'general_manager'],
            'hr' => ['Siti Rahmawati', 'hr@demo.test', 'hr'],
            'staff' => ['Aida', 'aida@demo.test', 'staff_hr'],
            'ga' => ['Farhan', 'farhan@demo.test', 'staff_hr_ga'],
        ];

        $ids = [];
        foreach ($accounts as $key => [$name, $email, $role]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
            ]);

            if (Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
                $user->syncRoles([$role]);
            } else {
                $this->command?->warn("Role {$role} belum ada; jalankan RolePermissionSeeder.");
            }
            $ids[$key] = (int) $user->id;
        }

        return $ids;
    }

    private function company(): int
    {
        $id = DB::table('companies')->insertGetId([
            'name' => 'PT Rumah Industri Sejahtera',
            'address' => 'Jl. Jend. Sudirman Kav. 45, Jakarta Selatan 12930',
            'phone' => '021-5550123',
            'email' => 'purchasing@ris-demo.co.id',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->whereIn('email', ['admin@demo.test', 'gm@demo.test', 'hr@demo.test', 'aida@demo.test', 'farhan@demo.test'])
            ->update(['default_company_id' => $id]);

        return $id;
    }

    /** @return array<string, object> */
    private function departments(): array
    {
        $rows = [
            ['HRGA', 'Human Resource & General Affair'],
            ['FIN', 'Finance & Accounting'],
            ['IT', 'Information Technology'],
            ['MKT', 'Marketing'],
            ['OPS', 'Operasional'],
        ];
        $result = [];
        foreach ($rows as [$code, $name]) {
            $id = DB::table('departments')->insertGetId([
                'code' => $code, 'name' => $name, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $result[$code] = (object) ['id' => $id, 'name' => $name];
        }

        return $result;
    }

    /** @return array<int, object> */
    private function employees(array $departments, array $users): array
    {
        $rows = [
            ['EMP-001', 'Siti Rahmawati', 'HRGA', 'hr'],
            // Aida & Farhan sengaja tidak ditautkan ke user agar menu Distribusi (permintaan
            // barang karyawan) tidak muncul untuk Aida; Farhan mengakses lewat role staff_hr_ga.
            ['EMP-002', 'Aida', 'HRGA', null],
            ['EMP-003', 'Budi Santoso', 'OPS', 'gm'],
            ['EMP-004', 'Dewi Lestari', 'FIN', null],
            ['EMP-005', 'Rizky Hidayat', 'IT', null],
            ['EMP-006', 'Fajar Nugroho', 'IT', null],
            ['EMP-007', 'Maya Sari', 'MKT', null],
            ['EMP-008', 'Agus Setiawan', 'OPS', null],
            ['EMP-009', 'Putri Ayu', 'FIN', null],
            ['EMP-010', 'Hendra Wijaya', 'MKT', null],
            ['EMP-011', 'Farhan', 'HRGA', null],
        ];
        $result = [];
        foreach ($rows as [$number, $name, $dept, $userKey]) {
            $id = DB::table('employees')->insertGetId([
                'department_id' => $departments[$dept]->id,
                'user_id' => $userKey ? $users[$userKey] : null,
                'employee_number' => $number,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@ris-demo.co.id',
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $result[] = (object) ['id' => $id, 'name' => $name, 'dept' => $departments[$dept]];
        }

        return $result;
    }

    /** @return array<int, object> */
    private function suppliers(): array
    {
        $rows = [
            ['CV Sinar Jaya Stationery', 'Hendra Kusuma', '0812-3456-7890', 'sales@sinarjaya.co.id', 'Jl. Pasar Baru No. 12, Jakarta Pusat'],
            ['PT Mitra Komputindo', 'Rina Marlina', '0813-2222-1111', 'order@mitrakomputindo.com', 'Mangga Dua Mall Lt. 3 Blok C, Jakarta Utara'],
            ['Toko Makmur Elektronik', 'Joko Susilo', '0857-7777-8888', 'makmur.elektronik@gmail.com', 'Jl. Glodok Raya No. 88, Jakarta Barat'],
            ['PT Bersih Sentosa', 'Lina Wati', '0821-5555-4444', 'cs@bersihsentosa.id', 'Jl. Raya Bekasi Km 18, Bekasi'],
            ['CV Furnindo Abadi', 'Yusuf Maulana', '0819-1234-5678', 'furnindo.abadi@yahoo.com', 'Jl. Industri Jepara No. 5, Jepara'],
            ['PT Pantry Nusantara', 'Ani Suryani', '0811-9090-8080', 'sales@pantrynusantara.co.id', 'Jl. Kebon Jeruk Raya No. 21, Jakarta Barat'],
        ];
        $result = [];
        foreach ($rows as $i => [$name, $cp, $phone, $email, $address]) {
            $code = sprintf('SUP-%03d', $i + 1);
            $id = DB::table('suppliers')->insertGetId([
                'code' => $code, 'name' => $name, 'normalized_name' => strtolower($name),
                'contact_person' => $cp, 'phone' => $phone, 'email' => $email, 'address' => $address,
                'is_active' => true, 'notes' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $result[] = (object) ['id' => $id, 'code' => $code, 'name' => $name, 'address' => $address];
        }

        return $result;
    }

    /** @return array<string, int> */
    private function categories(): array
    {
        $rows = [
            'ATK' => ['Alat Tulis Kantor', 'Kertas, pulpen, map, dan perlengkapan tulis'],
            'ELK' => ['Elektronik & IT', 'Perangkat komputer dan aksesoris'],
            'KBR' => ['Kebersihan', 'Perlengkapan kebersihan kantor'],
            'FUR' => ['Furnitur', 'Meja, kursi, dan lemari kantor'],
            'PNT' => ['Pantry', 'Kebutuhan dapur dan konsumsi kantor'],
        ];
        $result = [];
        foreach ($rows as $code => [$name, $desc]) {
            $result[$code] = DB::table('categories')->insertGetId([
                'code' => $code, 'name' => $name, 'description' => $desc, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $result;
    }

    /** @return array<string, object> */
    private function items(array $categories): array
    {
        // sku => [kategori, nama, satuan, min, max, harga]
        $rows = [
            'ATK-001' => ['ATK', 'Kertas HVS A4 80gsm', 'rim', 20, 150, 52000],
            'ATK-002' => ['ATK', 'Pulpen Gel Hitam 0.5', 'pak', 10, 60, 36000],
            'ATK-003' => ['ATK', 'Map Plastik Snelhecter', 'lusin', 5, 40, 30000],
            'ATK-004' => ['ATK', 'Stapler Besar HD-50', 'pcs', 3, 20, 45000],
            'ATK-005' => ['ATK', 'Tinta Printer Epson 003 Hitam', 'botol', 6, 40, 85000],
            'ELK-001' => ['ELK', 'Mouse Wireless Logitech M185', 'pcs', 5, 30, 165000],
            'ELK-002' => ['ELK', 'Keyboard USB Standar', 'pcs', 5, 30, 125000],
            'ELK-003' => ['ELK', 'Flashdisk 32GB', 'pcs', 5, 40, 75000],
            'ELK-004' => ['ELK', 'Monitor LED 24 inch', 'unit', 1, 10, 1850000],
            'KBR-001' => ['KBR', 'Tisu Wajah 250 Sheet', 'pak', 20, 120, 18000],
            'KBR-002' => ['KBR', 'Cairan Pembersih Lantai 5L', 'jerigen', 4, 20, 95000],
            'KBR-003' => ['KBR', 'Kantong Sampah Besar', 'pak', 10, 50, 25000],
            'FUR-001' => ['FUR', 'Kursi Kerja Ergonomis', 'unit', 0, 15, 1450000],
            'PNT-001' => ['PNT', 'Kopi Bubuk Kapal Api 380g', 'pak', 10, 60, 32000],
            'PNT-002' => ['PNT', 'Air Mineral Galon 19L', 'galon', 10, 50, 21000],
        ];
        $result = [];
        foreach ($rows as $sku => [$cat, $name, $unit, $min, $max, $price]) {
            $id = DB::table('items')->insertGetId([
                'category_id' => $categories[$cat], 'sku' => $sku, 'name' => $name, 'unit' => $unit,
                'stock_quantity' => 0, 'minimum_stock' => $min, 'maximum_stock' => $max,
                'last_purchase_price' => $price, 'reference_price' => $price,
                'is_active' => true, 'awaiting_first_receipt' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->balance[$id] = 0.0;
            $result[$sku] = (object) ['id' => $id, 'sku' => $sku, 'name' => $name, 'unit' => $unit, 'price' => $price];
        }

        return $result;
    }

    private function openingStock(array $items, int $actor): void
    {
        $opening = [
            'ATK-001' => 40, 'ATK-002' => 15, 'ATK-003' => 8, 'ATK-004' => 6, 'ATK-005' => 4,
            'ELK-001' => 7, 'ELK-002' => 3, 'ELK-003' => 12, 'ELK-004' => 2,
            'KBR-001' => 35, 'KBR-002' => 5, 'KBR-003' => 6,
            'FUR-001' => 2, 'PNT-001' => 18, 'PNT-002' => 8,
        ];
        $at = Carbon::parse('2026-10-01 08:00:00');
        foreach ($opening as $sku => $qty) {
            $item = $items[$sku];
            $adjId = DB::table('stock_adjustments')->insertGetId([
                'item_id' => $item->id, 'adjusted_by' => $actor,
                'stock_before' => 0, 'stock_after' => $qty, 'quantity_difference' => $qty,
                'reason' => 'Stok awal hasil stock opname Oktober 2026',
                'applied_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            $this->move($item->id, $actor, 'adjustment', $qty, $at, 'Stok awal hasil stock opname', ['stock_adjustment_id' => $adjId]);
        }
    }

    /** @return array<string, object> */
    private function purchaseOrders(int $companyId, array $suppliers, array $items, array $employees, array $departments, array $users): array
    {
        $company = DB::table('companies')->find($companyId);
        $companySnapshot = json_encode([
            'id' => $company->id, 'name' => $company->name, 'address' => $company->address,
            'phone' => $company->phone, 'email' => $company->email, 'logo' => '',
        ]);

        // key => [status, supplier idx, employee idx, tanggal, [sku => qty], received ('full'|'partial'|null), catatan]
        $plans = [
            'A' => ['received_by_supplier', 0, 0, '2026-10-05', ['ATK-001' => 50, 'ATK-002' => 20, 'ATK-003' => 10], 'full', 'Kebutuhan ATK bulanan Oktober'],
            'B' => ['received_by_supplier', 1, 4, '2026-10-12', ['ELK-001' => 10, 'ELK-002' => 10, 'ELK-004' => 4], 'full', 'Peremajaan perangkat tim IT'],
            'C' => ['received_by_supplier', 3, 7, '2026-10-20', ['KBR-001' => 60, 'KBR-002' => 8, 'KBR-003' => 20], 'partial', 'Stok kebersihan kuartal IV'],
            'D' => ['sent', 5, 1, '2026-11-03', ['PNT-001' => 24, 'PNT-002' => 30], null, 'Kebutuhan pantry November'],
            'E' => ['approved', 4, 2, '2026-11-08', ['FUR-001' => 6], null, 'Kursi untuk ruang meeting baru'],
            'F' => ['pending_approval', 0, 3, '2026-11-14', ['ATK-001' => 30, 'ATK-005' => 12, 'ATK-004' => 5], null, 'ATK tim Finance'],
            'G' => ['pending_approval', 1, 5, '2026-11-16', ['ELK-004' => 5, 'ELK-003' => 20], null, 'Monitor tambahan untuk developer'],
            'H' => ['draft', 2, 6, '2026-11-18', ['ELK-003' => 15], null, 'Flashdisk untuk materi event'],
            'I' => ['rejected', 4, 9, '2026-10-25', ['FUR-001' => 10], null, 'Pengadaan kursi lounge'],
            'J' => ['cancelled', 2, 8, '2026-10-28', ['ELK-001' => 5], null, 'Mouse cadangan'],
        ];

        $result = [];
        $seq = 0;
        foreach ($plans as $key => [$status, $supIdx, $empIdx, $date, $lines, $received, $notes]) {
            $seq++;
            $supplier = $suppliers[$supIdx];
            $employee = $employees[$empIdx];
            $orderDate = Carbon::parse($date.' 09:00:00');

            $subtotal = 0.0;
            foreach ($lines as $sku => $qty) {
                $subtotal += $qty * $items[$sku]->price;
            }
            $taxBase = $this->ppn ? round($subtotal * (float) $this->ppn->taxable_base_factor, 2) : 0.0;
            $tax = $this->ppn ? round($taxBase * (float) $this->ppn->rate_percent / 100, 2) : 0.0;
            $total = $subtotal + $tax;
            $needsGm = $total > 7000000;

            $submitted = $status === 'draft' ? null : $orderDate->copy()->addHours(1);
            $approved = in_array($status, ['approved', 'sent', 'received_by_supplier'], true) ? $orderDate->copy()->addDay() : null;
            $sent = in_array($status, ['sent', 'received_by_supplier'], true) ? $orderDate->copy()->addDays(2) : null;
            $confirmed = $status === 'received_by_supplier' ? $orderDate->copy()->addDays(3) : null;

            $poId = DB::table('purchase_orders')->insertGetId([
                'po_number' => sprintf('PO-%06d', $seq),
                'supplier_id' => $supplier->id,
                'requested_for_employee_id' => $employee->id,
                'requested_for_department_id' => $employee->dept->id,
                'requested_for_employee_name_snapshot' => $employee->name,
                'requested_for_department_name_snapshot' => $employee->dept->name,
                'created_by' => $users['staff'],
                'supplier_name_snapshot' => $supplier->name,
                'supplier_code_snapshot' => $supplier->code,
                'supplier_address_snapshot' => $supplier->address,
                'company_id' => $companyId,
                'company_snapshot' => $companySnapshot,
                'tax_scope' => 'order',
                'order_date' => $orderDate->toDateString(),
                'expected_date' => $orderDate->copy()->addDays(7)->toDateString(),
                'status' => $status,
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'tax_addition_total' => $tax,
                'tax_withholding_total' => 0,
                'total_amount' => $total,
                'approval_basis_amount' => $total,
                'approval_threshold_amount' => 7000000,
                'requires_gm_approval' => $needsGm,
                'submitted_at' => $submitted,
                'approved_at' => $approved,
                'rejected_at' => $status === 'rejected' ? $orderDate->copy()->addDay() : null,
                'rejection_reason' => $status === 'rejected' ? 'Anggaran furnitur kuartal ini sudah habis.' : null,
                'sent_at' => $sent,
                'supplier_confirmed_at' => $confirmed,
                'cancelled_at' => $status === 'cancelled' ? $orderDate->copy()->addDay() : null,
                'notes' => $notes,
                'created_at' => $orderDate, 'updated_at' => $confirmed ?? $sent ?? $approved ?? $submitted ?? $orderDate,
            ]);

            $poLines = [];
            foreach ($lines as $sku => $qty) {
                $item = $items[$sku];
                $poLines[$sku] = DB::table('purchase_order_items')->insertGetId([
                    'purchase_order_id' => $poId, 'item_id' => $item->id,
                    'item_sku_snapshot' => $item->sku, 'item_name_snapshot' => $item->name, 'unit_snapshot' => $item->unit,
                    'quantity' => $qty, 'unit_price' => $item->price, 'line_subtotal' => $qty * $item->price,
                    'discount_percent' => 0, 'discount_amount' => 0, 'taxable_base_amount' => 0, 'tax_amount' => 0,
                    'created_at' => $orderDate, 'updated_at' => $orderDate,
                ]);
            }

            if ($this->ppn) {
                DB::table('purchase_order_taxes')->insert([
                    'purchase_order_id' => $poId, 'tax_rate_id' => $this->ppn->id,
                    'tax_code_snapshot' => $this->ppn->code, 'tax_name_snapshot' => $this->ppn->label,
                    'direction_snapshot' => $this->ppn->direction,
                    'rate_percent_snapshot' => $this->ppn->rate_percent, 'base_factor_snapshot' => $this->ppn->taxable_base_factor,
                    'taxable_base_amount' => $taxBase, 'tax_amount' => $tax,
                    'created_at' => $orderDate, 'updated_at' => $orderDate,
                ]);
            }

            $this->approvalsAndEvents($poId, $status, $needsGm, $orderDate, $users);

            // Order dibuat ketika PO disetujui penuh.
            if ($approved !== null) {
                $orderStatus = match (true) {
                    $received === 'full' => 'completed',
                    $received === 'partial' => 'partially_received',
                    $status === 'received_by_supplier' => 'confirmed',
                    default => 'pending',
                };
                DB::table('orders')->insert([
                    'purchase_order_id' => $poId, 'status' => $orderStatus,
                    'confirmed_at' => $confirmed,
                    'completed_at' => $received === 'full' ? $orderDate->copy()->addDays(5) : null,
                    'created_at' => $approved, 'updated_at' => $orderDate->copy()->addDays(5),
                ]);
            }

            if ($received !== null) {
                $this->goodsReceipt($poId, $seq, $lines, $poLines, $items, $received, $orderDate->copy()->addDays(5), $users);
            }

            $result[$key] = (object) ['id' => $poId, 'supplier' => $supplier, 'total' => $total, 'date' => $orderDate, 'status' => $status];
        }

        return $result;
    }

    private function approvalsAndEvents(int $poId, string $status, bool $needsGm, Carbon $date, array $users): void
    {
        $event = function (string $action, ?string $from, string $to, int $actor, Carbon $at, ?string $notes = null) use ($poId): void {
            DB::table('purchase_order_events')->insert([
                'purchase_order_id' => $poId, 'actor_id' => $actor, 'action' => $action,
                'from_status' => $from, 'to_status' => $to, 'notes' => $notes, 'created_at' => $at,
            ]);
        };

        $event('created', null, 'draft', $users['staff'], $date);
        if ($status === 'draft') {
            return;
        }

        $event('submitted', 'draft', 'pending_approval', $users['staff'], $date->copy()->addHour());

        $approvedStatuses = ['approved', 'sent', 'received_by_supplier'];
        $hrStatus = match (true) {
            in_array($status, $approvedStatuses, true) => 'approved',
            $status === 'rejected' => 'rejected',
            $status === 'cancelled' => 'cancelled',
            default => 'pending',
        };
        DB::table('purchase_order_approvals')->insert([
            'purchase_order_id' => $poId, 'step_order' => 1, 'role_name' => 'hr', 'status' => $hrStatus,
            'decided_by' => in_array($hrStatus, ['approved', 'rejected'], true) ? $users['hr'] : null,
            'decided_at' => in_array($hrStatus, ['approved', 'rejected'], true) ? $date->copy()->addHours(5) : null,
            'rejection_reason' => $hrStatus === 'rejected' ? 'Anggaran furnitur kuartal ini sudah habis.' : null,
            'created_at' => $date, 'updated_at' => $date,
        ]);
        if ($needsGm) {
            $gmStatus = $hrStatus === 'approved' ? 'approved' : ($hrStatus === 'pending' ? 'pending' : 'cancelled');
            DB::table('purchase_order_approvals')->insert([
                'purchase_order_id' => $poId, 'step_order' => 2, 'role_name' => 'general_manager', 'status' => $gmStatus,
                'decided_by' => $gmStatus === 'approved' ? $users['gm'] : null,
                'decided_at' => $gmStatus === 'approved' ? $date->copy()->addDay() : null,
                'created_at' => $date, 'updated_at' => $date,
            ]);
        }

        if ($status === 'rejected') {
            $event('rejected_hr', 'pending_approval', 'rejected', $users['hr'], $date->copy()->addDay(), 'Anggaran furnitur kuartal ini sudah habis.');

            return;
        }
        if ($status === 'cancelled') {
            $event('cancelled', 'pending_approval', 'cancelled', $users['hr'], $date->copy()->addDay(), 'Supplier tidak memiliki stok.');

            return;
        }
        if (in_array($status, $approvedStatuses, true)) {
            $event('approved', 'pending_approval', 'approved', $needsGm ? $users['gm'] : $users['hr'], $date->copy()->addDay());
        }
        if (in_array($status, ['sent', 'received_by_supplier'], true)) {
            $event('sent', 'approved', 'sent', $users['staff'], $date->copy()->addDays(2));
        }
        if ($status === 'received_by_supplier') {
            $event('supplier_confirmed', 'sent', 'received_by_supplier', $users['staff'], $date->copy()->addDays(3));
        }
    }

    private function goodsReceipt(int $poId, int $seq, array $lines, array $poLines, array $items, string $mode, Carbon $at, array $users): void
    {
        $grnId = DB::table('goods_receipts')->insertGetId([
            'grn_number' => sprintf('GRN-%06d', $seq),
            'purchase_order_id' => $poId,
            'created_by' => $users['ga'], 'confirmed_by' => $users['hr'],
            'received_date' => $at->toDateString(),
            'status' => $mode === 'full' ? 'fully_received' : 'partially_received',
            'confirmed_at' => $at,
            'notes' => $mode === 'full' ? 'Barang diterima lengkap dan baik.' : 'Sebagian barang dikirim menyusul.',
            'created_at' => $at, 'updated_at' => $at,
        ]);

        foreach ($lines as $sku => $qty) {
            $got = $mode === 'full' ? $qty : (int) floor($qty / 2);
            $griId = DB::table('goods_receipt_items')->insertGetId([
                'goods_receipt_id' => $grnId, 'purchase_order_item_id' => $poLines[$sku],
                'qty_received' => $got, 'qty_damaged' => 0,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $this->move($items[$sku]->id, $users['hr'], 'grn_in', $got, $at, null, ['goods_receipt_item_id' => $griId]);
        }
    }

    private function distributions(array $items, array $employees, array $users): void
    {
        // [status, employee idx, tanggal, [sku => qty], tujuan]
        $plans = [
            ['received_by_employee', 3, '2026-10-15', ['ATK-001' => 10, 'ATK-002' => 5], 'Kebutuhan tutup buku Oktober'],
            ['received_by_employee', 4, '2026-10-22', ['ELK-001' => 4, 'ELK-002' => 4], 'Setup workstation karyawan baru IT'],
            ['sent', 6, '2026-11-05', ['ATK-003' => 6, 'ELK-003' => 5], 'Materi presentasi klien'],
            ['preparing', 7, '2026-11-12', ['KBR-001' => 20, 'KBR-003' => 5], 'Kebersihan area gudang'],
            ['approved', 5, '2026-11-15', ['ELK-004' => 2], 'Monitor tambahan developer'],
            ['requested', 9, '2026-11-18', ['PNT-001' => 6], 'Konsumsi rapat tim marketing'],
            ['cancelled', 8, '2026-11-02', ['FUR-001' => 1], 'Kursi pengganti (dibatalkan)'],
        ];

        foreach ($plans as $i => [$status, $empIdx, $date, $lines, $purpose]) {
            $at = Carbon::parse($date.' 10:00:00');
            $reached = fn (string $s): bool => array_search($status, ['requested', 'approved', 'preparing', 'sent', 'received_by_employee'], true)
                >= array_search($s, ['requested', 'approved', 'preparing', 'sent', 'received_by_employee'], true);
            $live = $status !== 'cancelled';

            $id = DB::table('distributions')->insertGetId([
                'distribution_number' => sprintf('DIST-%06d', $i + 1),
                'employee_id' => $employees[$empIdx]->id,
                'requested_by' => $users['ga'],
                'approved_by' => $live && $reached('approved') ? $users['gm'] : null,
                'sent_by' => $live && $reached('sent') ? $users['ga'] : null,
                'received_by' => $live && $reached('received_by_employee') ? $users['ga'] : null,
                'status' => $status,
                'purpose' => $purpose,
                'requested_at' => $at,
                'approved_at' => $live && $reached('approved') ? $at->copy()->addHours(3) : null,
                'sent_at' => $live && $reached('sent') ? $at->copy()->addDay() : null,
                'received_at' => $live && $reached('received_by_employee') ? $at->copy()->addDays(1)->addHours(2) : null,
                'cancelled_at' => $live ? null : $at->copy()->addHours(4),
                'rejection_reason' => $live ? null : 'Kursi lama masih bisa diperbaiki.',
                'created_at' => $at, 'updated_at' => $at,
            ]);

            DB::table('distribution_events')->insert([
                'distribution_id' => $id, 'actor_id' => $users['ga'], 'action' => 'requested',
                'from_status' => null, 'to_status' => 'requested', 'created_at' => $at,
            ]);
            if ($status !== 'requested') {
                DB::table('distribution_events')->insert([
                    'distribution_id' => $id, 'actor_id' => $users['gm'],
                    'action' => $live ? 'status_changed' : 'rejected',
                    'from_status' => 'requested', 'to_status' => $status, 'created_at' => $at->copy()->addHours(3),
                ]);
            }

            foreach ($lines as $sku => $qty) {
                $diId = DB::table('distribution_items')->insertGetId([
                    'distribution_id' => $id, 'item_id' => $items[$sku]->id, 'quantity' => $qty,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
                // Stok keluar saat barang dikirim.
                if ($live && $reached('sent')) {
                    $this->move($items[$sku]->id, $users['ga'], 'distribution_out', -$qty, $at->copy()->addDay(), $purpose, ['distribution_item_id' => $diId]);
                }
            }
        }
    }

    private function finance(array $suppliers, array $pos, array $users): void
    {
        // Tagihan Supplier (invoices)
        $invoices = [];
        foreach (['A' => 'INV/SJS/X/2026/0412', 'B' => 'MK-INV-2026-1187', 'C' => 'BS/2610/0098', 'D' => 'PN-INV-11-0031'] as $key => $number) {
            $po = $pos[$key];
            $invDate = $po->date->copy()->addDays(6);
            $invoices[$key] = (object) ['id' => DB::table('invoices')->insertGetId([
                'invoice_number' => $number, 'supplier_id' => $po->supplier->id, 'purchase_order_id' => $po->id,
                'invoice_date' => $invDate->toDateString(), 'due_date' => $invDate->copy()->addDays(30)->toDateString(),
                'total_amount' => $po->total, 'notes' => $key === 'C' ? 'Barang diterima sebagian, sisa menyusul.' : null,
                'created_by' => $users['hr'], 'created_at' => $invDate, 'updated_at' => $invDate,
            ]), 'total' => $po->total, 'date' => $invDate];
        }

        // Pembayaran
        $payments = [
            ['A', 1.0, 'verified', 'TRF BCA 0412-778812'],
            ['B', 0.5, 'verified', 'TRF Mandiri DP 50%'],
            ['B', 0.5, 'pending_verification', 'TRF Mandiri pelunasan'],
            ['C', 0.3, 'rejected', 'TRF BRI 2610-55'],
        ];
        foreach ($payments as $i => [$key, $portion, $status, $ref]) {
            $inv = $invoices[$key];
            $payDate = $inv->date->copy()->addDays(7 + $i);
            DB::table('payments')->insert([
                'payment_number' => sprintf('PAY-%06d', $i + 1), 'invoice_id' => $inv->id,
                'payment_date' => $payDate->toDateString(), 'amount' => round($inv->total * $portion, 2),
                'reference' => $ref, 'notes' => null, 'status' => $status,
                'created_by' => $users['hr'],
                'verified_by' => $status !== 'pending_verification' ? $users['admin'] : null,
                'verified_at' => $status !== 'pending_verification' ? $payDate->copy()->addDay() : null,
                'rejection_reason' => $status === 'rejected' ? 'Nominal transfer tidak sesuai bukti.' : null,
                'created_at' => $payDate, 'updated_at' => $payDate,
            ]);
        }

        // Kuitansi & Dokumen (tanpa file fisik)
        $documents = [
            ['A', 'Kuitansi Pembayaran', 'verified'],
            ['B', 'Surat Jalan', 'received'],
            ['C', 'Faktur Pajak', 'sent'],
            ['D', 'Kuitansi Pembayaran', 'not_uploaded'],
        ];
        foreach ($documents as $i => [$key, $type, $status]) {
            $po = $pos[$key];
            $at = $po->date->copy()->addDays(8);
            $stages = ['sent' => 1, 'received' => 2, 'verified' => 3];
            $level = $stages[$status] ?? 0;
            DB::table('documents')->insert([
                'document_number' => sprintf('DOC-%06d', $i + 1), 'document_type' => $type,
                'supplier_id' => $po->supplier->id, 'purchase_order_id' => $po->id,
                'document_date' => $at->toDateString(), 'status' => $status,
                'notes' => null, 'created_by' => $users['hr'], 'updated_by' => $users['hr'],
                'sent_at' => $level >= 1 ? $at->copy()->addDay() : null, 'sent_by' => $level >= 1 ? $users['staff'] : null,
                'received_at' => $level >= 2 ? $at->copy()->addDays(2) : null, 'received_by' => $level >= 2 ? $users['hr'] : null,
                'verified_at' => $level >= 3 ? $at->copy()->addDays(3) : null, 'verified_by' => $level >= 3 ? $users['admin'] : null,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    private function move(int $itemId, int $actor, string $type, float $qty, Carbon $at, ?string $reason, array $ref): void
    {
        $before = $this->balance[$itemId];
        $after = $before + $qty;
        $this->balance[$itemId] = $after;

        DB::table('stock_movements')->insert([
            'item_id' => $itemId, 'created_by' => $actor, 'movement_type' => $type,
            'quantity_change' => $qty, 'balance_before' => $before, 'balance_after' => $after,
            'reason' => $reason, 'created_at' => $at,
        ] + $ref);

        DB::table('items')->where('id', $itemId)->update(['stock_quantity' => $after, 'updated_at' => $at]);
    }
}
