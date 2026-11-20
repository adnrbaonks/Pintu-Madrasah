import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useState, useRef, useCallback } from 'react';
import { FileText, ChevronDown, Save, Check, X as XIcon, Settings as SettingsIcon } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface Subject {
    id: number;
    code: string;
    name: string;
    kkm: number;
    type: string;
}

interface GradeCell {
    id?: number;
    nilai_p1?: number | null;
    nilai_p2?: number | null;
    nilai_sts?: number | null;
    nilai_sas?: number | null;
    nilai_akhir?: number | null;
    predikat?: string | null;
}

interface SiswaRow {
    student_id: string;
    name: string;
    nisn: string | null;
    attendance_number: number | null;
    nilai: Record<number, GradeCell>;
}

interface LegerData {
    rombel: string;
    subjects: Subject[];
    siswa: SiswaRow[];
    semester: string;
}

interface GradingFormulaData {
    id?: string;
    academic_year_id?: string;
    weight_p1: number;
    weight_p2: number;
    weight_sts: number;
    weight_sas: number;
    threshold_a: number;
    threshold_b: number;
    threshold_c: number;
    notes?: string | null;
}

interface Props {
    rombels: { value: string; label: string }[];
    selectedRombelId?: string;
    selectedSemester: string;
    tahunAjaran: string;
    academicYearId?: string;
    formula?: GradingFormulaData | null;
    leger: LegerData | null;
    flash: { success?: string; error?: string };
}

// Input nilai yang bisa di-edit inline
function NilaiInput({ value, onSave }: {
    value: number | null | undefined;
    onSave: (v: number | null) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [local, setLocal] = useState(value?.toString() ?? '');
    const inputRef = useRef<HTMLInputElement>(null);

    function commit() {
        const num = local === '' ? null : parseFloat(local);
        if (num !== null && (num < 0 || num > 100)) {
            toast.error('Nilai harus antara 0–100');
            return;
        }
        onSave(num);
        setEditing(false);
    }

    if (!editing) {
        return (
            <button
                onClick={() => { setEditing(true); setLocal(value?.toString() ?? ''); setTimeout(() => inputRef.current?.select(), 10); }}
                className="w-12 h-7 text-center text-xs rounded hover:bg-accent hover:text-accent-foreground transition-colors"
            >
                {value != null ? value : <span className="text-muted-foreground">—</span>}
            </button>
        );
    }

    return (
        <input
            ref={inputRef}
            type="number"
            min={0}
            max={100}
            step={0.5}
            value={local}
            onChange={e => setLocal(e.target.value)}
            onBlur={commit}
            onKeyDown={e => { if (e.key === 'Enter') commit(); if (e.key === 'Escape') setEditing(false); }}
            className="w-14 h-7 text-center text-xs border border-primary rounded focus:outline-none focus:ring-1 focus:ring-primary px-1 bg-background"
            autoFocus
        />
    );
}

// Predikat badge
function PredikatBadge({ predikat }: { predikat?: string | null }) {
    if (!predikat) return <span className="text-muted-foreground text-xs">—</span>;
    const colors: Record<string, string> = {
        A: 'bg-emerald-500/15 text-emerald-600 border-emerald-500/20',
        B: 'bg-blue-500/15 text-blue-600 border-blue-500/20',
        C: 'bg-amber-500/15 text-amber-600 border-amber-500/20',
        D: 'bg-rose-500/15 text-rose-600 border-rose-500/20',
    };
    return <Badge variant="outline" className={cn('text-xs px-1.5', colors[predikat] ?? '')}>{predikat}</Badge>;
}

export default function LegerIndex({ rombels, selectedRombelId, selectedSemester, tahunAjaran, academicYearId, formula, leger, flash }: Props) {
    const [saving, setSaving] = useState<string | null>(null); // "studentId_subjectId"
    const [formulaOpen, setFormulaOpen] = useState(false);
    const [formulaData, setFormulaData] = useState({
        weight_p1: formula?.weight_p1 ?? 20,
        weight_p2: formula?.weight_p2 ?? 20,
        weight_sts: formula?.weight_sts ?? 30,
        weight_sas: formula?.weight_sas ?? 30,
        threshold_a: formula?.threshold_a ?? 90,
        threshold_b: formula?.threshold_b ?? 80,
        threshold_c: formula?.threshold_c ?? 70,
    });
    const [savingFormula, setSavingFormula] = useState(false);

    function submitFormula(e: React.FormEvent) {
        e.preventDefault();
        const total = Number(formulaData.weight_p1) + Number(formulaData.weight_p2) + Number(formulaData.weight_sts) + Number(formulaData.weight_sas);
        if (Math.abs(total - 100) > 0.01) {
            toast.error(`Total bobot harus 100%. Saat ini: ${total}%`);
            return;
        }
        setSavingFormula(true);
        router.post('/kelas/leger/formula', {
            academic_year_id: academicYearId,
            ...formulaData,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Formula penilaian berhasil disimpan.');
                setFormulaOpen(false);
            },
            onError: () => toast.error('Gagal menyimpan formula.'),
            onFinish: () => setSavingFormula(false),
        });
    }

    if (flash?.success) toast.success(flash.success);
    if (flash?.error)   toast.error(flash.error);

    function handleRombelChange(val: string) {
        router.get('/kelas/leger', { rombel_id: val, semester: selectedSemester }, { preserveState: false });
    }

    function handleSemesterChange(val: string) {
        router.get('/kelas/leger', { rombel_id: selectedRombelId, semester: val }, { preserveState: false });
    }

    const saveGrade = useCallback(async (
        studentId: string,
        subjectId: number,
        field: keyof Pick<GradeCell, 'nilai_p1' | 'nilai_p2' | 'nilai_sts' | 'nilai_sas'>,
        value: number | null,
        row: SiswaRow,
    ) => {
        const key = `${studentId}_${subjectId}`;
        setSaving(key);
        try {
            const activeYear = (document.querySelector('meta[name="academic-year-id"]') as HTMLMetaElement)?.content;
            const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const existing = row.nilai[subjectId] ?? {};

            const body = {
                student_id:       studentId,
                subject_id:       subjectId,
                rombel_id:        selectedRombelId,
                academic_year_id: activeYear,
                semester:         selectedSemester,
                nilai_p1:         existing.nilai_p1,
                nilai_p2:         existing.nilai_p2,
                nilai_sts:        existing.nilai_sts,
                nilai_sas:        existing.nilai_sas,
                [field]:          value,
            };

            const res = await fetch('/kelas/leger/grade', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            if (!res.ok) throw new Error('Gagal menyimpan.');
            const saved = await res.json();

            // Update local state via Inertia reload (simple approach)
            router.reload({ only: ['leger'], preserveScroll: true });
        } catch (err) {
            toast.error('Gagal menyimpan nilai.');
        } finally {
            setSaving(null);
        }
    }, [selectedRombelId, selectedSemester]);

    return (
        <AppLayout>
            <Head title="Leger Nilai" />

            {/* Header */}
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                        <FileText className="w-4 h-4" />
                        <span>Administrasi Kelas</span>
                    </div>
                    <h1 className="text-2xl font-bold">Leger Nilai</h1>
                    <p className="text-sm text-muted-foreground mt-0.5">Rekap nilai siswa per kelas — Tahun Ajaran {tahunAjaran}</p>
                </div>
                {academicYearId && (
                    <Button variant="outline" size="sm" onClick={() => setFormulaOpen(!formulaOpen)} className="gap-2">
                        <SettingsIcon className="w-4 h-4" />
                        Formula Penilaian ({formula?.weight_p1 ?? 20}:{formula?.weight_p2 ?? 20}:{formula?.weight_sts ?? 30}:{formula?.weight_sas ?? 30})
                    </Button>
                )}
            </div>

            {/* Formula Setting Panel */}
            {formulaOpen && (
                <Card className="mb-5 border-primary/30 bg-primary/5">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base flex items-center justify-between">
                            <span>Atur Formula Penilaian (Tahun Ajaran {tahunAjaran})</span>
                            <Button variant="ghost" size="sm" className="h-7 w-7 p-0" onClick={() => setFormulaOpen(false)}>
                                <XIcon className="w-4 h-4" />
                            </Button>
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Bobot komponen formatif dan sumatif serta ambang batas predikat untuk perhitungan nilai akhir.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submitFormula} className="space-y-4">
                            <div>
                                <h4 className="text-xs font-semibold mb-2">Bobot Nilai (Total Wajib 100%)</h4>
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                    <div className="space-y-1">
                                        <Label className="text-xs">Formatif 1 (P1) %</Label>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={formulaData.weight_p1}
                                            onChange={e => setFormulaData({ ...formulaData, weight_p1: Number(e.target.value) })}
                                            className="w-full text-sm border rounded px-2.5 py-1.5 bg-background"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Formatif 2 (P2) %</Label>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={formulaData.weight_p2}
                                            onChange={e => setFormulaData({ ...formulaData, weight_p2: Number(e.target.value) })}
                                            className="w-full text-sm border rounded px-2.5 py-1.5 bg-background"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">STS (Tengah Sem.) %</Label>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={formulaData.weight_sts}
                                            onChange={e => setFormulaData({ ...formulaData, weight_sts: Number(e.target.value) })}
                                            className="w-full text-sm border rounded px-2.5 py-1.5 bg-background"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">SAS (Akhir Sem.) %</Label>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={formulaData.weight_sas}
                                            onChange={e => setFormulaData({ ...formulaData, weight_sas: Number(e.target.value) })}
                                            className="w-full text-sm border rounded px-2.5 py-1.5 bg-background"
                                            required
                                        />
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 className="text-xs font-semibold mb-2">Ambang Batas Predikat</h4>
                                <div className="grid grid-cols-3 gap-3">
                                    <div className="space-y-1">
                                        <Label className="text-xs">Batas Nilai A (≥)</Label>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={formulaData.threshold_a}
                                            onChange={e => setFormulaData({ ...formulaData, threshold_a: Number(e.target.value) })}
                                            className="w-full text-sm border rounded px-2.5 py-1.5 bg-background"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Batas Nilai B (≥)</Label>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={formulaData.threshold_b}
                                            onChange={e => setFormulaData({ ...formulaData, threshold_b: Number(e.target.value) })}
                                            className="w-full text-sm border rounded px-2.5 py-1.5 bg-background"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Batas Nilai C (≥)</Label>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={formulaData.threshold_c}
                                            onChange={e => setFormulaData({ ...formulaData, threshold_c: Number(e.target.value) })}
                                            className="w-full text-sm border rounded px-2.5 py-1.5 bg-background"
                                            required
                                        />
                                    </div>
                                </div>
                                <p className="text-[11px] text-muted-foreground mt-1">Nilai di bawah batas C otomatis mendapat predikat D.</p>
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <Button type="button" variant="outline" size="sm" onClick={() => setFormulaOpen(false)}>Batal</Button>
                                <Button type="submit" size="sm" disabled={savingFormula}>
                                    {savingFormula ? 'Menyimpan...' : 'Simpan Formula'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {/* Filter Bar */}
            <Card className="mb-5">
                <CardContent className="pt-4 pb-4">
                    <div className="flex flex-wrap gap-4 items-end">
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">Rombel</Label>
                            <Select value={selectedRombelId ?? ''} onValueChange={handleRombelChange}>
                                <SelectTrigger className="w-52">
                                    <SelectValue placeholder="Pilih rombel..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {rombels.map(r => (
                                        <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">Semester</Label>
                            <Select value={selectedSemester} onValueChange={handleSemesterChange}>
                                <SelectTrigger className="w-36">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Ganjil">Ganjil</SelectItem>
                                    <SelectItem value="Genap">Genap</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {selectedRombelId && (
                            <div className="space-y-1.5">
                                <Label className="text-xs text-muted-foreground">Ekspor RDM (CSV)</Label>
                                <div className="flex gap-2">
                                    <a href={`/kelas/leger/export?rombel_id=${selectedRombelId}&semester=${selectedSemester}`}>
                                        <Button variant="outline" size="sm">Leger CSV</Button>
                                    </a>
                                </div>
                                <p className="text-[11px] text-muted-foreground">Salin-tempel ke template RDM per mapel.</p>
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>

            {/* Leger Table */}
            {!leger ? (
                <Card>
                    <CardContent className="py-16 text-center text-muted-foreground">
                        <FileText className="w-10 h-10 mx-auto mb-3 opacity-30" />
                        <p>Pilih Rombel dan Semester untuk menampilkan leger nilai.</p>
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-sm">Leger Nilai — {leger.rombel}</CardTitle>
                                <CardDescription className="text-xs">
                                    Semester {leger.semester} | {leger.siswa.length} siswa | {leger.subjects.length} mata pelajaran
                                </CardDescription>
                            </div>
                            <div className="flex gap-2">
                                <Badge variant="outline" className="text-xs">P1 = Formatif 1</Badge>
                                <Badge variant="outline" className="text-xs">P2 = Formatif 2</Badge>
                                <Badge variant="outline" className="text-xs">STS = Sumatif Tengah</Badge>
                                <Badge variant="outline" className="text-xs">SAS = Sumatif Akhir</Badge>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs border-collapse">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="sticky left-0 bg-muted/90 backdrop-blur px-3 py-2 text-left font-semibold min-w-[180px] z-10">Nama Siswa</th>
                                        <th className="px-2 py-2 font-semibold text-muted-foreground min-w-[40px]">No</th>
                                        {leger.subjects.map(subj => (
                                            <th key={subj.id} colSpan={5} className="border-l border-border/50 px-1 py-1 text-center font-semibold min-w-[240px]">
                                                <div className="font-semibold">{subj.code}</div>
                                                <div className="text-muted-foreground font-normal truncate max-w-[220px] mx-auto">{subj.name}</div>
                                                <div className="flex gap-0.5 justify-center mt-1">
                                                    {['P1', 'P2', 'STS', 'SAS', 'NA'].map(h => (
                                                        <span key={h} className="w-12 text-center text-muted-foreground">{h}</span>
                                                    ))}
                                                </div>
                                            </th>
                                        ))}
                                        <th className="px-3 py-2 font-semibold text-center min-w-[60px]">Rata</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {leger.siswa.map((row, idx) => {
                                        // Hitung rata-rata semua nilai akhir
                                        const nilaiAkhirList = leger.subjects
                                            .map(s => row.nilai[s.id]?.nilai_akhir)
                                            .filter(v => v != null) as number[];
                                        const rataRata = nilaiAkhirList.length
                                            ? (nilaiAkhirList.reduce((a, b) => a + b, 0) / nilaiAkhirList.length).toFixed(1)
                                            : '—';

                                        return (
                                            <tr key={row.student_id} className="border-b hover:bg-muted/20 transition-colors">
                                                <td className="sticky left-0 bg-background/95 backdrop-blur z-10 px-3 py-1.5">
                                                    <div className="font-medium">{row.name}</div>
                                                    {row.nisn && <div className="text-muted-foreground font-mono">{row.nisn}</div>}
                                                </td>
                                                <td className="px-2 py-1.5 text-center text-muted-foreground">{row.attendance_number ?? idx + 1}</td>
                                                {leger.subjects.map(subj => {
                                                    const grade = row.nilai[subj.id] ?? {};
                                                    const key = `${row.student_id}_${subj.id}`;
                                                    const isSaving = saving === key;
                                                    return (
                                                        <td key={subj.id} className="border-l border-border/30 px-0.5 py-1">
                                                            <div className={cn('flex items-center gap-0.5', isSaving && 'opacity-50')}>
                                                                {(['nilai_p1', 'nilai_p2', 'nilai_sts', 'nilai_sas'] as const).map(field => (
                                                                    <NilaiInput
                                                                        key={field}
                                                                        value={grade[field]}
                                                                        onSave={v => saveGrade(row.student_id, subj.id, field, v, row)}
                                                                    />
                                                                ))}
                                                                <div className="w-12 text-center">
                                                                    {grade.nilai_akhir != null ? (
                                                                        <span className={cn(
                                                                            'font-bold',
                                                                            grade.nilai_akhir >= (subj.kkm) ? 'text-emerald-600' : 'text-rose-500'
                                                                        )}>
                                                                            {grade.nilai_akhir.toFixed(1)}
                                                                        </span>
                                                                    ) : <span className="text-muted-foreground">—</span>}
                                                                </div>
                                                            </div>
                                                        </td>
                                                    );
                                                })}
                                                <td className="px-3 py-1.5 text-center font-bold">
                                                    {rataRata}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="px-4 py-3 border-t bg-muted/20 text-xs text-muted-foreground">
                            💡 Klik angka nilai untuk mengedit. Tekan Enter atau klik di luar untuk menyimpan.
                            Nilai akhir (NA) dihitung otomatis = rata-rata P1+P2+STS+SAS.
                            Warna <span className="text-emerald-600 font-medium">hijau</span> = di atas KKM,
                            <span className="text-rose-500 font-medium ml-1">merah</span> = di bawah KKM.
                        </div>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}
