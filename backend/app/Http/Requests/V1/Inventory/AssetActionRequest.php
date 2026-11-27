<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssetActionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['operation_key' => $this->header('Idempotency-Key') ?? $this->input('operation_key')]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('assets.manage') ?? false;
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();

        return [
            'operation_key' => ['required', 'uuid'],
            'holder_name' => [Rule::requiredIf(in_array($action, ['assign', 'reassign'], true)), 'nullable', 'string', 'max:200'],
            'condition' => [Rule::requiredIf(in_array($action, ['returnAsset', 'changeCondition'], true)), 'nullable', Rule::in(['good', 'fair', 'damaged'])],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'company_id' => ['prohibited'], 'status' => ['prohibited'], 'item_id' => ['prohibited'], 'asset_code' => ['prohibited'],
        ];
    }
}
