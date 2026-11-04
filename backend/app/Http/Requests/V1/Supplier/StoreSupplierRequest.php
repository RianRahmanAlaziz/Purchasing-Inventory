<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Supplier;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Support\SupplierName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreSupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Jangan percaya normalized_name yang dikirim client.
        // Hapus input buatan client, baik untuk JSON maupun form/query.
        $this->request->remove('normalized_name');
        $this->query->remove('normalized_name');
        if ($this->isJson()) {
            $this->json()->remove('normalized_name');
        }
        $normalized = [];

        if (is_string($this->input('code'))) {
            $normalized['code'] = Str::upper(trim($this->input('code')));
        }

        if (is_string($this->input('name'))) {
            $normalized['name'] = SupplierName::display($this->input('name'));
        }

        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim($this->input('email')));
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('suppliers.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:40',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('suppliers', 'code'),
            ],
            'name' => [
                'required', 'string', 'max:200',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && Supplier::query()
                        ->where('normalized_name', SupplierName::normalized($value))
                        ->exists()) {
                        $fail('Nama distributor sudah digunakan.');
                    }
                },
            ],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:35'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
