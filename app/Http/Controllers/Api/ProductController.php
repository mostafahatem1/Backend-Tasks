<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Traits\UploadTrait;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    use UploadTrait;

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

        $imagePath = $this->uploadFile($request->file('image'), 'products');
        unset($validated['image']);
        $validated['image_path'] = $imagePath;

        try {
            $product = Product::create($validated);
        } catch (\Throwable $e) {
            $this->removeFile($imagePath);
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
            $newImagePath = $this->uploadFile($request->file('image'), 'products');
            $oldImagePath = $product->image_path;
            $validated['image_path'] = $newImagePath;
            unset($validated['image']);
        }

        try {
            $product->update($validated);
        } catch (\Throwable $e) {
            $this->removeFile($newImagePath);
            throw $e;
        }

        if ($newImagePath && $oldImagePath && $oldImagePath !== $newImagePath) {
            $this->removeFile($oldImagePath);
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
