<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Item;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $values = [];

        if (is_string($this->input('sku'))) {
            $values['sku'] = Str::upper(trim($this->input('sku')));
        }

        if (is_string($this->input('name'))) {
            $values['name'] = Str::squish($this->input('name'));
        }

        if (is_string($this->input('unit'))) {
            $values['unit'] = Str::lower(trim($this->input('unit')));
        }

        $this->merge($values);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('items.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'category_id' => [
                'required', 'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
            'sku' => [
                'required', 'string', 'max:80',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('items', 'sku'),
            ],
            'name' => ['required', 'string', 'max:200'],
            'unit' => [
                'required', 'string', 'max:30',
                'regex:/^[a-z][a-z0-9.\/_-]*$/',
            ],
            'minimum_stock' => [
                'sometimes', 'required', 'numeric', 'min:0', 'decimal:0,3',
                'regex:/^\d{1,15}(?:\.\d{1,3})?$/',
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
            // Tidak menerima nilai stok atau harga terakhir dari CRUD.
            'stock_quantity' => ['prohibited'],
            'last_purchase_price' => ['prohibited'],
        ];
    }
}
