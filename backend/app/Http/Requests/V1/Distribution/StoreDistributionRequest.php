<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Distribution;

use App\Domain\Inventory\Support\StockDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class StoreDistributionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('employee_id') && ! $this->filled('recipient_name')) {
            $mine = $this->user()?->employee()
                ->where('is_active', true)->value('id');
            if ($mine !== null) {
                $this->merge(['employee_id' => $mine]);
            }
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        if ($user->can('distribution.create')) {
            return true;
        }
        $mine = $user->employee()->where('is_active', true)->value('id');

        return $mine !== null
            && ! $this->filled('recipient_name')
            && (int) $this->input('employee_id') === (int) $mine
            && $user->tokenCan('employee:self');
    }

    public function rules(): array
    {
        return [
            'employee_id' => [
                'required_without:recipient_name', 'nullable', 'integer', Rule::exists('employees', 'id')->where('is_active', true),
            ],
            'recipient_name' => ['required_without:employee_id', 'nullable', 'string', 'max:200', 'prohibits:employee_id'],
            'purpose' => ['required', 'string', 'min:5', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('items', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => [
                'required', 'string',
                'regex:/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?$/D',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    try {
                        if (StockDecimal::parse($value) <= 0) {
                            $fail('Kuantitas harus lebih dari nol.');
                        }
                    } catch (InvalidArgumentException) {
                        $fail('Format kuantitas tidak valid.');
                    }
                },
            ],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'requested_by' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'sent_by' => ['prohibited'],
            'received_by' => ['prohibited'],
            'recipient_name_snapshot' => ['prohibited'],
        ];
    }
}
