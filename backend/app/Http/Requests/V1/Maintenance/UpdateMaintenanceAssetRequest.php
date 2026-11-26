<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Maintenance;

use App\Domain\Maintenance\Models\MaintenanceAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateMaintenanceAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof MaintenanceAsset
            && Gate::allows('update', $asset);
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if (is_string($this->input('asset_name'))) {
            $values['asset_name'] = Str::squish(
                $this->input('asset_name')
            );
        }

        if (is_string($this->input('serial_number'))) {
            $values['serial_number'] = trim(
                $this->input('serial_number')
            );
        }

        if (is_string($this->input('location'))) {
            $values['location'] = trim(
                $this->input('location')
            );
        }

        if (is_string($this->input('reason'))) {
            $values['reason'] = trim(
                $this->input('reason')
            );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'asset_name' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],

            'serial_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'location' => [
                'sometimes',
                'nullable',
                'string',
                'max:200',
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            'reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],

            // Tidak boleh mengubah identitas,
            // kepemilikan maupun sumber aset.
            'id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'sequence_number' => ['prohibited'],
            'asset_code' => ['prohibited'],
            'asset_type' => ['prohibited'],
            'item_id' => ['prohibited'],

            'source_goods_receipt_item_id' => [
                'prohibited',
            ],

            'receipt_unit_ordinal' => ['prohibited'],
            'registration_source' => ['prohibited'],
            'registered_by' => ['prohibited'],
            'registered_at' => ['prohibited'],
            'is_active' => ['prohibited'],

            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $editable = [
                    'asset_name',
                    'serial_number',
                    'location',
                    'notes',
                ];

                foreach ($editable as $field) {
                    if (array_key_exists($field, $this->all())) {
                        return;
                    }
                }

                $validator->errors()->add(
                    'asset_name',
                    'Minimal satu metadata aset harus dikirim.'
                );
            },
        ];
    }
}
