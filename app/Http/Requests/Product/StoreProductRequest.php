<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
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
        return [
            'title' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'description' => ['required', 'string'],
            'available_stock' => ['required', 'integer', 'min:0'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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
            'title.required' => 'The title field is required.',
            'price.required' => 'The price field is required.',
            'price.min' => 'The price must be at least 0.',
            'price.decimal' => 'The price must have at most two decimal places.',
            'description.required' => 'The description field is required.',
            'available_stock.required' => 'The available stock field is required.',
            'available_stock.min' => 'The available stock must be at least 0.',
            'image.required' => 'The image field is required.',
            'image.image' => 'The file must be an image.',
            'image.mimes' => 'The image must be a file of type: jpg, jpeg, png, webp.',
            'image.max' => 'The image size must not exceed 2048 kilobytes.',
        ];
    }
}
