<?php

namespace App\Http\Controllers;

use App\Models\ArchiveCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ArchiveCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:archive_categories,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'retention_days' => 'nullable|integer|min:1|max:36500',
            'default_visibility' => 'required|in:private,internal,role_restricted',
        ]);

        ArchiveCategory::create([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'retention_days' => $validated['retention_days'] ?? null,
            'default_visibility' => $validated['default_visibility'],
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Kategori arsip baru berhasil ditambahkan.');
    }

    public function update(Request $request, ArchiveCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:archive_categories,code,'.$category->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'retention_days' => 'nullable|integer|min:1|max:36500',
            'default_visibility' => 'required|in:private,internal,role_restricted',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'retention_days' => $validated['retention_days'] ?? null,
            'default_visibility' => $validated['default_visibility'],
            'is_active' => $validated['is_active'] ?? $category->is_active,
        ]);

        return redirect()->back()->with('success', 'Kategori arsip diperbarui.');
    }

    public function destroy(ArchiveCategory $category): RedirectResponse
    {
        if ($category->archives()->withTrashed()->exists()) {
            return redirect()->back()->withErrors([
                'category' => 'Kategori tidak dapat dihapus karena masih memiliki dokumen arsip terkait.',
            ]);
        }

        $category->delete();

        return redirect()->back()->with('success', 'Kategori arsip dihapus.');
    }
}
