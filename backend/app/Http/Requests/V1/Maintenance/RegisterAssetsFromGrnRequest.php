<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Maintenance;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterAssetsFromGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasAnyRole(['super_admin', 'hr'], 'web')
            && $user->can('maintenance.assets.register-from-receipt')
            && $user->can('maintenance.assets.manage-all-companies');
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if (is_string($this->input('idempotency_key'))) {
            $updates['idempotency_key'] = strtolower(
                trim($this->input('idempotency_key'))
            );
        }

        if (is_array($this->input('units'))) {
            $units = $this->input('units');

            foreach ($units as &$unit) {
                if (! is_array($unit)) {
                    continue;
                }

                foreach (
                    [
                        'asset_name',
                        'asset_type',
                        'serial_number',
                    ] as $field
                ) {
                    if (
                        isset($unit[$field])
                        && is_string($unit[$field])
                    ) {
                        $unit[$field] = trim($unit[$field]);
                    }
                }

                if (($unit['serial_number'] ?? null) === '') {
                    $unit['serial_number'] = null;
                }
            }

            unset($unit);

            $updates['units'] = $units;
        }

        $this->merge($updates);
    }

    public function rules(): array
    {
        return [
            'goods_receipt_item_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'idempotency_key' => [
                'required',
                'uuid',
            ],

            'units' => [
                'required',
                'array',
                'list',
                'min:1',
                'max:50',
            ],

            'units.*' => [
                'required',
                'array:asset_name,asset_type,serial_number',
            ],

            'units.*.asset_name' => [
                'required',
                'string',
                'min:2',
                'max:200',
            ],

            'units.*.asset_type' => [
                'required',
                'string',
                Rule::in([
                    'vehicle',
                    'heavy_equipment',
                    'office_equipment',
                    'building',
                    'facility',
                ]),
            ],

            'units.*.serial_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'company_id' => ['prohibited'],
            'asset_code' => ['prohibited'],
            'registered_units' => ['prohibited'],
        ];
    }
}
