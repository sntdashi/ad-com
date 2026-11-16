<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Workforce\WorkforceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOrderTeamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof ServiceOrder
            && ($this->user()?->can('updateTeam', $order) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $selectedTechnicianIds = $this->input('technician_ids', []);

        if (! is_array($selectedTechnicianIds)) {
            $selectedTechnicianIds = [];
        }

        return [
            'technician_ids' => ['required', 'array', 'min:1', 'max:10'],
            'technician_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query
                    ->where('role', 'technician')
                    ->where('is_active', true)),
            ],
            'lead_technician_id' => [
                'required',
                'integer',
                Rule::in($selectedTechnicianIds),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $order = $this->route('order');
                if (! $order instanceof ServiceOrder) {
                    return;
                }

                $workforce = app(WorkforceService::class);
                $technicians = User::query()->whereKey($this->technicianIds())->get();

                foreach ($technicians as $technician) {
                    $conflict = $workforce->detectAssignmentConflict($technician, $order);
                    if ($conflict !== null) {
                        $validator->errors()->add('technician_ids', $conflict);
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'technician_ids.required' => 'Pilih minimal satu anggota tim teknisi.',
            'technician_ids.min' => 'Pilih minimal satu anggota tim teknisi.',
            'technician_ids.*.exists' => 'Anggota yang dipilih harus merupakan teknisi aktif.',
            'lead_technician_id.in' => 'PIC harus dipilih dari anggota tim teknisi.',
        ];
    }

    /** @return list<int> */
    public function technicianIds(): array
    {
        $technicianIds = $this->validated('technician_ids', []);

        if (! is_array($technicianIds)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $technicianId): int => (int) $technicianId,
            $technicianIds,
        ));
    }

    public function leadTechnicianId(): int
    {
        return (int) $this->validated('lead_technician_id');
    }
}
