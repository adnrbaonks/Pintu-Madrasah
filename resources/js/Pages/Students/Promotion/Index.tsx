import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Checkbox } from '@/components/ui/checkbox';
import { ArrowRight, GraduationCap, Loader2, AlertTriangle, UserCheck, ChevronRight, ChevronLeft } from 'lucide-react';

interface AcademicYear { id: string; year: string; semester: string; is_active: boolean; }
interface Rombel { id: string; name: string; academic_year_id: string; grade_level: string | null; }
interface Student { id: string; name: string; nisn: string; gender: string; }

const ROMAN_TO_NUM: Record<string, number> = { 'I':1, 'II':2, 'III':3, 'IV':4, 'V':5, 'VI':6, 'VII':7, 'VIII':8, 'IX':9, 'X':10, 'XI':11, 'XII':12 };

export default function Index({ academicYears, rombels }: {
    academicYears: AcademicYear[];
    rombels: Rombel[];
}) {
    // ── Source (Asal) ──────────────────────────────────
    const [srcYearId, setSrcYearId] = useState<string | null>(null);
    const [srcGrade, setSrcGrade] = useState<string | null>(null);
    const [srcRombelId, setSrcRombelId] = useState<string | null>(null);

    // ── Target (Tujuan) ────────────────────────────────
    const [tgtYearId, setTgtYearId] = useState<string | null>(null);
    const [tgtGrade, setTgtGrade] = useState<string | null>(null);
    const [tgtRombelId, setTgtRombelId] = useState<string | null>(null);

    // ── Data siswa & UI state ─────────────────────────
    const [students, setStudents] = useState<Student[]>([]);
    const [pagination, setPagination] = useState<any>(null);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState('5');
    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isLastGrade, setIsLastGrade] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [flashMessage, setFlashMessage] = useState('');

    // ── Derived: available grade levels per year ───────
    const srcGrades = [...new Set(
        rombels.filter(r => r.academic_year_id === srcYearId && r.grade_level != null)
               .map(r => r.grade_level as string)
    )].sort((a, b) => (ROMAN_TO_NUM[a] || 0) - (ROMAN_TO_NUM[b] || 0));

    const tgtGrades = [...new Set(
        rombels.filter(r => r.academic_year_id === tgtYearId && r.grade_level != null)
               .map(r => r.grade_level as string)
    )].sort((a, b) => (ROMAN_TO_NUM[a] || 0) - (ROMAN_TO_NUM[b] || 0));

    const srcRombels = rombels.filter(r =>
        r.academic_year_id === srcYearId && r.grade_level === srcGrade
    );
    const tgtRombels = rombels.filter(r =>
        r.academic_year_id === tgtYearId && r.grade_level === tgtGrade
    );

    // ── Fetch students whenever source rombel, target year, or page changes ───
    useEffect(() => {
        if (!srcRombelId) {
            setStudents([]); setSelectedIds([]); setIsLastGrade(false); setPagination(null);
            return;
        }
        setIsLoading(true); setSelectedIds([]);
        const params = new URLSearchParams({ rombel_id: srcRombelId, page: page.toString(), per_page: perPage });
        if (tgtYearId) params.append('target_academic_year_id', tgtYearId);

        fetch(`/siswa/kenaikan-kelas/students?${params}`)
            .then(r => r.json())
            .then(data => { setStudents(data.students.data); setPagination(data.students); setIsLastGrade(data.is_last_grade); })
            .finally(() => setIsLoading(false));
    }, [srcRombelId, tgtYearId, page, perPage]);

    const refreshStudents = () => {
        if (!srcRombelId) return;
        const params = new URLSearchParams({ rombel_id: srcRombelId, page: page.toString(), per_page: perPage });
        if (tgtYearId) params.append('target_academic_year_id', tgtYearId);
        fetch(`/siswa/kenaikan-kelas/students?${params}`)
            .then(r => r.json())
            .then(data => { setStudents(data.students.data); setPagination(data.students); setIsLastGrade(data.is_last_grade); });
    };

    const toggleAll = () => {
        setSelectedIds(selectedIds.length === students.length ? [] : students.map(s => s.id));
    };
    const toggleOne = (id: string) => {
        setSelectedIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
    };

    const flash = (msg: string) => {
        setFlashMessage(msg);
        setTimeout(() => setFlashMessage(''), 4000);
    };

    const handlePromote = () => {
        if (!selectedIds.length || !tgtYearId || !tgtRombelId) return;
        if (!confirm(`Naikan ${selectedIds.length} siswa ke rombel tujuan?`)) return;
        setIsSubmitting(true);
        router.post('/siswa/kenaikan-kelas/promote', {
            student_ids: selectedIds,
            target_academic_year_id: tgtYearId,
            target_rombel_id: tgtRombelId,
        }, {
            onSuccess: () => {
                flash(`${selectedIds.length} siswa berhasil dinaikkan kelasnya.`);
                setSelectedIds([]);
                setIsSubmitting(false);
                refreshStudents();
            },
            onError: () => setIsSubmitting(false),
            preserveScroll: true,
        });
    };

    const handleGraduate = () => {
        if (!selectedIds.length) return;
        if (!confirm(`Luluskan ${selectedIds.length} siswa secara permanen?`)) return;
        setIsSubmitting(true);
        router.post('/siswa/kenaikan-kelas/graduate', {
            student_ids: selectedIds,
        }, {
            onSuccess: () => {
                flash(`${selectedIds.length} siswa berhasil diluluskan.`);
                setStudents(prev => prev.filter(s => !selectedIds.includes(s.id)));
                setSelectedIds([]);
                setIsSubmitting(false);
            },
            onError: () => setIsSubmitting(false),
            preserveScroll: true,
        });
    };

    const canPromote = selectedIds.length > 0 && !!tgtYearId && !!tgtRombelId && !isSubmitting;
    const canGraduate = selectedIds.length > 0 && isLastGrade && !isSubmitting;

    return (
        <AppLayout>
            <Head title="Kenaikan Kelas Massal" />

            <div className="flex flex-col gap-5">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">Kenaikan Kelas Massal</h1>
                    <p className="text-sm text-slate-500">Pindahkan siswa antar rombel lintas tahun ajaran secara massal.</p>
                </div>

                {flashMessage && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 font-medium flex items-center gap-2">
                        <span>✅</span> {flashMessage}
                    </div>
                )}

                {/* ── Main 3-column layout: Source | Arrow | Target ─── */}
                <div className="grid grid-cols-[1fr_auto_1fr] gap-4 items-start">

                    {/* ── Panel Kiri: Sumber ─────────────────────────────── */}
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 flex flex-col">
                        {/* Header panel */}
                        <div className="px-4 py-3 border-b border-slate-100 bg-slate-50 rounded-t-xl flex items-center justify-between">
                            <p className="font-semibold text-slate-700 text-sm">📌 Sumber</p>
                            {students.length > 0 && (
                                <span className="text-xs bg-blue-100 text-blue-700 font-medium px-2 py-0.5 rounded-full">
                                    {students.length} siswa
                                </span>
                            )}
                        </div>

                        <div className="p-4 flex flex-col gap-3">
                            {/* Step 1: Tahun Ajaran */}
                            <div>
                                <label className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1 block">1. Tahun Ajaran</label>
                                <Select value={srcYearId} onValueChange={v => { setSrcYearId(v); setSrcGrade(null); setSrcRombelId(null); setPage(1); }}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Pilih tahun ajaran...">
                                            {(val: string | null) => {
                                                const y = academicYears.find(a => a.id === val);
                                                return y ? `${y.year} — ${y.semester} ${y.is_active ? '✦ Aktif' : ''}` : null;
                                            }}
                                        </SelectValue>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {academicYears.map(y => (
                                            <SelectItem key={y.id} value={y.id}>
                                                {y.year} — {y.semester} {y.is_active ? '✦ Aktif' : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Step 2: Tingkat */}
                            <div>
                                <label className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1 block">2. Tingkat</label>
                                <Select
                                    value={srcGrade}
                                    onValueChange={v => { setSrcGrade(v); setSrcRombelId(null); setPage(1); }}
                                    disabled={!srcYearId || srcGrades.length === 0}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Pilih tingkat...">
                                            {(val: string | null) => val ? `Kelas ${val}` : null}
                                        </SelectValue>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {srcGrades.map(g => (
                                            <SelectItem key={g} value={g}>
                                                Kelas {g}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Step 3: Rombel */}
                            <div>
                                <label className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1 block">3. Rombel / Kelas</label>
                                <Select value={srcRombelId} onValueChange={v => { setSrcRombelId(v); setPage(1); }} disabled={!srcGrade}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Pilih rombel...">
                                            {(val: string | null) => {
                                                const r = srcRombels.find(a => a.id === val) || rombels.find(a => a.id === val);
                                                return r ? r.name : null;
                                            }}
                                        </SelectValue>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {srcRombels.map(r => (
                                            <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {/* Tabel siswa */}
                        <div className="border-t border-slate-100 flex-1 overflow-hidden">
                            {isLoading ? (
                                <div className="flex items-center justify-center py-12 text-slate-400 gap-2">
                                    <Loader2 className="w-4 h-4 animate-spin" /> Memuat siswa...
                                </div>
                            ) : !srcRombelId ? (
                                <div className="flex flex-col items-center justify-center py-12 text-slate-300 text-sm gap-2">
                                    <GraduationCap className="w-8 h-8" />
                                    <span>Pilih rombel untuk melihat siswa</span>
                                </div>
                            ) : students.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-12 text-slate-400 text-sm gap-2">
                                    <UserCheck className="w-7 h-7 text-green-400" />
                                    <span className="text-center">Semua siswa di rombel ini<br/>sudah naik kelas.</span>
                                </div>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-slate-50/80">
                                            <TableHead className="w-10 pl-4">
                                                <Checkbox
                                                    checked={selectedIds.length === students.length && students.length > 0}
                                                    onCheckedChange={toggleAll}
                                                />
                                            </TableHead>
                                            <TableHead>Nama</TableHead>
                                            <TableHead className="w-10">L/P</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {students.map(s => (
                                            <TableRow
                                                key={s.id}
                                                className={`cursor-pointer ${selectedIds.includes(s.id) ? 'bg-blue-50 hover:bg-blue-100' : 'hover:bg-slate-50'}`}
                                                onClick={() => toggleOne(s.id)}
                                            >
                                                <TableCell className="pl-4" onClick={e => e.stopPropagation()}>
                                                    <Checkbox checked={selectedIds.includes(s.id)} onCheckedChange={() => toggleOne(s.id)} />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="font-medium text-sm leading-tight">{s.name}</div>
                                                    <div className="text-xs text-slate-400">{s.nisn || 'NISN: -'}</div>
                                                </TableCell>
                                                <TableCell className="text-sm text-center">{s.gender}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}

                            {pagination && pagination.last_page > 1 && (
                                <div className="flex flex-col sm:flex-row justify-end items-center text-sm text-slate-700 gap-8 mt-4 pt-4 border-t border-slate-100">
                                    {/* Rows per page */}
                                    <div className="flex items-center gap-3">
                                        <span className="font-medium text-slate-900">Rows per page</span>
                                        <Select value={perPage} onValueChange={(v) => { setPerPage(v); setPage(1); }}>
                                            <SelectTrigger className="w-[70px] h-8 text-sm"><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="5">5</SelectItem>
                                                <SelectItem value="15">15</SelectItem>
                                                <SelectItem value="25">25</SelectItem>
                                                <SelectItem value="50">50</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Previous / Next */}
                                    <div className="flex items-center gap-6">
                                        <button 
                                            onClick={() => setPage(p => Math.max(1, p - 1))}
                                            disabled={page === 1}
                                            className="flex items-center gap-1 font-medium hover:text-slate-900 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            <ChevronLeft className="w-4 h-4" />
                                            Previous
                                        </button>
                                        <button 
                                            onClick={() => setPage(p => Math.min(pagination.last_page, p + 1))}
                                            disabled={page === pagination.last_page}
                                            className="flex items-center gap-1 font-medium hover:text-slate-900 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            Next
                                            <ChevronRight className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            )}

                            {selectedIds.length > 0 && (
                                <div className="px-4 py-2 bg-blue-50 border-t border-blue-100 text-xs text-blue-700 font-medium">
                                    {selectedIds.length} dari {students.length} siswa dipilih
                                </div>
                            )}
                        </div>
                    </div>

                    {/* ── Pemisah Tengah (Arrow) ─────────────────────────── */}
                    <div className="flex flex-col items-center justify-start pt-14 gap-2">
                        <div className="w-8 h-8 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center">
                            <ChevronRight className="w-4 h-4 text-slate-400" />
                        </div>
                    </div>

                    {/* ── Panel Kanan: Tujuan ────────────────────────────── */}
                    <div className="flex flex-col gap-4">
                        {/* Panel tujuan naik kelas */}
                        <div className="bg-white rounded-xl shadow-sm border border-slate-200">
                            <div className="px-4 py-3 border-b border-slate-100 bg-slate-50 rounded-t-xl">
                                <p className="font-semibold text-slate-700 text-sm">🎯 Tujuan Kenaikan Kelas</p>
                            </div>
                            <div className="p-4 flex flex-col gap-3">
                                {/* Step 1 */}
                                <div>
                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1 block">1. Tahun Ajaran</label>
                                    <Select value={tgtYearId} onValueChange={v => { setTgtYearId(v); setTgtGrade(null); setTgtRombelId(null); }}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih tahun ajaran tujuan...">
                                                {(val: string | null) => {
                                                    const y = academicYears.find(a => a.id === val);
                                                    return y ? `${y.year} — ${y.semester} ${y.is_active ? '✦ Aktif' : ''}` : null;
                                                }}
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            {academicYears.map(y => (
                                                <SelectItem key={y.id} value={y.id}>
                                                    {y.year} — {y.semester} {y.is_active ? '✦ Aktif' : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Step 2: Tingkat */}
                                <div>
                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1 block">2. Tingkat</label>
                                    <Select
                                        value={tgtGrade}
                                        onValueChange={v => { setTgtGrade(v); setTgtRombelId(null); }}
                                        disabled={!tgtYearId || tgtGrades.length === 0}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih tingkat...">
                                                {(val: string | null) => val ? `Kelas ${val}` : null}
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            {tgtGrades.map(g => (
                                                <SelectItem key={g} value={g}>
                                                    Kelas {g}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Step 3: Rombel */}
                                <div>
                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1 block">3. Rombel / Kelas</label>
                                    <Select value={tgtRombelId} onValueChange={setTgtRombelId} disabled={!tgtGrade}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih rombel tujuan...">
                                                {(val: string | null) => {
                                                    const r = tgtRombels.find(a => a.id === val) || rombels.find(a => a.id === val);
                                                    return r ? r.name : null;
                                                }}
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            {tgtRombels.map(r => (
                                                <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <Button
                                    onClick={handlePromote}
                                    disabled={!canPromote}
                                    className="w-full gap-2 bg-blue-600 hover:bg-blue-700 mt-1"
                                >
                                    {isSubmitting
                                        ? <Loader2 className="w-4 h-4 animate-spin" />
                                        : <ArrowRight className="w-4 h-4" />
                                    }
                                    Proses Kenaikan {selectedIds.length > 0 ? `(${selectedIds.length} Siswa)` : ''}
                                </Button>
                            </div>
                        </div>

                        {/* Panel Kelulusan — hanya tampil jika kelas akhir */}
                        {isLastGrade && srcRombelId && (
                            <div className="bg-amber-50 rounded-xl shadow-sm border border-amber-200">
                                <div className="px-4 py-3 border-b border-amber-200 rounded-t-xl">
                                    <p className="font-semibold text-amber-800 text-sm flex items-center gap-2">
                                        <GraduationCap className="w-4 h-4" />
                                        Kelulusan — Kelas Akhir
                                    </p>
                                </div>
                                <div className="p-4 flex flex-col gap-3">
                                    <div className="flex items-start gap-2 text-xs text-amber-700 bg-amber-100/60 rounded-lg p-3">
                                        <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                                        <span>
                                            Siswa yang diluluskan akan berstatus <strong>"Lulus"</strong> secara permanen
                                            dan tidak dapat diubah ke rombel lain.
                                        </span>
                                    </div>
                                    <Button
                                        onClick={handleGraduate}
                                        disabled={!canGraduate}
                                        className="w-full gap-2 bg-amber-600 hover:bg-amber-700"
                                    >
                                        {isSubmitting
                                            ? <Loader2 className="w-4 h-4 animate-spin" />
                                            : <GraduationCap className="w-4 h-4" />
                                        }
                                        Luluskan {selectedIds.length > 0 ? `${selectedIds.length} Siswa` : 'Siswa Terpilih'}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>

                </div>{/* end grid */}
            </div>
        </AppLayout>
    );
}
