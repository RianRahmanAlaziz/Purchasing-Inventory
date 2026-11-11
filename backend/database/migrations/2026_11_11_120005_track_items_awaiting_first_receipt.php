<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'awaiting_first_receipt')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->boolean('awaiting_first_receipt')->default(false);
            });
        }

        // Data lama: cari SKU otomatis yang pertama kali tercatat bersama baris PO.
        $candidates = DB::table('items')->where('stock_quantity', 0)->where('sku', 'like', 'ITEM-%')
            ->whereExists(static function (Builder $query): void {
                $query->selectRaw('1')->from('purchase_order_items')
                    ->whereColumn('purchase_order_items.item_id', 'items.id')
                    ->whereColumn('purchase_order_items.item_sku_snapshot', 'items.sku')
                    ->whereColumn('purchase_order_items.created_at', 'items.created_at');
            })
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')->from('stock_movements')
                    ->whereColumn('stock_movements.item_id', 'items.id');
            });

        $candidates->select('id', 'sku')->chunkById(500, static function (Collection $items) use ($candidates): void {
            $ids = $items->filter(static function (object $item): bool {
                $suffix = substr($item->sku, strlen('ITEM-'));
                $number = str_pad((string) $item->id, 6, '0', STR_PAD_LEFT);

                return Str::isUlid($suffix) || $suffix === $number || preg_match('/^'.$number.'-[1-9]$/D', $suffix) === 1;
            })->pluck('id');

            (clone $candidates)->whereIn('id', $ids)->update(['awaiting_first_receipt' => true]);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('awaiting_first_receipt');
        });
    }
};
