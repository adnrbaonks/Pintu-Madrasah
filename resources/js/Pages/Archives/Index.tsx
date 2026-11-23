import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Archive as ArchiveIcon, Plus, FolderPlus, Eye, Lock, Users, Shield, Search, FileText, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';

interface Category {
    id: string;
    code: string;
    name: string;
    description?: string | null;
    retention_days?: number | null;
    default_visibility: 'private' | 'internal' | 'role_restricted';
    is_active: boolean;
}

interface ArchiveItem {
    id: string;
    archive_number: string;
    title: string;
    description?: string | null;
    classification_code?: string | null;
    visibility: 'private' | 'internal' | 'role_restricted';
    allowed_roles?: string[] | null;
    current_version: number;
    document_date?: string | null;
    retention_until?: string | null;
    status: 'active' | 'expired' | 'disposed';
    created_at: string;
    category?: { id: string; code: string; name: string } | null;
    organization_unit?: { id: string; code: string; name: string } | null;
    academic_year?: { id: string; year: string; semester?: string } | null;
    latest_version?: {
        id: string;
        version_number: number;
        original_name: string;
        file_size: number;
        mime_type?: string | null;
    } | null;
    creator?: { id: string; name: string } | null;
}

const ALL_ROLES = [
    { code: 'admin', label: 'Admin' },
    { code: 'kepala_madrasah', label: 'Kepala Madrasah' },
    { code: 'kaur_tu', label: 'Kepala TU' },
    { code: 'staf', label: 'Staf TU' },
    { code: 'operator', label: 'Operator' },
    { code: 'guru', label: 'Guru' },
    { code: 'pegawai', label: 'Pegawai' },
];

export default function ArchivesIndex({
    archives,
    categories,
    units,
    academicYears,
    canManage,
    filters,
    stats,
}: {
    archives: ArchiveItem[];
    categories: Category[];
    units: { id: string; code: string; name: string }[];
    academicYears: { id: string; year: string; semester?: string; is_active: boolean }[];
    canManage: boolean;
    filters: { search: string; category_id: string; visibility: string; status: string };
    stats: { total: number; private_count: number; expired_count: number };
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openCreate, setOpenCreate] = useState(false);
    const [openCategory, setOpenCategory] = useState(false);

    const activeYear = academicYears.find(y => y.is_active);

    const createForm = useForm<{
        archive_category_id: string;
        organization_unit_id: string;
        academic_year_id: string;
        archive_number: string;
        title: string;
        description: string;
        classification_code: string;
        visibility: 'private' | 'internal' | 'role_restricted';
        allowed_roles: string[];
        document_date: string;
        retention_until: string;
        file: File | null;
    }>({
        archive_category_id: categories[0]?.id ?? '',
        organization_unit_id: '',
        academic_year_id: activeYear?.id ?? '',
        archive_number: '',
        title: '',
        description: '',
        classification_code: '',
        visibility: 'internal',
        allowed_roles: ['kaur_tu', 'staf', 'operator'],
        document_date: new Date().toISOString().slice(0, 10),
        retention_until: '',
        file: null,
    });

    const categoryForm = useForm({
        code: '',
        name: '',
        description: '',
        retention_days: '1825',
        default_visibility: 'internal',
    });

    function submitArchive(e: React.FormEvent) {
        e.preventDefault();
        createForm.post('/arsip-digital', {
            forceFormData: true,
            onSuccess: () => {
                setOpenCreate(false);
                createForm.reset();
                toast.success('Arsip digital berhasil disimpan.');
            },
            onError: () => toast.error('Periksa kembali isian formulir arsip.'),
        });
    }

    function submitCategory(e: React.FormEvent) {
        e.preventDefault();
        categoryForm.post('/arsip-digital/kategori', {
            preserveScroll: true,
            onSuccess: () => {
                categoryForm.reset();
                toast.success('Kategori arsip ditambahkan.');
            },
        });
    }

    function applyFilter(next: Partial<typeof filters>) {
        const merged = { ...filters, ...next };
        router.get('/arsip-digital', {
            ...(merged.search ? { search: merged.search } : {}),
            ...(merged.category_id ? { category_id: merged.category_id } : {}),
            ...(merged.visibility ? { visibility: merged.visibility } : {}),
            ...(merged.status ? { status: merged.status } : {}),
        }, { preserveState: true, preserveScroll: true });
    }

    function toggleAllowedRole(roleCode: string) {
        const current = createForm.data.allowed_roles;
        if (current.includes(roleCode)) {
            createForm.setData('allowed_roles', current.filter(r => r !== roleCode));
        } else {
            createForm.setData('allowed_roles', [...current, roleCode]);
        }
    }

    function formatBytes(bytes?: number) {
        if (!bytes) return '0 B';
        const kb = bytes / 1024;
        if (kb < 1024) return `${kb.toFixed(1)} KB`;
        return `${(kb / 1024).toFixed(2)} MB`;
    }

    return (
        <AppLayout>
            <Head title="Arsip Digital Madrasah" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl p-2.5 bg-indigo-500/10">
                        <ArchiveIcon className="w-5 h-5 text-indigo-600" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">Arsip Digital Madrasah</h1>
                        <p className="text-sm text-muted-foreground">
                            Repositori dokumen resmi berversi dengan kontrol visibilitas privat, masa retensi, signed URL, dan jejak audit akses.
                        </p>
                    </div>
                </div>
                {canManage && (
                    <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => setOpenCategory(true)}>
                            <FolderPlus className="w-4 h-4 mr-1.5" /> Kategori ({categories.length})
                        </Button>
                        <Button onClick={() => setOpenCreate(true)}>
                            <Plus className="w-4 h-4 mr-1.5" /> Unggah Arsip
                        </Button>
                    </div>
                )}
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Total Dokumen Terjangkau</div>
                        <div className="text-2xl font-bold mt-1">{stats.total}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Arsip Privat / Terbatas</div>
                        <div className="text-2xl font-bold text-amber-600 mt-1">{stats.private_count}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Melewati Masa Retensi</div>
                        <div className="text-2xl font-bold text-rose-600 mt-1">{stats.expired_count}</div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="pb-3 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <CardTitle className="text-sm">Katalog Arsip ({archives.length})</CardTitle>
                        <CardDescription className="text-xs">
                            Seluruh unduhan menggunakan tautan bertanda tangan sementara (Temporary Signed URL 15 menit) dan tercatat di log akses.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative">
                            <Search className="w-3.5 h-3.5 absolute left-2.5 top-2.5 text-muted-foreground" />
                            <Input
                                className="h-8 pl-8 text-xs w-48"
                                placeholder="Cari judul / nomor arsip..."
                                defaultValue={filters.search}
                                onKeyDown={e => {
                                    if (e.key === 'Enter') {
                                        applyFilter({ search: (e.target as HTMLInputElement).value });
                                    }
                                }}
                            />
                        </div>
                        <select
                            className="h-8 rounded-md border px-2 text-xs bg-background"
                            value={filters.category_id}
                            onChange={e => applyFilter({ category_id: e.target.value })}
                        >
                            <option value="">Semua Kategori</option>
                            {categories.map(c => (
                                <option key={c.id} value={c.id}>{c.code} — {c.name}</option>
                            ))}
                        </select>
                        <select
                            className="h-8 rounded-md border px-2 text-xs bg-background"
                            value={filters.visibility}
                            onChange={e => applyFilter({ visibility: e.target.value })}
                        >
                            <option value="">Semua Visibilitas</option>
                            <option value="internal">Internal Madrasah</option>
                            <option value="role_restricted">Terbatas Role</option>
                            <option value="private">Privat</option>
                        </select>
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Nomor & Judul Arsip</th>
                                    <th className="px-4 py-2.5 font-medium">Kategori & Unit</th>
                                    <th className="px-4 py-2.5 font-medium">Visibilitas</th>
                                    <th className="px-4 py-2.5 font-medium">Versi & Berkas</th>
                                    <th className="px-4 py-2.5 font-medium">Retensi</th>
                                    <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {archives.map(a => (
                                    <tr key={a.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs text-indigo-600 font-semibold">{a.archive_number}</code>
                                            <div className="font-medium text-sm mt-0.5">{a.title}</div>
                                            {a.description && (
                                                <p className="text-xs text-muted-foreground line-clamp-1 mt-0.5">{a.description}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="font-medium">{a.category?.name ?? '—'}</div>
                                            <div className="text-muted-foreground">
                                                {a.organization_unit?.name ?? 'Seluruh Madrasah'}
                                                {a.academic_year ? ` • ${a.academic_year.year}` : ''}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {a.visibility === 'private' && (
                                                <Badge variant="outline" className="border-amber-500/40 text-amber-600 gap-1">
                                                    <Lock className="w-3 h-3" /> Privat
                                                </Badge>
                                            )}
                                            {a.visibility === 'role_restricted' && (
                                                <Badge variant="outline" className="border-indigo-500/40 text-indigo-600 gap-1">
                                                    <Shield className="w-3 h-3" /> Role Terbatas
                                                </Badge>
                                            )}
                                            {a.visibility === 'internal' && (
                                                <Badge variant="secondary" className="gap-1">
                                                    <Users className="w-3 h-3" /> Internal
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <Badge className="bg-emerald-500/15 text-emerald-600 border-emerald-500/20">
                                                v{a.current_version}
                                            </Badge>
                                            {a.latest_version && (
                                                <div className="text-muted-foreground mt-1 truncate max-w-[180px]" title={a.latest_version.original_name}>
                                                    <FileText className="w-3 h-3 inline mr-1" />
                                                    {a.latest_version.original_name} ({formatBytes(a.latest_version.file_size)})
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div>Tgl: {a.document_date ?? '—'}</div>
                                            <div className="text-muted-foreground">
                                                Retensi: {a.retention_until ?? 'Permanen'}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={`/arsip-digital/${a.id}`}>
                                                <Button variant="outline" size="sm">
                                                    <Eye className="w-3.5 h-3.5 mr-1" /> Detail & Unduh
                                                </Button>
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {archives.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-sm text-muted-foreground">
                                            Belum ada dokumen arsip yang ditemukan.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            {/* Dialog Unggah Arsip Baru */}
            <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Unggah Dokumen Arsip Digital</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitArchive} className="space-y-3.5">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Kategori Arsip *</Label>
                                <Select
                                    value={createForm.data.archive_category_id}
                                    onValueChange={(v: string | null) => v && createForm.setData('archive_category_id', v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Pilih Kategori" /></SelectTrigger>
                                    <SelectContent>
                                        {categories.map(c => (
                                            <SelectItem key={c.id} value={c.id}>{c.code} — {c.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {createForm.errors.archive_category_id && (
                                    <p className="text-xs text-destructive">{createForm.errors.archive_category_id}</p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <Label>Unit Kerja (Opsional)</Label>
                                <Select
                                    value={createForm.data.organization_unit_id || '__none__'}
                                    onValueChange={(v: string | null) => createForm.setData('organization_unit_id', !v || v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Seluruh Madrasah" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">— Seluruh Madrasah —</SelectItem>
                                        {units.map(u => (
                                            <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label>Judul Dokumen *</Label>
                            <Input
                                value={createForm.data.title}
                                onChange={e => createForm.setData('title', e.target.value)}
                                placeholder="Contoh: SK Pembagian Tugas Mengajar Semester Ganjil 2026/2027"
                                required
                            />
                            {createForm.errors.title && <p className="text-xs text-destructive">{createForm.errors.title}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Nomor Arsip (Kosongkan = Otomatis)</Label>
                                <Input
                                    value={createForm.data.archive_number}
                                    onChange={e => createForm.setData('archive_number', e.target.value)}
                                    placeholder="ARS/SK-KAMAD/2026/0001"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Tanggal Dokumen</Label>
                                <Input
                                    type="date"
                                    value={createForm.data.document_date}
                                    onChange={e => createForm.setData('document_date', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label>Visibilitas & Kontrol Akses *</Label>
                            <Select
                                value={createForm.data.visibility}
                                onValueChange={(v: string | null) => v && createForm.setData('visibility', v as 'private' | 'internal' | 'role_restricted')}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="internal">Internal Madrasah (Semua Pegawai/Guru Terautentikasi)</SelectItem>
                                    <SelectItem value="role_restricted">Terbatas Berdasarkan Role</SelectItem>
                                    <SelectItem value="private">Privat (Hanya Pemilik, Kamad, Kepala TU & Admin)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {createForm.data.visibility === 'role_restricted' && (
                            <div className="space-y-1.5 rounded-lg border p-3 bg-muted/30">
                                <Label className="text-xs">Role yang Diizinkan Membaca/Mengunduh:</Label>
                                <div className="flex flex-wrap gap-2">
                                    {ALL_ROLES.map(r => (
                                        <label key={r.code} className="flex items-center gap-1.5 text-xs cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={createForm.data.allowed_roles.includes(r.code)}
                                                onChange={() => toggleAllowedRole(r.code)}
                                            />
                                            <span>{r.label}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="space-y-1">
                            <Label>Ringkasan / Keterangan</Label>
                            <Input
                                value={createForm.data.description}
                                onChange={e => createForm.setData('description', e.target.value)}
                                placeholder="Catatan isi dokumen..."
                            />
                        </div>

                        <div className="space-y-1">
                            <Label>Berkas Dokumen (PDF/Word/Excel/Gambar/ZIP, Maks 20MB) *</Label>
                            <Input
                                type="file"
                                onChange={e => createForm.setData('file', e.target.files?.[0] ?? null)}
                                required
                            />
                            {createForm.errors.file && <p className="text-xs text-destructive">{createForm.errors.file}</p>}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpenCreate(false)}>Batal</Button>
                            <Button type="submit" disabled={createForm.processing}>Simpan Arsip (v1)</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog Kelola Kategori Arsip */}
            <Dialog open={openCategory} onOpenChange={setOpenCategory}>
                <DialogContent className="sm:max-w-lg max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Kategori & Kebijakan Retensi Arsip</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCategory} className="space-y-3 border-b pb-4">
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label className="text-xs">Kode Kategori *</Label>
                                <Input
                                    value={categoryForm.data.code}
                                    onChange={e => categoryForm.setData('code', e.target.value)}
                                    placeholder="SK-PANITIA"
                                    required
                                />
                            </div>
                            <div>
                                <Label className="text-xs">Masa Retensi (Hari)</Label>
                                <Input
                                    type="number"
                                    value={categoryForm.data.retention_days}
                                    onChange={e => categoryForm.setData('retention_days', e.target.value)}
                                    placeholder="1825"
                                />
                            </div>
                        </div>
                        <div>
                            <Label className="text-xs">Nama Kategori *</Label>
                            <Input
                                value={categoryForm.data.name}
                                onChange={e => categoryForm.setData('name', e.target.value)}
                                placeholder="SK Kepanitiaan Kegiatan"
                                required
                            />
                        </div>
                        <div className="flex justify-end">
                            <Button type="submit" size="sm" disabled={categoryForm.processing}>
                                <Plus className="w-3.5 h-3.5 mr-1" /> Tambah Kategori
                            </Button>
                        </div>
                    </form>

                    <div className="space-y-2 mt-2">
                        {categories.map(c => (
                            <div key={c.id} className="flex items-center justify-between border rounded-lg p-2.5 text-xs">
                                <div>
                                    <span className="font-mono font-semibold text-indigo-600">{c.code}</span> — <span className="font-medium">{c.name}</span>
                                    <div className="text-muted-foreground">
                                        Retensi: {c.retention_days ? `${c.retention_days} hari` : 'Permanen'} • Default: {c.default_visibility}
                                    </div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive"
                                    onClick={() => router.delete(`/arsip-digital/kategori/${c.id}`, { preserveScroll: true })}
                                >
                                    <Trash2 className="w-3.5 h-3.5" />
                                </Button>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
