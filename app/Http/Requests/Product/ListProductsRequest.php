<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
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
        $maxPriceRules = ['nullable', 'numeric', 'min:0', 'decimal:0,2'];

        if ($this->has('min_price') && $this->input('min_price') !== null && $this->input('min_price') !== '') {
            $maxPriceRules[] = 'gte:min_price';
        }

        return [
            'search' => ['nullable', 'string', 'max:255'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'max_price' => $maxPriceRules,
            'stock_status' => ['nullable', 'string', Rule::in(['in_stock', 'out_of_stock'])],
            'sort_by' => ['nullable', 'string', Rule::in(['id', 'title', 'price', 'available_stock', 'created_at'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.string' => 'The search parameter must be a string.',
            'search.max' => 'The search parameter must not exceed 255 characters.',
            'min_price.numeric' => 'The min price must be a number.',
            'min_price.min' => 'The min price must be at least 0.',
            'min_price.decimal' => 'The min price must have at most two decimal places.',
            'max_price.numeric' => 'The max price must be a number.',
            'max_price.min' => 'The max price must be at least 0.',
            'max_price.decimal' => 'The max price must have at most two decimal places.',
            'max_price.gte' => 'The max price must be greater than or equal to min price.',
            'stock_status.string' => 'The stock status must be a string.',
            'stock_status.in' => 'The selected stock status is invalid. Allowed values: in_stock, out_of_stock.',
            'sort_by.string' => 'The sort by parameter must be a string.',
            'sort_by.in' => 'The selected sort by field is invalid.',
            'sort_direction.string' => 'The sort direction must be a string.',
            'sort_direction.in' => 'The selected sort direction is invalid. Allowed values: asc, desc.',
            'per_page.integer' => 'The per page parameter must be an integer.',
            'per_page.min' => 'The per page parameter must be at least 1.',
            'per_page.max' => 'The per page parameter must not exceed 100.',
            'page.integer' => 'The page parameter must be an integer.',
            'page.min' => 'The page parameter must be at least 1.',
        ];
    }
}
