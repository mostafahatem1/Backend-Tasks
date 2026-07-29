<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxTotalRules = ['nullable', 'numeric', 'min:0', 'decimal:0,2'];
        if ($this->has('min_total') && $this->input('min_total') !== null && $this->input('min_total') !== '') {
            $maxTotalRules[] = 'gte:min_total';
        }

        $createdToRules = ['nullable', 'date_format:Y-m-d'];
        if ($this->has('created_from') && $this->input('created_from') !== null && $this->input('created_from') !== '') {
            $createdToRules[] = 'after_or_equal:created_from';
        }

        return [
            'status' => ['nullable', 'string', Rule::enum(OrderStatus::class)],
            'min_total' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'max_total' => $maxTotalRules,
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => $createdToRules,
            'sort_by' => ['nullable', 'string', Rule::in(['id', 'status', 'total_amount', 'created_at', 'updated_at'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Custom message for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.enum' => 'The selected status is invalid.',
            'status.in' => 'The selected status is invalid.',
            'min_total.numeric' => 'The min total must be a number.',
            'min_total.min' => 'The min total must be at least 0.',
            'min_total.decimal' => 'The min total must have at most two decimal places.',
            'max_total.numeric' => 'The max total must be a number.',
            'max_total.min' => 'The max total must be at least 0.',
            'max_total.decimal' => 'The max total must have at most two decimal places.',
            'max_total.gte' => 'The max total must be greater than or equal to min total.',
            'created_from.date_format' => 'The created from date does not match the format Y-m-d.',
            'created_to.date_format' => 'The created to date does not match the format Y-m-d.',
            'created_to.after_or_equal' => 'The created to date must be a date after or equal to created from.',
            'sort_by.in' => 'The selected sort by field is invalid.',
            'sort_direction.in' => 'The selected sort direction is invalid. Allowed values: asc, desc.',
            'per_page.integer' => 'The per page parameter must be an integer.',
            'per_page.min' => 'The per page parameter must be at least 1.',
            'per_page.max' => 'The per page parameter must not exceed 100.',
            'page.integer' => 'The page parameter must be an integer.',
            'page.min' => 'The page parameter must be at least 1.',
        ];
    }
}
