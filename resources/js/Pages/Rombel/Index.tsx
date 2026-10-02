import AppLayout from '@/Layouts/AppLayout';
import { Head, router, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Plus, Users, Eye, Pencil, Trash2 } from 'lucide-react';

interface Rombel { id: string; name: string; grade_level: string; homeroom: string | null; student_count: number; }
interface Summary { grade: string; rombel_count: number; student_total: number; }
interface AcademicYear { id: string; year: string; semester: string; is_active: boolean; }
interface Paginated { data: Rombel[]; links: any[]; from: number; to: number; total: number; }

const GRADE_BADGE: Record<string, string> = {
    VII:  'bg-blue-50 text-blue-700 border border-blue-200',
    VIII: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
    IX:   'bg-violet-50 text-violet-700 border border-violet-200',
};
const GRADE_DOT: Record<string, string> = { VII: 'bg-blue-400', VIII: 'bg-emerald-400', IX: 'bg-violet-400' };
const GRADE_CARD: Record<string, string> = {
    VII:  'border-blue-200 hover:border-blue-300',
    VIII: 'border-emerald-200 hover:border-emerald-300',
    IX:   'border-violet-200 hover:border-violet-300',
};
const GRADE_CARD_ACTIVE: Record<string, string> = {
    VII:  'border-blue-400 bg-blue-50 ring-1 ring-blue-300',
    VIII: 'border-emerald-400 bg-emerald-50 ring-1 ring-emerald-300',
    IX:   'border-violet-400 bg-violet-50 ring-1 ring-violet-300',
};
const GRADE_ICON: Record<string, string> = {
    VII:  'bg-blue-100 text-blue-600',
    VIII: 'bg-emerald-100 text-emerald-600',
    IX:   'bg-violet-100 text-violet-600',
};

export default function Index({ rombels, summary, academicYears, filters, selectedYear }: {
    rombels: Paginated;
    summary: Summary[];
    academicYears: AcademicYear[];
    filters: any;
    selectedYear: string;
}) {
    const [gradeFilter, setGradeFilter] = useState(filters.grade || 'all');
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editRombel, setEditRombel] = useState<Rombel | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        academic_year_id: selectedYear || '',
        class_id: '',
        homeroom_teacher_id: '',
    });

    const navigate = (params: Record<string, string>) => {
        router.get('/rombel', { academic_year_id: selectedYear, grade: gradeFilter === 'all' ? '' : gradeFilter, ...params }, { preserveState: true, preserveScroll: true });
    };

    const handleYearChange = (val: string) => {
        navigate({ academic_year_id: val, grade: '' });
    };

    const handleGradeFilter = (g: string) => {
        const next = gradeFilter === g ? 'all' : g;
        setGradeFilter(next);
        navigate({ grade: next === 'all' ? '' : next });
    };

    const openCreate = () => { reset(); setIsCreateOpen(true); };

    const openEdit = (r: Rombel) => {
        setEditRombel(r);
        setData({ name: r.name, academic_year_id: selectedYear, class_id: '', homeroom_teacher_id: '' });
    };

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        post('/rombel', { onSuccess: () => { setIsCreateOpen(false); reset(); } });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editRombel) return;
        router.put(`/rombel/${editRombel.id}`, { name: data.name }, {
            onSuccess: () => setEditRombel(null),
            preserveScroll: true,
        });
    };

    const handleDelete = (r: Rombel) => {
        if (r.student_count > 0) { alert(`Rombel "${r.name}" tidak dapat dihapus karena masih memiliki ${r.student_count} siswa.`); return; }
        if (!confirm(`Hapus rombel "${r.name}"?`)) return;
        router.delete(`/rombel/${r.id}`, { preserveScroll: true });
    };

    const totalSiswa = summary.reduce((s, x) => s + x.student_total, 0);

    return (
        <AppLayout>
            <Head title="Rombongan Belajar" />
            <div className="flex flex-col gap-6">

                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Rombongan Belajar</h1>
                        <p className="text-sm text-slate-500">{rombels.total} rombel · {totalSiswa.toLocaleString('id-ID')} total siswa</p>
                    </div>
                    <Button onClick={openCreate} className="gap-2 bg-lime-600 hover:bg-lime-700 self-start sm:self-auto">
                        <Plus className="w-4 h-4" /> Tambah Rombel
                    </Button>
                </div>

                {/* Summary Cards — klik untuk filter */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    {summary.map(s => (
                        <button key={s.grade} onClick={() => handleGradeFilter(s.grade)}
                            className={`rounded-xl border p-4 text-left transition-all bg-white ${
                                gradeFilter === s.grade ? GRADE_CARD_ACTIVE[s.grade] : GRADE_CARD[s.grade]
                            } hover:shadow-md`}
                        >
                            <div className="flex items-center gap-3">
                                <div className={`w-10 h-10 rounded-lg flex items-center justify-center font-bold text-sm shrink-0 ${GRADE_ICON[s.grade] ?? 'bg-slate-100 text-slate-600'}`}>
                                    {s.grade}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs text-slate-400 font-medium">Kelas {s.grade}</p>
                                    <p className="font-bold text-slate-800">
                                        {s.rombel_count} <span className="text-xs font-normal text-slate-400">rombel</span>
                                    </p>
                                </div>
                                <div className="text-right shrink-0">
                                    <p className="text-xl font-bold text-slate-800">{s.student_total.toLocaleString('id-ID')}</p>
                                    <p className="text-xs text-slate-400">siswa</p>
                                </div>
                            </div>
                        </button>
                    ))}
                </div>

                {/* Tabel */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200">
                    {/* Toolbar */}
                    <div className="p-4 border-b border-slate-100 bg-slate-50/60 flex flex-wrap gap-3 justify-between items-center rounded-t-xl">
                        <div className="flex gap-2 items-center flex-wrap">
                            <div className="w-48">
                                <Select value={selectedYear} onValueChange={handleYearChange}>
                                    <SelectTrigger className="bg-white h-9 text-sm"><SelectValue placeholder="Tahun Ajaran" /></SelectTrigger>
                                    <SelectContent>
                                        {academicYears.map(y => (
                                            <SelectItem key={y.id} value={y.id}>{y.year} — {y.semester} {y.is_active ? '✦' : ''}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex gap-1">
                                {['all', 'VII', 'VIII', 'IX'].map(g => (
                                    <button key={g} onClick={() => handleGradeFilter(g)}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all border ${
                                            gradeFilter === g
                                                ? 'bg-slate-800 text-white border-slate-800'
                                                : 'bg-white text-slate-600 border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                                        }`}>
                                        {g === 'all' ? 'Semua' : `Kelas ${g}`}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <p className="text-xs text-slate-400">
                            {rombels.from}–{rombels.to} dari {rombels.total} rombel
                        </p>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50/40 hover:bg-slate-50/40">
                                <TableHead>Nama Rombel</TableHead>
                                <TableHead>Tingkat</TableHead>
                                <TableHead>Wali Kelas</TableHead>
                                <TableHead className="text-center">
                                    <span className="flex items-center justify-center gap-1">
                                        <Users className="w-3.5 h-3.5" /> Siswa
                                    </span>
                                </TableHead>
                                <TableHead className="text-right pr-4">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rombels.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-center py-12 text-slate-400">
                                        Tidak ada rombel ditemukan.
                                    </TableCell>
                                </TableRow>
                            ) : rombels.data.map(r => (
                                <TableRow key={r.id} className="hover:bg-slate-50/50">
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <div className={`w-2 h-2 rounded-full shrink-0 ${GRADE_DOT[r.grade_level] ?? 'bg-slate-300'}`} />
                                            <span className="font-semibold text-slate-800">{r.name}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <span className={`px-2 py-0.5 rounded text-xs font-medium ${GRADE_BADGE[r.grade_level] ?? 'bg-slate-50 text-slate-500 border border-slate-200'}`}>
                                            Kelas {r.grade_level}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-sm text-slate-500">
                                        {r.homeroom ?? <span className="italic text-slate-300 text-xs">Belum ditentukan</span>}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        <span className="font-bold text-slate-700">{r.student_count}</span>
                                        <span className="text-xs text-slate-400 ml-1">siswa</span>
                                    </TableCell>
                                    <TableCell className="text-right pr-3">
                                        <div className="flex justify-end gap-1">
                                            <Button size="sm" variant="ghost" className="h-8 w-8 p-0 text-blue-500 hover:bg-blue-50" onClick={() => router.visit(`/rombel/${r.id}`)}>
                                                <Eye className="w-3.5 h-3.5" />
                                            </Button>
                                            <Button size="sm" variant="ghost" className="h-8 w-8 p-0 text-slate-400 hover:bg-slate-100" onClick={() => openEdit(r)}>
                                                <Pencil className="w-3.5 h-3.5" />
                                            </Button>
                                            <Button size="sm" variant="ghost" className="h-8 w-8 p-0 text-red-400 hover:bg-red-50" onClick={() => handleDelete(r)}>
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>

                    {/* Pagination */}
                    {rombels.links && rombels.links.length > 3 && (
                        <div className="flex flex-col sm:flex-row items-center justify-between gap-4 px-4 py-3 border-t border-slate-100 bg-slate-50/40">
                            <p className="text-sm text-slate-500">
                                Menampilkan <span className="font-semibold">{rombels.from}</span>–<span className="font-semibold">{rombels.to}</span> dari <span className="font-semibold">{rombels.total}</span> rombel
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {rombels.links.map((link: any, i: number) => (
                                    <Link
                                        key={i}
                                        href={link.url || '#'}
                                        preserveScroll
                                        preserveState
                                        className={`min-w-[36px] h-9 px-3 flex items-center justify-center rounded-lg border text-sm font-medium transition-colors
                                            ${link.active
                                                ? 'bg-slate-800 text-white border-slate-800 cursor-default'
                                                : link.url
                                                    ? 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 hover:border-slate-300'
                                                    : 'bg-white text-slate-300 border-slate-100 cursor-not-allowed'
                                            }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Modal Tambah */}
            <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>Tambah Rombel Baru</DialogTitle></DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label>Nama Rombel</Label>
                            <Input placeholder="cth: Kelas 7J" value={data.name} onChange={e => setData('name', e.target.value)} required />
                            {errors.name && <p className="text-red-500 text-xs">{errors.name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tingkat</Label>
                            <Select value={data.class_id} onValueChange={v => setData('class_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Pilih tingkat..." /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="VII">Kelas VII</SelectItem>
                                    <SelectItem value="VIII">Kelas VIII</SelectItem>
                                    <SelectItem value="IX">Kelas IX</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.class_id && <p className="text-red-500 text-xs">{errors.class_id}</p>}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsCreateOpen(false)}>Batal</Button>
                            <Button type="submit" disabled={processing} className="bg-lime-600 hover:bg-lime-700">Simpan</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal Edit */}
            <Dialog open={!!editRombel} onOpenChange={v => !v && setEditRombel(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>Edit Rombel: {editRombel?.name}</DialogTitle></DialogHeader>
                    <form onSubmit={submitEdit} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label>Nama Rombel</Label>
                            <Input value={data.name} onChange={e => setData('name', e.target.value)} required />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditRombel(null)}>Batal</Button>
                            <Button type="submit" disabled={processing} className="bg-lime-600 hover:bg-lime-700">Simpan Perubahan</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
