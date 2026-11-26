<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Maintenance;

use App\Domain\Maintenance\Models\MaintenanceVehicleProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveMaintenanceVehicleProfileRequest extends FormRequest
{
    private const EDITABLE = [
        'fleet_number',
        'registration_number',
        'brand',
        'model',
        'manufacture_year',
        'chassis_number',
        'engine_number',
        'meter_mode',
        'notes',
    ];

    public function authorize(): bool
    {
        if ($this->isMethod('post')) {
            return Gate::allows(
                'create',
                MaintenanceVehicleProfile::class
            );
        }

        $profile = $this->route('profile');

        return $profile instanceof MaintenanceVehicleProfile
            && Gate::allows('update', $profile);
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        foreach (
            [
                'fleet_number',
                'registration_number',
                'brand',
                'model',
                'chassis_number',
                'engine_number',
                'notes',
                'reason',
            ] as $field
        ) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            $updates[$field] = $value === ''
                && $field !== 'reason'
                ? null
                : $value;
        }

        $this->merge($updates);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'asset_id' => $creating
                ? ['required', 'integer', Rule::exists(
                    'maintenance_assets',
                    'id'
                )]
                : ['prohibited'],

            'fleet_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:60',
            ],

            'registration_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'brand' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'model' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'manufacture_year' => [
                'sometimes',
                'nullable',
                'integer',
                'between:1900,2100',
            ],

            'chassis_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'engine_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'meter_mode' => [
                $creating ? 'required' : 'sometimes',
                'string',
                Rule::in(
                    MaintenanceVehicleProfile::METER_MODES
                ),
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

            'id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'asset_code' => ['prohibited'],
            'asset_type' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $creating = $this->isMethod('post');

                $allowed = array_merge(
                    self::EDITABLE,
                    ['reason'],
                    $creating ? ['asset_id'] : []
                );

                // Jangan diam-diam mengabaikan field asing.
                foreach (array_keys($this->all()) as $key) {
                    if (! in_array($key, $allowed, true)) {
                        $validator->errors()->add(
                            $key,
                            'Field ini tidak boleh diubah.'
                        );
                    }
                }

                if ($creating) {
                    return;
                }

                foreach (self::EDITABLE as $field) {
                    if (array_key_exists($field, $this->all())) {
                        return;
                    }
                }

                $validator->errors()->add(
                    'metadata',
                    'Minimal satu field profil harus dikirim.'
                );
            },
        ];
    }
}
