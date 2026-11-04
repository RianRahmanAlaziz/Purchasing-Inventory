<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Employee\Models\Employee;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Exceptions\PurchaseOrderConflict;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\PoMoney;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tax\Models\TaxRate;
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

            $this->writeDraft($po, $data);
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
                'supplier_id' => $locked->supplier_id,
                'requested_for_employee_id' => $locked->requested_for_employee_id,
                'requested_for_department_id' => $locked->requested_for_department_id,
                'order_date' => $locked->order_date->format('Y-m-d'),
                'expected_date' => $locked->expected_date?->format('Y-m-d'),
                'notes' => $locked->notes,
                'items' => $locked->items->map(fn ($item): array => [
                    'item_id' => $item->item_id,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'notes' => $item->notes,
                ])->all(),
                'tax_rate_ids' => $locked->taxes->pluck('tax_rate_id')->all(),
            ], $data);

            $this->writeDraft($locked, $merged);
            $this->event($locked, $actorId, 'updated', 'draft', 'draft');

            return $this->load($locked);
        }, attempts: 3);
    }

    private function writeDraft(PurchaseOrder $po, array $data): void
    {
        $supplier = Supplier::query()->lockForUpdate()->findOrFail($data['supplier_id']);
        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => ['Supplier harus aktif.']]);
        }

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

        $po->fill([
            'supplier_id' => $supplier->id,
            'requested_for_employee_id' => $employee->id,
            'requested_for_department_id' => $employee->department_id,
            'supplier_name_snapshot' => $supplier->name,
            'supplier_code_snapshot' => $supplier->code,
            'order_date' => $orderDate,
            'expected_date' => $data['expected_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $itemRows = [];
        $subtotalCents = 0;
        foreach ($items as $entry) {
            $item = Item::query()->with('category')->lockForUpdate()->findOrFail($entry['item_id']);
            if (! $item->is_active || ! $item->category?->is_active) {
                throw ValidationException::withMessages(['items' => ['Barang dan kategori harus aktif.']]);
            }

            $quantity = (string) $entry['quantity'];
            $unitPrice = (string) $entry['unit_price'];
            $lineSubtotal = PoMoney::lineSubtotal($quantity, $unitPrice);
            $subtotalCents = PoMoney::add($subtotalCents, PoMoney::cents($lineSubtotal));
            $itemRows[] = [
                'item_id' => $item->id,
                'item_sku_snapshot' => $item->sku,
                'item_name_snapshot' => $item->name,
                'unit_snapshot' => $item->unit,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
                'notes' => $entry['notes'] ?? null,
            ];
        }
        $subtotal = PoMoney::format($subtotalCents);
        $addition = 0;
        $withholding = 0;
        $taxRows = [];
        foreach ($data['tax_rate_ids'] ?? [] as $rateId) {
            $rate = TaxRate::query()->with('taxType')->lockForUpdate()->findOrFail($rateId);
            if (! $rate->is_active || ! $rate->taxType?->is_active
                || $rate->effective_from->format('Y-m-d') > $orderDate
                || ($rate->effective_until !== null && $rate->effective_until->format('Y-m-d') < $orderDate)) {
                throw ValidationException::withMessages(['tax_rate_ids' => ['Tarif pajak tidak aktif/tidak berlaku pada tanggal PO.']]);
            }

            $calculated = PoMoney::tax($subtotal, (string) $rate->taxable_base_factor, (string) $rate->rate_percent);
            $taxCents = PoMoney::cents($calculated['tax']);
            if ($rate->taxType->direction === 'addition') {
                $addition = PoMoney::add($addition, $taxCents);
            } elseif ($rate->taxType->direction === 'withholding') {
                $withholding = PoMoney::add($withholding, $taxCents);
            } else {
                throw ValidationException::withMessages(['tax_rate_ids' => ['Arah pajak tidak dikenal.']]);
            }

            $taxRows[] = [
                'tax_rate_id' => $rate->id,
                'tax_code_snapshot' => $rate->taxType->code,
                'tax_name_snapshot' => $rate->taxType->name,
                'direction_snapshot' => $rate->taxType->direction,
                'rate_percent_snapshot' => $rate->rate_percent,
                'base_factor_snapshot' => $rate->taxable_base_factor,
                'taxable_base_amount' => $calculated['base'],
                'tax_amount' => $calculated['tax'],
            ];
        }

        $approvalBasis = PoMoney::add($subtotalCents, $addition);
        $grandTotal = PoMoney::subtract($approvalBasis, $withholding);
        $threshold = PoMoney::cents((string) config('purchasing.gm_approval_threshold', '7000000.00'));
        $po->fill([
            'subtotal' => $subtotal,
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
