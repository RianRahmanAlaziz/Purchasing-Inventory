<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\PoMoney;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveFinance
{
    public function invoice(array $data, int $actorId, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($data, $actorId, $invoice): Invoice {
            $locked = $invoice ? Invoice::query()->lockForUpdate()->findOrFail($invoice->id) : new Invoice;
            $values = array_merge($locked->only(['supplier_id', 'purchase_order_id', 'total_amount', 'invoice_number', 'notes']), [
                'invoice_date' => $locked->invoice_date?->format('Y-m-d'),
                'due_date' => $locked->due_date?->format('Y-m-d'),
            ], $data);
            if (! Supplier::query()->lockForUpdate()->findOrFail($values['supplier_id'])->is_active) {
                throw ValidationException::withMessages(['supplier_id' => ['Supplier harus aktif.']]);
            }
            if (! empty($values['purchase_order_id'])) {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($values['purchase_order_id']);
                if ((int) $po->supplier_id !== (int) $values['supplier_id'] || in_array($po->status, ['rejected', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['purchase_order_id' => ['PO harus sesuai supplier dan tidak ditolak/dibatalkan.']]);
                }
            }
            if (! empty($values['due_date']) && $values['due_date'] < $values['invoice_date']) {
                throw ValidationException::withMessages(['due_date' => ['Jatuh tempo tidak boleh sebelum tanggal tagihan.']]);
            }
            $total = PoMoney::cents((string) $values['total_amount']);
            if ($total === 0) {
                throw ValidationException::withMessages(['total_amount' => ['Total tagihan harus lebih dari nol.']]);
            }
            if ($locked->exists) {
                if ($locked->payments()->exists() && ((int) $values['supplier_id'] !== (int) $locked->supplier_id
                    || ($values['purchase_order_id'] ?? null) != $locked->purchase_order_id)) {
                    abort(409, 'Supplier dan PO tidak dapat diganti setelah ada pembayaran.');
                }
                $reserved = $this->reserved($locked);
                if ($total < $reserved) {
                    throw ValidationException::withMessages(['total_amount' => ['Total tidak boleh di bawah pembayaran terverifikasi dan yang sedang diperiksa.']]);
                }
            } else {
                $values['created_by'] = $actorId;
            }
            $locked->fill($values)->save();

            return Invoice::query()->withBalances()->findOrFail($locked->id);
        }, attempts: 3);
    }

    public function payment(array $data, int $actorId, ?Payment $payment = null): Payment
    {
        return DB::transaction(function () use ($data, $actorId, $payment): Payment {
            $invoiceId = $payment?->invoice_id ?? $data['invoice_id'];
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $locked = $payment ? Payment::query()->lockForUpdate()->findOrFail($payment->id) : new Payment;
            if ($locked->exists && $locked->status !== 'pending_verification') {
                abort(409, 'Hanya pembayaran yang belum diputuskan dapat diedit.');
            }
            if (isset($data['invoice_id']) && (int) $data['invoice_id'] !== (int) $invoiceId) {
                throw ValidationException::withMessages(['invoice_id' => ['Tagihan pembayaran tidak dapat diganti.']]);
            }
            $values = array_merge($locked->only(['payment_date', 'amount', 'reference', 'notes']), $data);
            $amount = PoMoney::cents((string) $values['amount']);
            if ($amount === 0 || PoMoney::add($this->reserved($invoice, $locked->id), $amount) > PoMoney::cents((string) $invoice->total_amount)) {
                throw ValidationException::withMessages(['amount' => ['Nominal harus lebih dari nol dan tidak melebihi sisa tagihan setelah pembayaran terverifikasi/menunggu.']]);
            }
            if (! $locked->exists) {
                $values += ['payment_number' => 'PAY-'.Str::ulid(), 'invoice_id' => $invoice->id, 'created_by' => $actorId, 'status' => 'pending_verification'];
            }
            $locked->fill($values)->save();

            return $locked->refresh()->load('invoice.supplier', 'invoice.purchaseOrder');
        }, attempts: 3);
    }

    public function decide(Payment $payment, int $actorId, bool $approve, ?string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actorId, $approve, $reason): Payment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending_verification') {
                abort(409, 'Pembayaran sudah diputuskan.');
            }
            if ($this->reserved($invoice) > PoMoney::cents((string) $invoice->total_amount)) {
                abort(409, 'Jumlah pembayaran melebihi total tagihan.');
            }
            $locked->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $actorId,
                'verified_at' => now(), 'rejection_reason' => $approve ? null : $reason]);

            return $locked->refresh()->load('invoice.supplier', 'invoice.purchaseOrder');
        }, attempts: 3);
    }

    public function deleteInvoice(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->payments()->withTrashed()->exists()) {
                abort(409, 'Tagihan dengan riwayat pembayaran tidak dapat dihapus.');
            }
            $locked->delete();
        }, attempts: 3);
    }

    public function deletePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending_verification') {
                abort(409, 'Pembayaran yang sudah diputuskan tidak dapat dihapus.');
            }
            $locked->delete();
        }, attempts: 3);
    }

    private function reserved(Invoice $invoice, ?int $except = null): int
    {
        $amounts = $invoice->payments()->whereIn('status', ['verified', 'pending_verification'])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))->pluck('amount');

        return $amounts->reduce(fn (int $sum, $amount): int => PoMoney::add($sum, PoMoney::cents((string) $amount)), 0);
    }
}
