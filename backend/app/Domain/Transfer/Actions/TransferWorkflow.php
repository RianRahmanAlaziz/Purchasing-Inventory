<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Actions;

use App\Domain\Asset\Actions\AssetWorkflow;
use App\Domain\Company\Models\Company;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use App\Domain\Transfer\Models\InventoryTransfer;
use App\Models\User;
use App\Support\ShortReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TransferWorkflow
{
    public function __construct(private readonly InventoryLedger $ledger, private readonly AssetWorkflow $assets) {}

    public function save(array $data, User $actor, ?InventoryTransfer $transfer = null): InventoryTransfer
    {
        $this->permission($actor, 'inventory.transfer.create');

        return DB::transaction(function () use ($data, $actor, $transfer): InventoryTransfer {
            if ((int) $data['from_company_id'] === (int) $data['to_company_id']) {
                throw new StockConflictException('PT asal dan tujuan harus berbeda.');
            }
            $this->company((int) $data['from_company_id']);
            $this->company((int) $data['to_company_id']);
            $locked = $transfer === null ? null : InventoryTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked !== null && $locked->status !== 'draft') {
                throw new StockConflictException('Hanya transfer Draft yang dapat diubah.');
            }
            $ids = array_column($data['items'], 'item_id');
            if ($ids === [] || count(array_unique($ids)) !== count($ids)) {
                throw new StockConflictException('Pilih minimal satu barang yang unik.');
            }
            $items = Item::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $formatted = [];
            foreach ($data['items'] as $line) {
                $item = $items->get($line['item_id']);
                $quantity = StockDecimal::parse($line['quantity']);
                $assetIds = array_map('intval', $line['asset_ids'] ?? []);
                if ($item === null || ! $item->is_active || $quantity <= 0) {
                    throw new StockConflictException('Barang transfer harus aktif dengan jumlah positif.');
                }
                if ($item->item_type === 'office_asset') {
                    if ($quantity % 1000 !== 0 || count($assetIds) * 1000 !== $quantity || count(array_unique($assetIds)) !== count($assetIds)) {
                        throw new StockConflictException('Aset kantor harus menggunakan unit utuh dengan kode aset sejumlah unit transfer.');
                    }
                } elseif ($assetIds !== []) {
                    throw new StockConflictException('Barang habis pakai tidak menggunakan kode aset.');
                }
                $formatted[] = ['item_id' => $item->id, 'quantity' => StockDecimal::format($quantity), 'asset_ids' => $assetIds === [] ? null : $assetIds];
            }
            $fields = [
                'from_company_id' => $data['from_company_id'], 'to_company_id' => $data['to_company_id'],
                'transfer_date' => $data['transfer_date'] ?? now()->toDateString(), 'notes' => $data['notes'] ?? null,
            ];
            if ($locked === null) {
                $locked = InventoryTransfer::query()->create($fields + [
                    'transfer_number' => 'TRF-'.Str::ulid(), 'created_by' => $actor->id, 'status' => 'draft',
                ]);
                ShortReference::assign($locked, 'transfer_number', 'TRF');
                $action = 'created';
            } else {
                $locked->update($fields);
                $locked->items()->delete();
                $action = 'updated';
            }
            $locked->items()->createMany($formatted);
            $this->event($locked, $actor, $action, $action === 'created' ? null : 'draft');

            return $this->loaded($locked);
        }, attempts: 3);
    }

    public function send(InventoryTransfer $transfer, User $actor): InventoryTransfer
    {
        $this->permission($actor, 'inventory.transfer.send');

        return DB::transaction(function () use ($transfer, $actor): InventoryTransfer {
            $locked = InventoryTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $lines = $locked->items()->orderBy('item_id')->get();
            if ($lines->isEmpty()) {
                throw new StockConflictException('Transfer harus memiliki barang.');
            }
            if (in_array($locked->status, ['sent', 'received'], true)) {
                $this->auditCount($lines->pluck('id')->all(), 'transfer_out');

                return $this->loaded($locked);
            }
            if ($locked->status !== 'draft') {
                throw new StockConflictException('Hanya transfer Draft yang dapat dikirim.');
            }
            $this->company((int) $locked->from_company_id);
            $this->company((int) $locked->to_company_id);
            if ((int) $locked->from_company_id === (int) $locked->to_company_id) {
                throw new StockConflictException('PT asal dan tujuan harus berbeda.');
            }
            $items = Item::query()->whereIn('id', $lines->pluck('item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $item = $items->get($line->item_id);
                $quantity = StockDecimal::parse((string) $line->quantity);
                if ($item === null || ! $item->is_active || $quantity <= 0 || $this->ledger->balance($item, (int) $locked->from_company_id) < $quantity) {
                    throw new StockConflictException('Stok PT asal tidak cukup atau barang sudah tidak aktif.');
                }
                if ($item->item_type === 'office_asset') {
                    $this->assetQuantity($line->asset_ids ?? [], $quantity);
                    $this->assets->transferUnits($item, (int) $locked->from_company_id, (int) $locked->to_company_id,
                        $line->asset_ids ?? [], (int) $actor->id, (int) $locked->id, false);
                } elseif (($line->asset_ids ?? []) !== []) {
                    throw new StockConflictException('Klasifikasi barang transfer berubah. Perbarui Draft terlebih dahulu.');
                }
                $this->ledger->change($item, (int) $locked->from_company_id, -$quantity, (int) $actor->id, 'transfer_out', [
                    'transfer_item_id' => $line->id, 'from_company_id' => $locked->from_company_id,
                    'to_company_id' => $locked->to_company_id, 'operation_key' => 'transfer:'.$locked->id.':sent:'.$line->id,
                ], 'Pengiriman transfer '.$locked->transfer_number);
            }
            $locked->update(['status' => 'sent', 'sent_by' => $actor->id, 'sent_at' => now()]);
            $this->event($locked, $actor, 'sent', 'draft');

            return $this->loaded($locked);
        }, attempts: 3);
    }

    public function receive(InventoryTransfer $transfer, User $actor): InventoryTransfer
    {
        $this->permission($actor, 'inventory.transfer.receive');

        return DB::transaction(function () use ($transfer, $actor): InventoryTransfer {
            $locked = InventoryTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $lines = $locked->items()->orderBy('item_id')->get();
            if ($lines->isEmpty()) {
                throw new StockConflictException('Transfer harus memiliki barang.');
            }
            if ($locked->status === 'received') {
                $this->auditCount($lines->pluck('id')->all(), 'transfer_out');
                $this->auditCount($lines->pluck('id')->all(), 'transfer_in');

                return $this->loaded($locked);
            }
            if ($locked->status !== 'sent' || $locked->sent_at === null) {
                throw new StockConflictException('Hanya transfer Sent yang dapat diterima.');
            }
            $this->company((int) $locked->to_company_id);
            $this->auditCount($lines->pluck('id')->all(), 'transfer_out');
            $items = Item::query()->whereIn('id', $lines->pluck('item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $item = $items->get($line->item_id);
                $quantity = StockDecimal::parse((string) $line->quantity);
                if ($quantity <= 0) {
                    throw new StockConflictException('Jumlah penerimaan transfer tidak valid.');
                }
                if ($item->item_type === 'office_asset') {
                    $this->assetQuantity($line->asset_ids ?? [], $quantity);
                    $this->assets->transferUnits($item, (int) $locked->from_company_id, (int) $locked->to_company_id,
                        $line->asset_ids ?? [], (int) $actor->id, (int) $locked->id, true);
                }
                $this->ledger->change($item, (int) $locked->to_company_id, $quantity, (int) $actor->id, 'transfer_in', [
                    'transfer_item_id' => $line->id, 'from_company_id' => $locked->from_company_id,
                    'to_company_id' => $locked->to_company_id, 'operation_key' => 'transfer:'.$locked->id.':received:'.$line->id,
                ], 'Penerimaan transfer '.$locked->transfer_number);
            }
            $locked->update(['status' => 'received', 'received_by' => $actor->id, 'received_at' => now()]);
            $this->event($locked, $actor, 'received', 'sent');

            return $this->loaded($locked);
        }, attempts: 3);
    }

    public function cancel(InventoryTransfer $transfer, User $actor): InventoryTransfer
    {
        $this->permission($actor, 'inventory.transfer.create');

        return DB::transaction(function () use ($transfer, $actor): InventoryTransfer {
            $locked = InventoryTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return $this->loaded($locked);
            }
            if ($locked->status !== 'draft') {
                throw new StockConflictException('Transfer terkirim tidak boleh dibatalkan. Terima dahulu, lalu buat transfer pengembalian yang diaudit.');
            }
            $locked->update(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);
            $this->event($locked, $actor, 'cancelled', 'draft');

            return $this->loaded($locked);
        }, attempts: 3);
    }

    private function assetQuantity(array $ids, int $quantity): void
    {
        if ($quantity % 1000 !== 0 || count($ids) * 1000 !== $quantity || count(array_unique($ids)) !== count($ids)) {
            throw new StockConflictException('Jumlah unit aset harus sama dengan kode aset terpilih.');
        }
    }

    private function company(int $id): void
    {
        if (Company::query()->whereKey($id)->sharedLock()->first() === null) {
            throw new StockConflictException('PT transfer tidak tersedia.');
        }
    }

    private function auditCount(array $ids, string $type): void
    {
        if (StockMovement::query()->whereIn('transfer_item_id', $ids)->where('movement_type', $type)->count() !== count($ids)) {
            throw new StockConflictException('Status transfer tidak sesuai audit mutasi stok.');
        }
    }

    private function permission(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException;
        }
    }

    private function event(InventoryTransfer $transfer, User $actor, string $action, ?string $from): void
    {
        $transfer->events()->create(['actor_id' => $actor->id, 'action' => $action, 'from_status' => $from,
            'to_status' => $transfer->status, 'notes' => $transfer->notes, 'created_at' => now()]);
    }

    private function loaded(InventoryTransfer $transfer): InventoryTransfer
    {
        return $transfer->refresh()->load(['fromCompany:id,name', 'toCompany:id,name', 'items.item:id,sku,name,unit,item_type', 'events.actor:id,name']);
    }
}
