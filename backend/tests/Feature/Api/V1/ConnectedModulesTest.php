<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Company\Models\Company;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Item\Models\Item;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tax\Models\TaxType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ConnectedModulesTest extends TestCase
{
    use DatabaseTransactions;

    private Supplier $supplier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $permissions = ['companies.view', 'companies.manage', 'invoices.view', 'invoices.manage',
            'payments.view', 'payments.manage', 'payments.verify', 'documents.view', 'documents.manage',
            'documents.send', 'documents.receive', 'documents.verify', 'po.create', 'po.update', 'po.view'];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $role = Role::findOrCreate('connected-test', 'web');
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::factory()->create(['is_active' => true])->assignRole($role);
        Sanctum::actingAs($this->admin, ['*']);
        $this->supplier = Supplier::query()->create(['code' => 'CONNECTED', 'name' => 'Supplier Integrasi',
            'normalized_name' => 'supplier integrasi', 'is_active' => true, 'address' => 'Alamat master']);
    }

    private function invoice(string $amount = '1000.00'): int
    {
        return (int) $this->postJson('/api/v1/invoices', ['invoice_number' => 'INV-TEST', 'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-11-05', 'total_amount' => $amount])->assertCreated()->json('data.id');
    }

    public function test_company_crud_and_default_are_persisted_and_validated(): void
    {
        $id = $this->postJson('/api/v1/companies', ['name' => 'PT Integrasi', 'address' => "Jalan Satu\nBandung"])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/companies/$id", ['name' => 'PT Integrasi Baru'])->assertOk();
        $this->postJson("/api/v1/companies/$id/activate")->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.default_company_id', $id);
        $this->postJson('/api/v1/companies', ['name' => 'PT Integrasi Baru'])->assertUnprocessable();
        $this->patchJson("/api/v1/companies/$id", ['logo' => 'data:image/svg+xml;base64,PHN2Zz4='])->assertUnprocessable();
        $this->deleteJson("/api/v1/companies/$id")->assertOk();
        $this->getJson("/api/v1/companies/$id")->assertNotFound();
        $this->assertNull($this->admin->fresh()->default_company_id);
    }

    public function test_po_keeps_company_address_discounts_line_tax_and_snapshots_across_partial_edit(): void
    {
        $company = Company::query()->create(['name' => 'PT Penerbit', 'address' => 'Alamat PT']);
        $department = Department::query()->create(['code' => 'CONNECTED', 'name' => 'Operasional', 'is_active' => true]);
        $employee = Employee::query()->create(['employee_number' => 'CONNECTED', 'name' => 'Pemohon', 'department_id' => $department->id, 'is_active' => true]);
        $category = Category::query()->create(['code' => 'CONNECTED', 'name' => 'Kategori', 'is_active' => true]);
        $item = Item::query()->create(['category_id' => $category->id, 'sku' => 'CONNECTED', 'name' => 'Master Barang', 'unit' => 'pcs', 'minimum_stock' => 0, 'is_active' => true]);
        $type = TaxType::query()->create(['code' => 'CONNECTED', 'name' => 'PPN Uji', 'direction' => 'addition', 'is_active' => true]);
        $rate = $type->rates()->create(['label' => 'PPN Uji 12', 'rate_percent' => '12', 'taxable_base_factor' => '0.5',
            'effective_from' => '2026-01-01', 'is_active' => true]);
        $payload = ['company_id' => $company->id, 'supplier_id' => $this->supplier->id,
            'supplier_address' => "Alamat tujuan\nJakarta", 'requested_for_employee_id' => $employee->id,
            'requested_for_department_id' => $department->id, 'order_date' => '2026-11-05', 'tax_scope' => 'line',
            'items' => [['item_id' => $item->id, 'description' => 'Deskripsi pada PO', 'unit' => 'pcs',
                'quantity' => '2.000', 'unit_price' => '10000.00', 'discount_percent' => '10', 'tax_rate_id' => $rate->id]]];
        $id = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()
            ->assertJsonPath('data.gross_subtotal', '20000.00')->assertJsonPath('data.discount_total', '2000.00')
            ->assertJsonPath('data.subtotal', '18000.00')->assertJsonPath('data.total_amount', '19080.00')
            ->assertJsonPath('data.items.0.tax_amount', '1080.00')->json('data.id');
        $company->update(['name' => 'PT Diganti']);
        $this->patchJson("/api/v1/purchase-orders/$id", ['notes' => "Catatan satu\nCatatan dua"])->assertOk()
            ->assertJsonPath('data.company_snapshot.name', 'PT Penerbit')->assertJsonPath('data.total_amount', '19080.00')
            ->assertJsonPath('data.supplier_address_snapshot', "Alamat tujuan\nJakarta")
            ->assertJsonPath('data.items.0.name', 'Deskripsi pada PO')->assertJsonPath('data.items.0.tax_rate_id', $rate->id);
        $secondItem = $item->replicate();
        $secondItem->sku = 'CONNECTED-2';
        $secondItem->save();
        $payload['items'][] = [...$payload['items'][0], 'item_id' => $secondItem->id];
        $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()
            ->assertJsonPath('data.total_amount', '38160.00')->assertJsonCount(1, 'data.taxes')
            ->assertJsonPath('data.taxes.0.tax_amount', '2160.00');
        $company->delete();
        $this->getJson("/api/v1/purchase-orders/$id")->assertOk()->assertJsonPath('data.company_snapshot.name', 'PT Penerbit');
        $payload['items'][0]['unit'] = 'kg';
        $payload['company_id'] = null;
        $this->postJson('/api/v1/purchase-orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_invoice_crud_requires_positive_amount_and_consistent_supplier(): void
    {
        $id = $this->invoice();
        $this->getJson("/api/v1/invoices/$id")->assertOk()->assertJsonPath('data.status', 'unpaid');
        $this->patchJson("/api/v1/invoices/$id", ['total_amount' => '1250.50'])->assertOk()->assertJsonPath('data.total_amount', '1250.50');
        $this->patchJson("/api/v1/invoices/$id", ['total_amount' => '0'])->assertUnprocessable();
        $this->postJson('/api/v1/invoices', ['invoice_number' => 'INV-TEST', 'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-11-05', 'total_amount' => '1000.00'])->assertUnprocessable();
        $this->deleteJson("/api/v1/invoices/$id")->assertOk();
        $this->getJson("/api/v1/invoices/$id")->assertNotFound();
    }

    public function test_only_verified_payments_count_towards_settlement_and_overpayment_is_blocked(): void
    {
        $invoice = $this->invoice();
        $payload = ['invoice_id' => $invoice, 'payment_date' => '2026-11-05', 'amount' => '400.00', 'status' => 'verified'];
        $payment = $this->postJson('/api/v1/payments', $payload)->assertCreated()->assertJsonPath('data.status', 'pending_verification')->json('data.id');
        $this->getJson("/api/v1/invoices/$invoice")->assertOk()->assertJsonPath('data.paid_amount', '0.00')->assertJsonPath('data.pending_amount', '400.00');
        $this->postJson("/api/v1/payments/$payment/verify")->assertOk();
        $this->getJson("/api/v1/invoices/$invoice")->assertOk()->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.paid_amount', '400.00');
        $payload['amount'] = '600.00';
        $second = $this->postJson('/api/v1/payments', $payload)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/invoices/$invoice", ['total_amount' => '999.99'])->assertUnprocessable();
        $this->postJson("/api/v1/payments/$second/verify")->assertOk();
        $this->postJson("/api/v1/payments/$second/verify")->assertConflict();
        $this->getJson("/api/v1/invoices/$invoice")->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.remaining_amount', '0.00');
        $payload['amount'] = '0.01';
        $this->postJson('/api/v1/payments', $payload)->assertUnprocessable();
        $this->patchJson("/api/v1/payments/$payment", ['amount' => '399.00'])->assertConflict();
        $this->deleteJson("/api/v1/payments/$payment")->assertConflict();
        $this->deleteJson("/api/v1/invoices/$invoice")->assertConflict();
    }

    public function test_rejecting_or_deleting_pending_payment_releases_reserved_amount(): void
    {
        $invoice = $this->invoice();
        $payload = ['invoice_id' => $invoice, 'payment_date' => '2026-11-05', 'amount' => '1000.00'];
        $id = $this->postJson('/api/v1/payments', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payments/$id/reject", ['reason' => 'Referensi tidak cocok'])->assertOk();
        $replacement = $this->postJson('/api/v1/payments', $payload)->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/payments/$replacement")->assertOk();
        $this->getJson("/api/v1/invoices/$invoice")->assertOk()->assertJsonPath('data.available_amount', '1000.00');
    }

    public function test_due_date_uses_workspace_day_and_settlement_still_clears_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 18:00:00', 'UTC'));
        try {
            $invoice = $this->invoice();
            $this->patchJson("/api/v1/invoices/$invoice", ['due_date' => '2026-11-05'])->assertOk()
                ->assertJsonPath('data.status', 'overdue')->assertJsonPath('data.payment_status', 'unpaid');
            $payment = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice,
                'payment_date' => '2026-11-06', 'amount' => '1000.00'])->assertCreated()->json('data.id');
            $this->postJson("/api/v1/payments/$payment/verify")->assertOk();
            $this->getJson("/api/v1/invoices/$invoice")->assertOk()->assertJsonPath('data.status', 'paid')
                ->assertJsonPath('data.is_overdue', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_documents_store_files_privately_and_follow_the_required_workflow(): void
    {
        Storage::fake('local');
        $payload = ['document_type' => 'Kuitansi', 'supplier_id' => $this->supplier->id,
            'document_date' => '2026-11-05', 'file' => UploadedFile::fake()->create('kuitansi.pdf', 10, 'application/pdf')];
        $id = $this->post('/api/v1/documents', $payload, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'uploaded')->json('data.id');
        $this->getJson("/api/v1/documents/$id")->assertOk()->assertJsonMissingPath('data.file_path');
        $this->get("/api/v1/documents/$id/download")->assertOk()->assertDownload('kuitansi.pdf');
        $this->postJson("/api/v1/documents/$id/verify")->assertConflict();
        $this->postJson("/api/v1/documents/$id/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->patchJson("/api/v1/documents/$id", ['notes' => 'Tidak boleh'])->assertConflict();
        $this->deleteJson("/api/v1/documents/$id")->assertConflict();
        $this->postJson("/api/v1/documents/$id/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->postJson("/api/v1/documents/$id/verify")->assertOk()->assertJsonPath('data.status', 'verified');
        $role = Role::findOrCreate('document-no-access', 'web');
        Sanctum::actingAs(User::factory()->create(['is_active' => true])->assignRole($role), ['*']);
        $this->getJson("/api/v1/documents/$id/download")->assertForbidden();
    }

    public function test_document_without_file_cannot_be_sent_and_script_uploads_are_rejected(): void
    {
        Storage::fake('local');
        $payload = ['document_type' => 'Surat Jalan', 'supplier_id' => $this->supplier->id, 'document_date' => '2026-11-05'];
        $id = $this->postJson('/api/v1/documents', $payload)->assertCreated()->assertJsonPath('data.status', 'not_uploaded')->json('data.id');
        $this->postJson("/api/v1/documents/$id/send")->assertConflict();
        $payload['file'] = UploadedFile::fake()->create('script.html', 1, 'text/html');
        $this->post('/api/v1/documents', $payload, ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson("/api/v1/documents/$id")->assertOk();
    }

    public function test_permissions_protect_each_new_module_and_document_download(): void
    {
        $role = Role::findOrCreate('connected-reader', 'web');
        $user = User::factory()->create(['is_active' => true])->assignRole($role);
        Sanctum::actingAs($user, ['*']);
        foreach (['companies', 'invoices', 'payments', 'documents'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden();
            $this->postJson('/api/v1/'.$path, [])->assertForbidden();
        }
        $role->givePermissionTo('companies.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson('/api/v1/companies')->assertOk();
        $this->postJson('/api/v1/companies', ['name' => 'Tidak boleh'])->assertForbidden();
    }
}
