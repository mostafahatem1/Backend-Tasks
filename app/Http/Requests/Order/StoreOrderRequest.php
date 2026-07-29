<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get data to be validated from the request.
     */
    public function validationData(): array
    {
        $data = parent::validationData();
        $data['idempotency_key'] = $this->header('Idempotency-Key');

        return $data;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
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
            'idempotency_key.string' => 'The idempotency key must be a string.',
            'idempotency_key.max' => 'The idempotency key may not exceed 64 characters.',
            'idempotency_key.regex' => 'The idempotency key contains invalid characters.',
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.product_id.distinct' => 'Duplicate products are not allowed in the same order.',
            'items.*.product_id.exists' => 'The selected product does not exist.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
        ];
    }

    /**
     * Get the validated idempotency key from the header.
     */
    public function idempotencyKey(): ?string
    {
        $header = $this->header('Idempotency-Key');

        if (! is_string($header)) {
            return null;
        }

        $trimmed = trim($header);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Get the validated data with only product_id and quantity for items.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (isset($validated['items']) && is_array($validated['items'])) {
            $validated['items'] = array_map(function ($item) {
                return [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (int) $item['quantity'],
                ];
            }, $validated['items']);
        }

        return $key ? data_get($validated, $key, $default) : $validated;
    }
}
