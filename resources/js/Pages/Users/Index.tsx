import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Users, Plus, Search, Edit2, Trash2, KeyRound,
    ShieldCheck, UserCircle, GraduationCap, ToggleLeft, ToggleRight, X
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel,
    AlertDialogContent, AlertDialogDescription, AlertDialogFooter,
    AlertDialogHeader, AlertDialogTitle
} from '@/components/ui/alert-dialog';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface UserItem {
    id: string;
    name: string;
    username: string;
    role: 'admin' | 'staf' | 'guru';
    is_active: boolean;
    staff_name: string | null;
    staff_nip: string | null;
    created_at: string;
}

interface PageProps {
    users: {
        data: UserItem[];
        links: { url: string | null; label: string; active: boolean }[];
        meta: { current_page: number; last_page: number; total: number };
        from: number;
        to: number;
        total: number;
    };
    staffOptions: { value: string; label: string }[];
    filters: { search?: string };
    flash: { success?: string; error?: string };
    auth: { user: { id: string; name: string; role: string } };
}

const roleBadge = (role: string) => {
    if (role === 'admin') return <Badge className="bg-rose-500/15 text-rose-500 border-rose-500/20 hover:bg-rose-500/20"><ShieldCheck className="w-3 h-3 mr-1" />Admin</Badge>;
    if (role === 'staf') return <Badge className="bg-blue-500/15 text-blue-500 border-blue-500/20 hover:bg-blue-500/20"><UserCircle className="w-3 h-3 mr-1" />Staf</Badge>;
    return <Badge className="bg-emerald-500/15 text-emerald-500 border-emerald-500/20 hover:bg-emerald-500/20"><GraduationCap className="w-3 h-3 mr-1" />Guru</Badge>;
};

// ── Add/Edit Modal ────────────────────────────────────────────────────────
function UserFormModal({
    open, onClose, user, staffOptions
}: {
    open: boolean;
    onClose: () => void;
    user?: UserItem | null;
    staffOptions: { value: string; label: string }[];
}) {
    const isEdit = !!user;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: user?.name ?? '',
        username: user?.username ?? '',
        password: '',
        role: user?.role ?? 'staf',
        staff_id: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (isEdit) {
            put(`/users/${user!.id}`, {
                onSuccess: () => { onClose(); reset(); },
            });
        } else {
            post('/users', {
                onSuccess: () => { onClose(); reset(); },
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={v => !v && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Pengguna' : 'Tambah Pengguna Baru'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1.5">
                        <Label>Nama Lengkap</Label>
                        <Input value={data.name} onChange={e => setData('name', e.target.value)} placeholder="Nama pengguna" />
                        {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Username / NIP</Label>
                        <Input value={data.username} onChange={e => setData('username', e.target.value)} placeholder="Username atau NIP" />
                        {errors.username && <p className="text-xs text-destructive">{errors.username}</p>}
                    </div>
                    {!isEdit && (
                        <div className="space-y-1.5">
                            <Label>Password</Label>
                            <Input type="password" value={data.password} onChange={e => setData('password', e.target.value)} placeholder="Min. 8 karakter" />
                            {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
                        </div>
                    )}
                    <div className="space-y-1.5">
                        <Label>Role</Label>
                        <Select value={data.role} onValueChange={v => setData('role', v as any)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Administrator</SelectItem>
                                <SelectItem value="staf">Staf Tata Usaha</SelectItem>
                                <SelectItem value="guru">Guru</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.role && <p className="text-xs text-destructive">{errors.role}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Batal</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan...' : isEdit ? 'Simpan Perubahan' : 'Buat Pengguna'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ── Reset Password Modal ──────────────────────────────────────────────────
function ResetPasswordModal({ open, onClose, user }: { open: boolean; onClose: () => void; user: UserItem | null }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
        password_confirmation: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(`/users/${user!.id}/reset-password`, {
            onSuccess: () => { onClose(); reset(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={v => !v && onClose()}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Reset Password</DialogTitle>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">Reset password untuk: <strong>{user?.name}</strong></p>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Password Baru</Label>
                        <Input type="password" value={data.password} onChange={e => setData('password', e.target.value)} placeholder="Min. 8 karakter" />
                        {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Konfirmasi Password</Label>
                        <Input type="password" value={data.password_confirmation} onChange={e => setData('password_confirmation', e.target.value)} placeholder="Ulangi password baru" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Batal</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Mereset...' : 'Reset Password'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────
export default function UsersIndex({ users, staffOptions, filters, flash, auth }: PageProps) {
    const [showAdd, setShowAdd] = useState(false);
    const [editUser, setEditUser] = useState<UserItem | null>(null);
    const [resetUser, setResetUser] = useState<UserItem | null>(null);
    const [deleteUser, setDeleteUser] = useState<UserItem | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');

    // Flash messages
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        router.get('/users', { search }, { preserveState: true, replace: true });
    }

    function handleDelete() {
        if (!deleteUser) return;
        router.delete(`/users/${deleteUser.id}`, {
            onFinish: () => setDeleteUser(null),
        });
    }

    function handleToggleActive(user: UserItem) {
        router.post(`/users/${user.id}/toggle-active`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Manajemen Pengguna" />

            {/* Header */}
            <div className="flex flex-col gap-1 mb-6">
                <div className="flex items-center gap-2 text-muted-foreground text-sm">
                    <Users className="w-4 h-4" />
                    <span>Keamanan &amp; Akses</span>
                </div>
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Manajemen Pengguna</h1>
                        <p className="text-muted-foreground text-sm mt-0.5">
                            Kelola akun dan hak akses pengguna sistem
                        </p>
                    </div>
                    <Button onClick={() => setShowAdd(true)} className="gap-2">
                        <Plus className="w-4 h-4" />
                        Tambah Pengguna
                    </Button>
                </div>
            </div>

            {/* Search */}
            <div className="mb-4">
                <form onSubmit={handleSearch} className="relative max-w-sm">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground pointer-events-none" />
                    <Input
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder="Cari nama atau username..."
                        className="pl-9 pr-9"
                    />
                    {search && (
                        <button
                            type="button"
                            onClick={() => { setSearch(''); router.get('/users', {}, { preserveState: true }); }}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        >
                            <X className="w-3.5 h-3.5" />
                        </button>
                    )}
                </form>
            </div>

            {/* Table */}
            <div className="bg-card border rounded-xl overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/40">
                                <th className="text-left px-4 py-3 font-semibold text-muted-foreground">Nama</th>
                                <th className="text-left px-4 py-3 font-semibold text-muted-foreground">Username</th>
                                <th className="text-left px-4 py-3 font-semibold text-muted-foreground">Role</th>
                                <th className="text-left px-4 py-3 font-semibold text-muted-foreground">Status</th>
                                <th className="text-left px-4 py-3 font-semibold text-muted-foreground">GTK Terkait</th>
                                <th className="text-left px-4 py-3 font-semibold text-muted-foreground">Dibuat</th>
                                <th className="text-right px-4 py-3 font-semibold text-muted-foreground">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="text-center py-12 text-muted-foreground">
                                        Tidak ada pengguna ditemukan.
                                    </td>
                                </tr>
                            ) : users.data.map(user => (
                                <tr key={user.id} className={cn(
                                    'border-b last:border-0 hover:bg-muted/20 transition-colors',
                                    !user.is_active && 'opacity-50'
                                )}>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{user.name}</div>
                                        {user.id === auth.user.id && (
                                            <span className="text-xs text-teal-500">(Anda)</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{user.username}</td>
                                    <td className="px-4 py-3">{roleBadge(user.role)}</td>
                                    <td className="px-4 py-3">
                                        {user.is_active
                                            ? <Badge variant="outline" className="text-emerald-500 border-emerald-500/30">Aktif</Badge>
                                            : <Badge variant="outline" className="text-muted-foreground">Nonaktif</Badge>
                                        }
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground text-xs">
                                        {user.staff_name ? (
                                            <div>
                                                <div>{user.staff_name}</div>
                                                <div className="font-mono text-xs opacity-70">{user.staff_nip}</div>
                                            </div>
                                        ) : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground text-xs">{user.created_at}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                variant="ghost" size="icon"
                                                className="h-8 w-8 text-muted-foreground hover:text-foreground"
                                                title={user.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                                onClick={() => handleToggleActive(user)}
                                                disabled={user.id === auth.user.id}
                                            >
                                                {user.is_active
                                                    ? <ToggleRight className="w-4 h-4 text-emerald-500" />
                                                    : <ToggleLeft className="w-4 h-4" />
                                                }
                                            </Button>
                                            <Button
                                                variant="ghost" size="icon"
                                                className="h-8 w-8 text-muted-foreground hover:text-foreground"
                                                title="Edit"
                                                onClick={() => setEditUser(user)}
                                            >
                                                <Edit2 className="w-4 h-4" />
                                            </Button>
                                            <Button
                                                variant="ghost" size="icon"
                                                className="h-8 w-8 text-muted-foreground hover:text-amber-500"
                                                title="Reset Password"
                                                onClick={() => setResetUser(user)}
                                            >
                                                <KeyRound className="w-4 h-4" />
                                            </Button>
                                            <Button
                                                variant="ghost" size="icon"
                                                className="h-8 w-8 text-muted-foreground hover:text-destructive"
                                                title="Hapus"
                                                onClick={() => setDeleteUser(user)}
                                                disabled={user.id === auth.user.id}
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Pagination info */}
                {users.total > 0 && (
                    <div className="px-4 py-3 border-t bg-muted/20 text-xs text-muted-foreground">
                        Menampilkan {users.from}–{users.to} dari {users.total} pengguna
                    </div>
                )}
            </div>

            {/* Modals */}
            <UserFormModal
                open={showAdd}
                onClose={() => setShowAdd(false)}
                staffOptions={staffOptions}
            />
            <UserFormModal
                open={!!editUser}
                onClose={() => setEditUser(null)}
                user={editUser}
                staffOptions={staffOptions}
            />
            <ResetPasswordModal
                open={!!resetUser}
                onClose={() => setResetUser(null)}
                user={resetUser}
            />

            {/* Delete Confirm */}
            <AlertDialog open={!!deleteUser} onOpenChange={v => !v && setDeleteUser(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus Pengguna?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Akun <strong>{deleteUser?.name}</strong> akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                            Hapus Permanen
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
