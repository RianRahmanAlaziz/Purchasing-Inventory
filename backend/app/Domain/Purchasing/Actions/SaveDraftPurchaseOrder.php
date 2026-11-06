<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Employee\Models\Employee;
use App\Domain\Purchasing\Exceptions\PurchaseOrderConflict;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\PoMoney;
use App\Domain\Supplier\Support\SupplierName;
use App\Domain\Tax\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveDraftPurchaseOrder
{
    public function create(array $data, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actorId): PurchaseOrder {
            $po = new PurchaseOrder;
            $po->po_number = 'PO-'.Str::ulid();
            $po->status = 'draft';
            $po->created_by = $actorId;

            $this->writeDraft($po, $data, $actorId);
            $this->event($po, $actorId, 'created', null, 'draft');

            return $this->load($po);
        }, attempts: 3);
    }

    public function update(PurchaseOrder $po, array $data, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data, $actorId): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->getKey());
            if ($locked->status !== 'draft') {
                throw new PurchaseOrderConflict('PO hanya boleh diubah saat Draft.');
            }

            // PATCH parsial: daftar item dan pajak lama digunakan bila field tidak dikirim.
            $locked->load('items', 'taxes');
            $merged = array_merge([
                'supplier_id' => array_key_exists('supplier_name', $data) && ! array_key_exists('supplier_id', $data) ? null : $locked->supplier_id,
                'supplier_name' => isset($data['supplier_id']) && (int) $data['supplier_id'] !== (int) $locked->supplier_id ? null : $locked->supplier_name_snapshot,
                'requested_for_employee_id' => $locked->requested_for_employee_id,
                'requested_for_department_id' => $locked->requested_for_department_id,
                'order_date' => $locked->order_date->format('Y-m-d'),
                'expected_date' => $locked->expected_date?->format('Y-m-d'),
                'notes' => $locked->notes,
                'company_id' => $locked->company_id,
                'supplier_address' => (isset($data['supplier_id']) && (int) $data['supplier_id'] !== (int) $locked->supplier_id)
                    ? null : $locked->supplier_address_snapshot,
                'tax_scope' => $locked->tax_scope,
                'items' => $locked->items->map(fn ($item): array => [
                    'item_id' => $item->item_id,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'notes' => $item->notes,
                    'description' => $item->item_name_snapshot,
                    'unit' => $item->unit_snapshot,
                    'discount_percent' => (string) $item->discount_percent,
                    'tax_rate_id' => $item->tax_rate_id,
                ])->all(),
                'tax_rate_ids' => $locked->tax_scope === 'order' ? $locked->taxes->pluck('tax_rate_id')->all() : [],
            ], $data);

            $this->writeDraft($locked, $merged, $actorId);
            $this->event($locked, $actorId, 'updated', 'draft', 'draft');

            return $this->load($locked);
        }, attempts: 3);
    }

    private function writeDraft(PurchaseOrder $po, array $data, int $actorId): void
    {
        $actor = User::query()->findOrFail($actorId);
        $masters = app(ResolvePurchaseOrderMasters::class);
        $supplier = $masters->supplier($data, $actor);

        $employee = Employee::query()->with('department')
            ->lockForUpdate()->findOrFail($data['requested_for_employee_id']);
        if (! $employee->is_active || ! $employee->department?->is_active
            || (int) $employee->department_id !== (int) $data['requested_for_department_id']) {
            throw ValidationException::withMessages([
                'requested_for_department_id' => ['Departemen harus aktif dan sesuai departemen karyawan.'],
            ]);
        }

        $orderDate = $data['order_date'];
        if (isset($data['expected_date']) && $data['expected_date'] < $orderDate) {
            throw ValidationException::withMessages(['expected_date' => ['Tanggal perkiraan harus sama atau setelah tanggal PO.']]);
        }
        $items = $data['items'];
        if (count($items) === 0) {
            throw ValidationException::withMessages(['items' => ['PO minimal memiliki satu item.']]);
        }

        $taxScope = $data['tax_scope'] ?? 'order';
        if (($taxScope === 'line' && ($data['tax_rate_ids'] ?? []) !== [])
            || ($taxScope === 'order' && collect($items)->contains(fn ($entry) => isset($entry['tax_rate_id'])))) {
            throw ValidationException::withMessages(['tax_rate_ids' => ['Pilih pajak per barang atau pajak seluruh PO, bukan keduanya.']]);
        }
        $companyId = $data['company_id'] ?? null;
        $companySnapshot = null;
        if ($companyId !== null) {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $companySnapshot = (int) $po->company_id === (int) $companyId && $po->company_snapshot
                ? $po->company_snapshot
                : ['id' => $company->id, 'name' => $company->name, 'address' => $company->address ?? '',
                    'phone' => $company->phone ?? '', 'email' => $company->email ?? '', 'logo' => $company->logo ?? ''];
        }
        $po->fill([
            'company_id' => $companyId,
            'company_snapshot' => $companySnapshot,
            'supplier_address_snapshot' => $data['supplier_address'] ?? $supplier->address,
            'tax_scope' => $taxScope,
            'supplier_id' => $supplier->id,
            'requested_for_employee_id' => $employee->id,
            'requested_for_department_id' => $employee->department_id,
            'supplier_name_snapshot' => isset($data['supplier_name']) ? SupplierName::display($data['supplier_name']) : $supplier->name,
            'supplier_code_snapshot' => $supplier->code,
            'order_date' => $orderDate,
            'expected_date' => $data['expected_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $itemRows = [];
        $subtotalCents = 0;
        $discountTotal = 0;
        $taxRows = [];
        $seenItems = [];
        foreach ($items as $index => $entry) {
            $item = $masters->item($entry, $actor, $index);
            if (isset($seenItems[$item->id])) {
                throw ValidationException::withMessages(['items' => ['Barang yang sama cukup ditulis pada satu baris.']]);
            }
            $seenItems[$item->id] = true;
            if (! $item->is_active || ! $item->category?->is_active) {
                throw ValidationException::withMessages(['items' => ['Barang dan kategori harus aktif.']]);
            }

            $quantity = (string) $entry['quantity'];
            $unitPrice = (string) $entry['unit_price'];
            if (isset($entry['unit']) && $entry['unit'] !== $item->unit) {
                throw ValidationException::withMessages(['items' => ['Satuan harus sesuai master barang. Pilih barang dengan satuan yang benar.']]);
            }
            $gross = PoMoney::lineSubtotal($quantity, $unitPrice);
            $discountPercent = (string) ($entry['discount_percent'] ?? '0');
            $discount = PoMoney::tax($gross, '1', $discountPercent)['tax'];
            $discountTotal = PoMoney::add($discountTotal, PoMoney::cents($discount));
            $lineSubtotal = PoMoney::format(PoMoney::subtract(PoMoney::cents($gross), PoMoney::cents($discount)));
            $subtotalCents = PoMoney::add($subtotalCents, PoMoney::cents($lineSubtotal));
            $lineTax = null;
            if ($taxScope === 'line' && isset($entry['tax_rate_id'])) {
                $lineTax = $this->taxRow((int) $entry['tax_rate_id'], $orderDate, $lineSubtotal);
                $this->aggregateTax($taxRows, $lineTax);
            }
            $itemRows[] = [
                'item_id' => $item->id,
                'item_sku_snapshot' => $item->sku,
                'item_name_snapshot' => $entry['description'] ?? $entry['name'] ?? $item->name,
                'unit_snapshot' => $item->unit,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discount,
                'tax_rate_id' => $lineTax['tax_rate_id'] ?? null,
                'tax_label_snapshot' => $lineTax['label'] ?? null,
                'tax_direction_snapshot' => $lineTax['direction_snapshot'] ?? null,
                'tax_rate_percent_snapshot' => $lineTax['rate_percent_snapshot'] ?? null,
                'tax_base_factor_snapshot' => $lineTax['base_factor_snapshot'] ?? null,
                'taxable_base_amount' => $lineTax['taxable_base_amount'] ?? '0.00',
                'tax_amount' => $lineTax['tax_amount'] ?? '0.00',
                'notes' => $entry['notes'] ?? null,
            ];
        }
        $subtotal = PoMoney::format($subtotalCents);
        $addition = 0;
        $withholding = 0;
        if ($taxScope === 'order') {
            foreach ($data['tax_rate_ids'] ?? [] as $rateId) {
                $this->aggregateTax($taxRows, $this->taxRow((int) $rateId, $orderDate, $subtotal));
            }
        }
        foreach ($taxRows as $tax) {
            $taxCents = PoMoney::cents($tax['tax_amount']);
            if ($tax['direction_snapshot'] === 'addition') {
                $addition = PoMoney::add($addition, $taxCents);
            } elseif ($tax['direction_snapshot'] === 'withholding') {
                $withholding = PoMoney::add($withholding, $taxCents);
            } else {
                throw ValidationException::withMessages(['tax_rate_ids' => ['Arah pajak tidak dikenal.']]);
            }
        }

        $approvalBasis = PoMoney::add($subtotalCents, $addition);
        $grandTotal = PoMoney::subtract($approvalBasis, $withholding);
        $threshold = PoMoney::cents((string) config('purchasing.gm_approval_threshold', '20000000.00'));
        $po->fill([
            'subtotal' => $subtotal,
            'discount_total' => PoMoney::format($discountTotal),
            'tax_addition_total' => PoMoney::format($addition),
            'tax_withholding_total' => PoMoney::format($withholding),
            'total_amount' => PoMoney::format($grandTotal),
            'approval_basis_amount' => PoMoney::format($approvalBasis),
            'approval_threshold_amount' => PoMoney::format($threshold),
            'requires_gm_approval' => $approvalBasis > $threshold,
        ]);
        $po->save();

        // Hanya boleh dilakukan saat Draft; tidak mungkin ada GRN pada item PO ini.
        $po->items()->delete();
        $po->taxes()->delete();
        $po->items()->createMany($itemRows);
        if ($taxRows !== []) {
            $po->taxes()->createMany($taxRows);
        }
    }

    private function taxRow(int $rateId, string $orderDate, string $subtotal): array
    {
        $rate = TaxRate::query()->with('taxType')->lockForUpdate()->findOrFail($rateId);
        if (! $rate->is_active || ! $rate->taxType?->is_active
            || $rate->effective_from->format('Y-m-d') > $orderDate
            || ($rate->effective_until !== null && $rate->effective_until->format('Y-m-d') < $orderDate)) {
            throw ValidationException::withMessages(['tax_rate_ids' => ['Tarif pajak tidak aktif/tidak berlaku pada tanggal PO.']]);
        }
        $calculated = PoMoney::tax($subtotal, (string) $rate->taxable_base_factor, (string) $rate->rate_percent);

        return ['tax_rate_id' => $rate->id, 'label' => $rate->label,
            'tax_code_snapshot' => $rate->taxType->code, 'tax_name_snapshot' => $rate->taxType->name,
            'direction_snapshot' => $rate->taxType->direction, 'rate_percent_snapshot' => $rate->rate_percent,
            'base_factor_snapshot' => $rate->taxable_base_factor,
            'taxable_base_amount' => $calculated['base'], 'tax_amount' => $calculated['tax']];
    }

    private function aggregateTax(array &$rows, array $tax): void
    {
        unset($tax['label']);
        $id = $tax['tax_rate_id'];
        if (isset($rows[$id])) {
            foreach (['taxable_base_amount', 'tax_amount'] as $key) {
                $rows[$id][$key] = PoMoney::format(PoMoney::add(PoMoney::cents($rows[$id][$key]), PoMoney::cents($tax[$key])));
            }
        } else {
            $rows[$id] = $tax;
        }
    }

    private function event(PurchaseOrder $po, int $actor, string $action, ?string $from, string $to): void
    {
        $po->events()->create([
            'actor_id' => $actor, 'action' => $action, 'from_status' => $from,
            'to_status' => $to, 'created_at' => now(),
        ]);
    }

    private function load(PurchaseOrder $po): PurchaseOrder
    {
        return $po->refresh()->load(['items', 'taxes', 'approvals', 'order', 'events']);
    }
}
