import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { ClipboardList, Plus, Eye } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Progress } from '@/components/ui/progress';
import { toast } from 'sonner';

interface ProgramItem {
    id: string;
    code: string;
    title: string;
    description?: string | null;
    start_date?: string | null;
    end_date?: string | null;
    budget_amount: number;
    status: string;
    progress_percent: number;
    activities_count: number;
    reports_count: number;
    academic_year?: { id: string; year: string } | null;
    organization_unit?: { id: string; code: string; name: string } | null;
    pic?: { id: string; name: string } | null;
}

export default function ProgramsIndex({
    programs,
    units,
    academicYears,
    users,
    filters,
    stats,
}: {
    programs: ProgramItem[];
    units: { id: string; code: string; name: string }[];
    academicYears: { id: string; year: string; is_active: boolean }[];
    users: { id: string; name: string; role: string }[];
    filters: { status: string; unit_id: string };
    stats: { total: number; approved: number; total_budget: number };
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openCreate, setOpenCreate] = useState(false);

    const activeYear = academicYears.find(y => y.is_active);

    const form = useForm({
        code: '',
        title: '',
        description: '',
        academic_year_id: activeYear?.id ?? '',
        organization_unit_id: '',
        pic_user_id: '',
        start_date: new Date().toISOString().slice(0, 10),
        end_date: '',
        budget_amount: '0',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/program-kerja', {
            onSuccess: () => {
                setOpenCreate(false);
                form.reset();
                toast.success('Program kerja berhasil dibuat.');
            },
        });
    }

    function applyFilter(next: Partial<typeof filters>) {
        const merged = { ...filters, ...next };
        router.get('/program-kerja', {
            ...(merged.status ? { status: merged.status } : {}),
            ...(merged.unit_id ? { unit_id: merged.unit_id } : {}),
        }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Program Kerja Madrasah" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl p-2.5 bg-teal-500/10">
                        <ClipboardList className="w-5 h-5 text-teal-600" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">Program Kerja & Pelaporan Kegiatan</h1>
                        <p className="text-sm text-muted-foreground">
                            Perencanaan program tahunan madrasah, rincian kegiatan, realisasi anggaran, dan laporan pertanggungjawaban berjenjang.
                        </p>
                    </div>
                </div>
                <Button onClick={() => setOpenCreate(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Buat Program Kerja
                </Button>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Total Program Kerja</div>
                        <div className="text-2xl font-bold mt-1">{stats.total}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Disetujui / Berjalan</div>
                        <div className="text-2xl font-bold text-emerald-600 mt-1">{stats.approved}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Total Pagu Anggaran</div>
                        <div className="text-2xl font-bold text-teal-600 mt-1">
                            Rp {stats.total_budget.toLocaleString('id-ID')}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="pb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <CardTitle className="text-sm">Daftar Program Kerja ({programs.length})</CardTitle>
                        <CardDescription className="text-xs">
                            Klik Detail untuk mengelola rincian kegiatan, mengirim laporan realisasi, dan lampiran bukti.
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        <select
                            className="h-8 rounded-md border px-2 text-xs bg-background"
                            value={filters.unit_id}
                            onChange={e => applyFilter({ unit_id: e.target.value })}
                        >
                            <option value="">Semua Unit</option>
                            {units.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                        <select
                            className="h-8 rounded-md border px-2 text-xs bg-background"
                            value={filters.status}
                            onChange={e => applyFilter({ status: e.target.value })}
                        >
                            <option value="">Semua Status</option>
                            <option value="draft">Draft</option>
                            <option value="submitted">Diajukan</option>
                            <option value="verified">Diverifikasi TU</option>
                            <option value="approved">Disetujui Kamad</option>
                            <option value="completed">Selesai</option>
                        </select>
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Kode & Nama Program</th>
                                    <th className="px-4 py-2.5 font-medium">Unit & Penanggung Jawab</th>
                                    <th className="px-4 py-2.5 font-medium">Anggaran & Kegiatan</th>
                                    <th className="px-4 py-2.5 font-medium">Progres</th>
                                    <th className="px-4 py-2.5 font-medium">Status</th>
                                    <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {programs.map(p => (
                                    <tr key={p.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs font-semibold text-teal-600">{p.code}</code>
                                            <div className="font-medium mt-0.5">{p.title}</div>
                                            {p.academic_year && (
                                                <div className="text-xs text-muted-foreground">TA {p.academic_year.year}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="font-medium">{p.organization_unit?.name ?? 'Seluruh Madrasah'}</div>
                                            <div className="text-muted-foreground">PIC: {p.pic?.name ?? '—'}</div>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="font-semibold">Rp {p.budget_amount.toLocaleString('id-ID')}</div>
                                            <div className="text-muted-foreground">
                                                {p.activities_count} kegiatan • {p.reports_count} laporan
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 w-36">
                                            <div className="flex items-center justify-between text-xs mb-1">
                                                <span>Capaian</span>
                                                <span className="font-semibold">{p.progress_percent}%</span>
                                            </div>
                                            <Progress value={p.progress_percent} className="h-1.5" />
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline" className="uppercase text-[10px]">
                                                {p.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={`/program-kerja/${p.id}`}>
                                                <Button variant="outline" size="sm">
                                                    <Eye className="w-3.5 h-3.5 mr-1" /> Detail
                                                </Button>
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {programs.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-sm text-muted-foreground">
                                            Belum ada program kerja terdaftar.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Buat Program Kerja Madrasah</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-3.5">
                        <div className="space-y-1">
                            <Label>Nama Program Kerja *</Label>
                            <Input
                                value={form.data.title}
                                onChange={e => form.setData('title', e.target.value)}
                                placeholder="Contoh: Program Peningkatan Mutu Kompetensi Guru & Digitalisasi"
                                required
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Unit Kerja</Label>
                                <Select
                                    value={form.data.organization_unit_id || '__none__'}
                                    onValueChange={(v: string | null) => form.setData('organization_unit_id', !v || v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Seluruh Madrasah" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">— Seluruh Madrasah —</SelectItem>
                                        {units.map(u => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Penanggung Jawab (PIC)</Label>
                                <Select
                                    value={form.data.pic_user_id || '__none__'}
                                    onValueChange={(v: string | null) => form.setData('pic_user_id', !v || v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Pilih PIC" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">— Saya Sendiri —</SelectItem>
                                        {users.map(u => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Tanggal Mulai</Label>
                                <Input type="date" value={form.data.start_date} onChange={e => form.setData('start_date', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Pagu Anggaran (Rp)</Label>
                                <Input type="number" value={form.data.budget_amount} onChange={e => form.setData('budget_amount', e.target.value)} />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Deskripsi / Sasaran Program</Label>
                            <Input
                                value={form.data.description}
                                onChange={e => form.setData('description', e.target.value)}
                                placeholder="Sasaran dan indikator keberhasilan program..."
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpenCreate(false)}>Batal</Button>
                            <Button type="submit" disabled={form.processing}>Simpan Program</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
