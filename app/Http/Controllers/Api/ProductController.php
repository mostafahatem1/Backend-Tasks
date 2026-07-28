<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::orderBy('id', 'desc')->get();

        return apiResponse(
            data: ProductResource::collection($products),
            message: 'Products retrieved successfully.',
            status: 200
        );
    }

    public function show(Product $product): JsonResponse
    {
        return apiResponse(
            data: new ProductResource($product),
            message: 'Product retrieved successfully.',
            status: 200
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $imagePath = $request->file('image')->store('products', 'public');
        unset($validated['image']);
        $validated['image_path'] = $imagePath;

        try {
            $product = Product::create($validated);
        } catch (\Throwable $e) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $e;
        }

        return apiResponse(
            data: new ProductResource($product),
            message: 'Product created successfully.',
            status: 201
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();
        $oldImagePath = null;
        $newImagePath = null;

        if ($request->hasFile('image')) {
            $newImagePath = $request->file('image')->store('products', 'public');
            $oldImagePath = $product->image_path;
            $validated['image_path'] = $newImagePath;
            unset($validated['image']);
        }

        try {
            $product->update($validated);
        } catch (\Throwable $e) {
            if ($newImagePath) {
                Storage::disk('public')->delete($newImagePath);
            }
            throw $e;
        }

        if ($newImagePath && $oldImagePath && $oldImagePath !== $newImagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return apiResponse(
            data: new ProductResource($product->fresh()),
            message: 'Product updated successfully.',
            status: 200
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return apiResponse(
            message: 'Product deleted successfully.',
            status: 200
        );
    }
}
