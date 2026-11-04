<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'po_number', 'supplier_id', 'requested_for_employee_id',
        'requested_for_department_id', 'created_by',
        'supplier_name_snapshot', 'supplier_code_snapshot',
        'order_date', 'expected_date', 'status', 'subtotal',
        'tax_addition_total', 'tax_withholding_total', 'total_amount',
        'approval_basis_amount', 'approval_threshold_amount',
        'requires_gm_approval', 'submitted_at', 'approved_at',
        'rejected_at', 'rejection_reason', 'sent_at',
        'supplier_confirmed_at', 'cancelled_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'tax_addition_total' => 'decimal:2',
            'tax_withholding_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'approval_basis_amount' => 'decimal:2',
            'approval_threshold_amount' => 'decimal:2',
            'requires_gm_approval' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'sent_at' => 'datetime',
            'supplier_confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_for_employee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requested_for_department_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(PurchaseOrderTax::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PurchaseOrderApproval::class)->orderBy('step_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PurchaseOrderEvent::class)->orderBy('id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
