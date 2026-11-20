<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Daftar semua pengguna (admin only).
     */
    public function index(Request $request): Response
    {
        $users = User::with('staff:id,name,nip')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role' => $u->role,
                'is_active' => $u->is_active,
                'staff_id' => $u->staff_id,
                'staff_name' => $u->staff?->name,
                'staff_nip' => $u->staff?->nip,
                'created_at' => $u->created_at?->format('d/m/Y'),
            ]);

        $staffOptions = Staff::orderBy('name')
            ->get(['id', 'name', 'nip'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->name} ({$s->nip})"]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'staffOptions' => $staffOptions,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Buat pengguna baru.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'staf', 'guru'])],
            'staff_id' => ['nullable', 'exists:staff,id'],
        ]);

        User::create($data);

        return back()->with('success', 'Pengguna berhasil dibuat.');
    }

    /**
     * Update data pengguna.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'staf', 'guru'])],
            'staff_id' => ['nullable', 'exists:staff,id'],
            'is_active' => ['boolean'],
        ]);

        $user->update($data);

        return back()->with('success', 'Data pengguna berhasil diperbarui.');
    }

    /**
     * Reset password pengguna.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => $request->password]); // cast 'hashed' handles bcrypt

        return back()->with('success', 'Password berhasil direset.');
    }

    /**
     * Toggle status aktif pengguna.
     */
    public function toggleActive(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'Tidak dapat menonaktifkan akun yang sedang digunakan.']);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', 'Status pengguna berhasil diubah.');
    }

    /**
     * Hapus pengguna.
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'Tidak dapat menghapus akun yang sedang digunakan.']);
        }

        $user->delete();

        return back()->with('success', 'Pengguna berhasil dihapus.');
    }
}
