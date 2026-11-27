<?php

declare(strict_types=1);

namespace App\Domain\Asset\Actions;

use App\Domain\Asset\Models\AssetEvent;
use App\Domain\Asset\Models\OfficeAsset;
use App\Domain\Company\Models\Company;
use App\Domain\Inventory\Exceptions\StockConflictException;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Support\StockDecimal;
use App\Domain\Item\Models\Item;
use App\Models\User;
use App\Support\ShortReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetWorkflow
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    /** Register physical units after the enclosing receipt/adjustment has credited the ledger. */
    public function registerUnits(Item $item, int $companyId, int $units, int $actorId, array $source = []): void
    {
        if ($item->item_type !== 'office_asset' || $units <= 0) {
            throw new StockConflictException('Registrasi aset memerlukan barang aset dan jumlah unit utuh positif.');
        }
        if (! Company::query()->whereKey($companyId)->exists()) {
            throw new StockConflictException('PT lokasi aset tidak tersedia.');
        }
        $sourceType = $source['source_type'] ?? (isset($source['goods_receipt_item_id']) ? 'goods_receipt_item'
            : (isset($source['stock_adjustment_id']) ? 'stock_adjustment' : 'registration'));
        $sourceId = $source['source_id'] ?? $source['goods_receipt_item_id'] ?? $source['stock_adjustment_id'] ?? null;

        DB::transaction(function () use ($item, $companyId, $units, $actorId, $sourceType, $sourceId): void {
            Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            for ($unit = 1; $unit <= $units; $unit++) {
                $prior = $sourceId === null ? null : OfficeAsset::query()
                    ->where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_unit', $unit)->first();
                if ($prior !== null) {
                    if ((int) $prior->item_id !== (int) $item->id) {
                        throw new StockConflictException('Referensi asal aset sudah dipakai barang lain.');
                    }

                    continue;
                }
                $asset = OfficeAsset::query()->create([
                    'asset_code' => 'AST-'.Str::ulid(), 'item_id' => $item->id, 'company_id' => $companyId,
                    'status' => 'available', 'condition' => 'good', 'source_type' => $sourceType,
                    'source_id' => $sourceId, 'source_unit' => $sourceId === null ? null : $unit,
                ]);
                ShortReference::assign($asset, 'asset_code', 'AST');
                $this->event($asset, $actorId, 'registered', null, null, null,
                    'asset:registered:'.$asset->id, null, ['source_type' => $sourceType, 'source_id' => $sourceId]);
            }
        }, attempts: 3);
    }

    /** The caller issues the line quantity through InventoryLedger in the same transaction. */
    public function issueDistribution(Item $item, int $companyId, array $assetIds, string $holder, int $actorId, int $distributionItemId): void
    {
        if ($item->item_type !== 'office_asset' || trim($holder) === '') {
            throw new StockConflictException('Pemegang aset wajib diisi untuk distribusi aset kantor.');
        }
        DB::transaction(function () use ($item, $companyId, $assetIds, $holder, $actorId, $distributionItemId): void {
            Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $line = DB::table('distribution_items')->where('id', $distributionItemId)->first();
            if ($line === null || (int) $line->item_id !== (int) $item->id
                || count($assetIds) * 1000 !== StockDecimal::parse((string) $line->quantity)) {
                throw new StockConflictException('Jumlah kode aset harus sama dengan jumlah unit pada baris distribusi.');
            }
            $assets = $this->selected($item, $assetIds);
            foreach ($assets as $asset) {
                $key = 'distribution:'.$distributionItemId.':asset:'.$asset->id;
                if (AssetEvent::query()->where('operation_key', $key)->exists()) {
                    continue;
                }
                if ((int) $asset->company_id !== $companyId || $asset->status !== 'available' || $asset->condition === 'damaged') {
                    throw new StockConflictException('Aset yang dipilih tidak tersedia di PT asal.');
                }
                $asset->update(['status' => 'in_use', 'holder_name' => trim($holder)]);
                $this->event($asset, $actorId, 'distributed', 'available', $companyId, null, $key, null,
                    ['distribution_item_id' => $distributionItemId]);
            }
        }, attempts: 3);
    }

    /** Physical identities move with the transfer, never duplicating asset codes. */
    public function transferUnits(Item $item, int $fromCompany, int $toCompany, array $assetIds, int $actorId, int $transferId, bool $receiving): void
    {
        $assets = $this->selected($item, $assetIds);
        foreach ($assets as $asset) {
            if ((int) $asset->company_id !== $fromCompany
                || $asset->status !== ($receiving ? 'in_transit' : 'available')
                || $asset->condition === 'damaged') {
                throw new StockConflictException('Identitas atau lokasi aset tidak sesuai transfer.');
            }
            if ($receiving && ! AssetEvent::query()->where('operation_key', 'transfer:'.$transferId.':sent:'.$asset->id)->exists()) {
                throw new StockConflictException('Aset tidak dikirim melalui transfer ini.');
            }
        }
        foreach ($assets as $asset) {
            $asset->update(['status' => $receiving ? 'available' : 'in_transit', 'company_id' => $receiving ? $toCompany : $fromCompany]);
            $action = $receiving ? 'transfer_received' : 'transfer_sent';
            $this->event($asset, $actorId, $action, $receiving ? 'in_transit' : 'available', $fromCompany, null,
                'transfer:'.$transferId.':'.($receiving ? 'received' : 'sent').':'.$asset->id, null,
                ['inventory_transfer_id' => $transferId, 'destination_company_id' => $toCompany]);
        }
    }

    public function transition(OfficeAsset $asset, User $actor, string $action, array $data): OfficeAsset
    {
        if (! $actor->can('assets.manage')) {
            throw new AuthorizationException;
        }
        $key = strtolower($data['operation_key']);
        $holder = isset($data['holder_name']) ? trim($data['holder_name']) : null;
        $condition = $data['condition'] ?? null;
        $fingerprint = hash('sha256', json_encode([$actor->id, $asset->id, $action, $holder, $condition, $data['serial_number'] ?? null, $data['reason'] ?? null], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($asset, $actor, $action, $data, $key, $holder, $condition, $fingerprint): OfficeAsset {
                $item = Item::query()->whereKey($asset->item_id)->lockForUpdate()->firstOrFail();
                $locked = OfficeAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
                $prior = AssetEvent::query()->where('operation_key', $key)->first();
                if ($prior !== null) {
                    $this->verifyReplay($prior, $fingerprint);

                    return $this->loaded($locked);
                }
                if ($item->item_type !== 'office_asset' || ! $item->is_active || ! Company::query()->whereKey($locked->company_id)->exists()) {
                    throw new StockConflictException('Barang atau PT aset tidak aktif.');
                }
                $fromStatus = $locked->status;
                $fromHolder = $locked->holder_name;
                $fromCondition = $locked->condition;
                $fromSerial = $locked->serial_number;
                $delta = 0;
                if ($action === 'assign') {
                    if ($locked->status !== 'available' || $locked->condition === 'damaged' || $holder === null || $holder === '') {
                        throw new StockConflictException('Hanya aset tersedia yang dapat ditugaskan kepada pemegang.');
                    }
                    $locked->fill(['status' => 'in_use', 'holder_name' => $holder]);
                    $delta = -1000;
                } elseif ($action === 'reassign') {
                    if ($locked->status !== 'in_use' || $holder === null || $holder === '' || $holder === $locked->holder_name) {
                        throw new StockConflictException('Pemindahan pemegang memerlukan aset digunakan dan nama pemegang baru.');
                    }
                    $locked->fill(['holder_name' => $holder]);
                } elseif ($action === 'return') {
                    if ($locked->status !== 'in_use' || ! in_array($condition, ['good', 'fair', 'damaged'], true)) {
                        throw new StockConflictException('Pengembalian memerlukan aset digunakan dan hasil pemeriksaan kondisi.');
                    }
                    $locked->fill(['holder_name' => null, 'condition' => $condition, 'status' => $condition === 'damaged' ? 'damaged' : 'available']);
                    $delta = $condition === 'damaged' ? 0 : 1000;
                } elseif ($action === 'condition') {
                    if (! in_array($locked->status, ['available', 'damaged'], true) || ! in_array($condition, ['good', 'fair', 'damaged'], true)) {
                        throw new StockConflictException('Periksa kondisi setelah aset dikembalikan atau saat aset tersedia.');
                    }
                    $next = $condition === 'damaged' ? 'damaged' : 'available';
                    $delta = $locked->status === $next ? 0 : ($next === 'available' ? 1000 : -1000);
                    $locked->fill(['condition' => $condition, 'status' => $next]);
                    if (array_key_exists('serial_number', $data)) {
                        $locked->serial_number = $data['serial_number'];
                    }
                } else {
                    throw new StockConflictException('Aksi aset tidak dikenal.');
                }
                if ($delta !== 0) {
                    $this->ledger->change($item, (int) $locked->company_id, $delta, (int) $actor->id, 'asset_'.$action,
                        ['asset_id' => $locked->id, 'operation_key' => 'asset:'.$key], 'Perubahan ketersediaan aset '.$locked->asset_code);
                }
                $locked->save();
                $this->event($locked, (int) $actor->id, $action, $fromStatus, (int) $locked->company_id, $fromHolder, $key, $fingerprint,
                    ['reason' => $data['reason'] ?? null, 'from_condition' => $fromCondition, 'to_condition' => $locked->condition,
                        'from_serial_number' => $fromSerial, 'to_serial_number' => $locked->serial_number]);

                return $this->loaded($locked);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $prior = AssetEvent::query()->where('operation_key', $key)->first();
            if ($prior === null) {
                throw $exception;
            }
            $this->verifyReplay($prior, $fingerprint);

            return $this->loaded($asset->refresh());
        }
    }

    private function selected(Item $item, array $assetIds): Collection
    {
        $ids = array_map('intval', $assetIds);
        if ($ids === [] || count(array_unique($ids)) !== count($ids)) {
            throw new StockConflictException('Pilih kode aset yang unik untuk setiap unit.');
        }
        $assets = OfficeAsset::query()->where('item_id', $item->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($assets->count() !== count($ids)) {
            throw new StockConflictException('Kode aset tidak sesuai dengan barang yang dipilih.');
        }

        return $assets;
    }

    private function verifyReplay(AssetEvent $event, string $fingerprint): void
    {
        if ($event->request_fingerprint === null || ! hash_equals($event->request_fingerprint, $fingerprint)) {
            throw new StockConflictException('Operation key aset sudah dipakai untuk permintaan berbeda.');
        }
    }

    private function event(OfficeAsset $asset, int $actorId, string $action, ?string $fromStatus, ?int $fromCompany, ?string $fromHolder, string $key, ?string $fingerprint = null, array $reference = []): void
    {
        $asset->events()->create([
            'actor_id' => $actorId, 'action' => $action, 'from_status' => $fromStatus, 'to_status' => $asset->status,
            'from_company_id' => $fromCompany, 'to_company_id' => $reference['destination_company_id'] ?? $asset->company_id,
            'from_holder' => $fromHolder, 'to_holder' => $asset->holder_name, 'operation_key' => $key,
            'request_fingerprint' => $fingerprint, 'reference' => $reference === [] ? null : $reference, 'created_at' => now(),
        ]);
    }

    private function loaded(OfficeAsset $asset): OfficeAsset
    {
        return $asset->load(['item:id,category_id,sku,name,unit,item_type', 'item.category:id,code,name', 'company:id,name', 'events.actor:id,name', 'events.fromCompany:id,name', 'events.toCompany:id,name']);
    }
}
