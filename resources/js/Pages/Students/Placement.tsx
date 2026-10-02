import React, { useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    GraduationCap, Users, CheckCircle2, AlertTriangle,
    Check, Search, School, ShieldCheck,
    UserCheck, UserPlus, X, LayoutGrid, ArrowRight
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Progress } from '@/components/ui/progress';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';

interface RombelInfo {
    id: string;
    name: string;
    grade_level: string;
    current_students: number;
    male_count: number;
    female_count: number;
    max_capacity: number;
    remaining_capacity: number;
    is_full: boolean;
}

interface Registration {
    id: string;
    registration_number: string;
    name: string;
    gender: 'L' | 'P';
    nisn?: string;
    prev_school_name?: string;
    status: string;
    converted_student?: { id: string; name: string; cohorts?: { rombel?: { id: string; name: string } }[] };
}

interface UnassignedStudent {
    id: string;
    name: string;
    nisn?: string;
    nik?: string;
    gender: string;
    emis_student_id?: string;
    grade_level?: string | null;
}

interface Props {
    activeYear: { id: string; year: string; semester: string } | null;
    verifiedRegistrations: Registration[];
    unassignedStudents: UnassignedStudent[];
    placedRegistrations: Registration[];
    rombels: RombelInfo[];
}

const GRADES = ['VII', 'VIII', 'IX'];
const GRADE_MAP: Record<string, string> = { 'VII': '7', 'VIII': '8', 'IX': '9' };

function CapacityRing({ current, max }: { current: number; max: number }) {
    const pct = max > 0 ? Math.round((current / max) * 100) : 0;
    const color = pct >= 100 ? 'text-rose-500' : pct >= 85 ? 'text-amber-500' : 'text-emerald-500';
    return (
        <div className="relative w-14 h-14">
            <svg className="w-14 h-14 -rotate-90" viewBox="0 0 56 56">
                <circle cx="28" cy="28" r="24" fill="none" stroke="currentColor" className="text-muted/30" strokeWidth="4" />
                <circle cx="28" cy="28" r="24" fill="none" stroke="currentColor"
                    className={color} strokeWidth="4" strokeDasharray={`${(pct / 100) * 150.8} 150.8`} strokeLinecap="round" />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className={`text-xs font-bold ${color}`}>{current}</span>
                <span className="text-[8px] text-muted-foreground">/{max}</span>
            </div>
        </div>
    );
}

export default function PlacementIndex({
    activeYear, verifiedRegistrations, unassignedStudents, placedRegistrations, rombels,
}: Props) {
    const [activeTab, setActiveTab] = useState('VII');
    const [selectedRombelId, setSelectedRombelId] = useState<string | null>(null);
    const [selectedRegIds, setSelectedRegIds] = useState<string[]>([]);
    const [selectedStudentIds, setSelectedStudentIds] = useState<string[]>([]);
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);

    const allCandidates = useMemo(() => [
        ...verifiedRegistrations.map(r => ({ ...r, source: 'ppdb' as const })),
        ...unassignedStudents.map(s => ({
            ...s, source: 'emis' as const, registration_number: s.nisn || '-',
            prev_school_name: null, status: 'active',
        })),
    ], [verifiedRegistrations, unassignedStudents]);

    const candidatesByTab = useMemo(() => {
        const result: Record<string, typeof allCandidates> = {};
        GRADES.forEach(g => {
            result[g] = allCandidates.filter(c => {
                if (c.source === 'emis' && 'grade_level' in c) return c.grade_level === g;
                return c.source === 'ppdb';
            });
        });
        return result;
    }, [allCandidates]);

    const placedStudentIds = useMemo(() => {
        const ids = new Set<string>();
        placedRegistrations.forEach(r => { if (r.converted_student?.id) ids.add(r.converted_student.id); });
        return ids;
    }, [placedRegistrations]);

    const activeTabCandidates = candidatesByTab[activeTab] || [];
    const availableCandidates = useMemo(() =>
        activeTabCandidates.filter(c => !(c.source === 'emis' && placedStudentIds.has(c.id))),
    [activeTabCandidates, placedStudentIds]);

    const filteredCandidates = useMemo(() => {
        if (!search) return availableCandidates;
        const q = search.toLowerCase();
        return availableCandidates.filter(c => c.name.toLowerCase().includes(q) || c.registration_number.toLowerCase().includes(q));
    }, [availableCandidates, search]);

    const activeTabRombels = useMemo(() => rombels.filter(r => r.grade_level === activeTab), [rombels, activeTab]);

    const tabStats = useMemo(() => {
        const result: Record<string, { siswa: number; rombel: number; terisi: number; kapasitas: number }> = {};
        GRADES.forEach(g => {
            const r = rombels.filter(rm => rm.grade_level === g);
            result[g] = {
                siswa: candidatesByTab[g]?.length || 0,
                rombel: r.length,
                terisi: r.reduce((a, rm) => a + rm.current_students, 0),
                kapasitas: r.reduce((a, rm) => a + rm.max_capacity, 0),
            };
        });
        return result;
    }, [rombels, candidatesByTab]);

    const totalSelected = selectedRegIds.length + selectedStudentIds.length;

    const handleSelectAll = (checked: boolean) => {
        setSelectedRegIds(checked ? filteredCandidates.filter(c => c.source === 'ppdb').map(c => c.id) : []);
        setSelectedStudentIds(checked ? filteredCandidates.filter(c => c.source === 'emis').map(c => c.id) : []);
    };

    const handleToggle = (id: string, source: 'ppdb' | 'emis') => {
        if (source === 'ppdb') {
            setSelectedRegIds(p => p.includes(id) ? p.filter(i => i !== id) : [...p, id]);
        } else {
            setSelectedStudentIds(p => p.includes(id) ? p.filter(i => i !== id) : [...p, id]);
        }
    };

    const handleAssign = () => {
        if (!selectedRombelId || totalSelected === 0) return;
        const t = rombels.find(r => r.id === selectedRombelId);
        if (t && t.current_students + totalSelected > t.max_capacity) {
            alert(`Kapasitas tidak cukup! Sisa ${t.remaining_capacity} kursi, ${totalSelected} siswa.`);
            return;
        }
        setProcessing(true);
        router.post('/siswa/penempatan/assign', {
            registration_ids: selectedRegIds.length > 0 ? selectedRegIds : undefined,
            student_ids: selectedStudentIds.length > 0 ? selectedStudentIds : undefined,
            rombel_id: selectedRombelId,
        }, {
            preserveScroll: true,
            onSuccess: () => { setSelectedRegIds([]); setSelectedStudentIds([]); setSearch(''); },
            onFinish: () => setProcessing(false),
        });
    };

    const handleTabChange = (v: string) => {
        setActiveTab(v);
        setSelectedRombelId(null);
        setSelectedRegIds([]);
        setSelectedStudentIds([]);
        setSearch('');
    };

    const selectedRombel = rombels.find(r => r.id === selectedRombelId);
    const wouldExceed = selectedRombel && selectedRombel.current_students + totalSelected > selectedRombel.max_capacity;

    return (
        <AppLayout>
            <Head title="Penempatan Siswa ke Rombel" />
            <div className="space-y-5">

                {/* ═══ HEADER ═══ */}
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Penempatan Siswa ke Rombel</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            Pilih rombel → centang siswa → tempatkan.
                        </p>
                    </div>
                    <Link href="/ppdb/registrations">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <UserPlus className="h-3.5 w-3.5" /> Data PPDB
                        </Button>
                    </Link>
                </div>

                {/* ═══ STAT CARDS ═══ */}
                <div className="grid grid-cols-3 gap-3">
                    <div className="p-3 rounded-xl border bg-gradient-to-br from-card to-muted/30">
                        <div className="flex items-center justify-between mb-1">
                            <span className="text-[11px] text-muted-foreground font-medium">Tahun Ajaran</span>
                            <GraduationCap className="h-3.5 w-3.5 text-muted-foreground/60" />
                        </div>
                        <p className="text-sm font-bold">{activeYear?.year || '—'}</p>
                        <p className="text-[10px] text-muted-foreground">{activeYear?.semester || 'Belum diatur'}</p>
                    </div>
                    <div className="p-3 rounded-xl border bg-gradient-to-br from-card to-indigo-50/50 dark:to-indigo-950/20">
                        <div className="flex items-center justify-between mb-1">
                            <span className="text-[11px] text-muted-foreground font-medium">Siap Ditempatkan</span>
                            <ShieldCheck className="h-3.5 w-3.5 text-indigo-500/60" />
                        </div>
                        <p className="text-sm font-bold text-indigo-600">{allCandidates.length}</p>
                        <p className="text-[10px] text-muted-foreground">{verifiedRegistrations.length} PPDB · {unassignedStudents.length} EMIS</p>
                    </div>
                    <div className="p-3 rounded-xl border bg-gradient-to-br from-card to-emerald-50/50 dark:to-emerald-950/20">
                        <div className="flex items-center justify-between mb-1">
                            <span className="text-[11px] text-muted-foreground font-medium">Sudah Ditempatkan</span>
                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500/60" />
                        </div>
                        <p className="text-sm font-bold text-emerald-600">{placedRegistrations.length}</p>
                        <p className="text-[10px] text-muted-foreground">siswa aktif di rombel</p>
                    </div>
                </div>

                {/* ═══ TABS + CONTENT ═══ */}
                <Tabs value={activeTab} onValueChange={handleTabChange}>
                    <TabsList className="bg-muted/50 p-1 h-auto w-full justify-start">
                        {GRADES.map(g => {
                            const s = tabStats[g];
                            return (
                                <TabsTrigger key={g} value={g}
                                    className="flex-1 sm:flex-none text-xs gap-2 px-5 py-2.5 data-[state=active]:bg-background data-[state=active]:shadow-sm">
                                    <span className="font-semibold">Tingkat {GRADE_MAP[g]}</span>
                                    <span className="text-muted-foreground">·</span>
                                    <span className="text-muted-foreground">{s.siswa} siswa</span>
                                    <span className="text-muted-foreground">·</span>
                                    <span className="text-muted-foreground">{s.rombel} kelas</span>
                                </TabsTrigger>
                            );
                        })}
                    </TabsList>

                    {GRADES.map(g => {
                        const stats = tabStats[g];
                        const totalTerisi = stats.terisi;
                        const totalKapasitas = stats.kapasitas;
                        const pctGlobal = totalKapasitas > 0 ? Math.round((totalTerisi / totalKapasitas) * 100) : 0;

                        return (
                            <TabsContent key={g} value={g} className="mt-4 space-y-5">

                                {/* ─── RINGKASAN TINGKAT ─── */}
                                <div className="flex items-center gap-6 p-4 rounded-2xl border bg-gradient-to-r from-card to-muted/20">
                                    <div className="flex-1 space-y-2">
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-base font-bold">Tingkat {GRADE_MAP[g]}</h3>
                                            <Badge variant="outline" className="text-[10px]">{stats.rombel} rombel</Badge>
                                        </div>
                                        <div className="flex items-center gap-4 text-xs text-muted-foreground">
                                            <span><strong className="text-foreground">{stats.siswa}</strong> siswa perlu ditempatkan</span>
                                            <span>·</span>
                                            <span><strong className="text-foreground">{totalTerisi}</strong> terisi dari {totalKapasitas} kursi</span>
                                        </div>
                                        <Progress value={pctGlobal} className="h-1.5" />
                                    </div>
                                    <div className="text-right">
                                        <p className="text-2xl font-bold text-foreground">{pctGlobal}%</p>
                                        <p className="text-[10px] text-muted-foreground">terisi</p>
                                    </div>
                                </div>

                                {/* ─── GRID ROMBEL ─── */}
                                <div>
                                    <div className="flex items-center justify-between mb-3">
                                        <h3 className="text-sm font-semibold flex items-center gap-2">
                                            <LayoutGrid className="h-4 w-4 text-muted-foreground" />
                                            Pilih Rombel
                                        </h3>
                                        {selectedRombel && (
                                            <Button variant="ghost" size="sm" className="text-[11px] h-7 text-muted-foreground"
                                                onClick={() => { setSelectedRombelId(null); setSelectedRegIds([]); setSelectedStudentIds([]); }}>
                                                <X className="h-3 w-3 mr-1" /> Batal pilih
                                            </Button>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-7 gap-2.5">
                                        {activeTabRombels.map(r => {
                                            const pct = r.max_capacity > 0 ? Math.round((r.current_students / r.max_capacity) * 100) : 0;
                                            const selected = selectedRombelId === r.id;
                                            return (
                                                <div key={r.id}
                                                    onClick={() => !r.is_full && setSelectedRombelId(selected ? null : r.id)}
                                                    className={`group relative p-4 rounded-2xl border-2 text-center transition-all duration-200 ${
                                                        selected
                                                            ? 'border-primary bg-primary/5 shadow-md ring-2 ring-primary/20 scale-[1.02]'
                                                            : r.is_full
                                                            ? 'border-border/30 opacity-40 cursor-not-allowed bg-muted/10'
                                                            : 'border-border/50 hover:border-primary/40 hover:bg-primary/5 hover:shadow-sm cursor-pointer'
                                                    }`}>
                                                    {selected && (
                                                        <div className="absolute top-2 right-2">
                                                            <div className="w-5 h-5 rounded-full bg-primary flex items-center justify-center">
                                                                <Check className="h-3 w-3 text-primary-foreground" />
                                                            </div>
                                                        </div>
                                                    )}
                                                    <CapacityRing current={r.current_students} max={r.max_capacity} />
                                                    <p className="font-bold text-sm mt-2">{r.name}</p>
                                                    <div className="flex items-center justify-center gap-2 mt-1">
                                                        <span className="text-[10px] font-medium text-blue-500">L {r.male_count}</span>
                                                        <span className="text-muted-foreground text-[8px]">·</span>
                                                        <span className="text-[10px] font-medium text-pink-500">P {r.female_count}</span>
                                                    </div>
                                                    <p className={`text-[10px] font-medium mt-0.5 ${r.is_full ? 'text-rose-500' : pct >= 85 ? 'text-amber-500' : 'text-emerald-500'}`}>
                                                        {r.is_full ? 'Penuh' : `${r.remaining_capacity} kursi tersisa`}
                                                    </p>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* ─── SISWA (muncul setelah rombel dipilih) ─── */}
                                {selectedRombelId ? (
                                    <Card className="shadow-sm overflow-hidden border-primary/20">
                                        {/* Header */}
                                        <div className="px-5 py-4 bg-gradient-to-r from-primary/5 to-transparent border-b border-border/40">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex items-center justify-center w-10 h-10 rounded-xl bg-primary/10 text-primary">
                                                        <School className="h-5 w-5" />
                                                    </div>
                                                    <div>
                                                        <h3 className="text-sm font-bold">{selectedRombel?.name}</h3>
                                                        <p className="text-[11px] text-muted-foreground">
                                                            {filteredCandidates.length} siswa tersedia · {selectedRombel?.remaining_capacity} kursi kosong
                                                        </p>
                                                    </div>
                                                </div>
                                                {totalSelected > 0 && (
                                                    <Badge className="bg-primary text-primary-foreground text-[10px] px-2.5 py-0.5">
                                                        {totalSelected} dipilih
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>

                                        {/* Search */}
                                        <div className="px-5 py-3 border-b border-border/40 bg-muted/10">
                                            <div className="flex items-center gap-2">
                                                <div className="relative flex-1">
                                                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                                                    <Input placeholder="Cari nama, NISN..." value={search}
                                                        onChange={e => setSearch(e.target.value)} className="h-8 pl-8 text-xs" />
                                                </div>
                                                <div className="flex items-center gap-1.5 px-2">
                                                    <Checkbox id="sa" checked={filteredCandidates.length > 0 && totalSelected === filteredCandidates.length}
                                                        onCheckedChange={handleSelectAll} />
                                                    <label htmlFor="sa" className="text-[11px] text-muted-foreground cursor-pointer whitespace-nowrap">Semua</label>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Batch Action Bar */}
                                        {totalSelected > 0 && (
                                            <div className="px-5 py-3 border-b border-border/40 bg-emerald-50 dark:bg-emerald-950/20 flex items-center justify-between">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex items-center justify-center w-6 h-6 rounded-full bg-emerald-600 text-white text-[10px] font-bold">{totalSelected}</div>
                                                    <span className="text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                                        siswa akan ditempatkan ke <strong>{selectedRombel?.name}</strong>
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Button variant="ghost" size="sm" className="text-[11px] h-7 text-muted-foreground"
                                                        onClick={() => { setSelectedRegIds([]); setSelectedStudentIds([]); }}>
                                                        Reset
                                                    </Button>
                                                    <Button size="sm" onClick={handleAssign} disabled={processing || !!wouldExceed}
                                                        className="h-8 px-4 text-xs bg-emerald-600 hover:bg-emerald-700 text-white gap-1.5 font-semibold shadow-sm">
                                                        <Check className="h-3.5 w-3.5" />
                                                        {processing ? 'Memproses...' : 'Tempatkan Sekarang'}
                                                    </Button>
                                                </div>
                                            </div>
                                        )}

                                        {/* Capacity Warning */}
                                        {wouldExceed && (
                                            <div className="px-5 py-2.5 border-b border-border/40 bg-amber-50 dark:bg-amber-950/30 text-amber-800 dark:text-amber-200 text-[11px] flex items-center gap-2">
                                                <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                                                Peringatan: Melebihi kapasitas ({selectedRombel!.current_students + totalSelected}/{selectedRombel!.max_capacity})
                                            </div>
                                        )}

                                        {/* Student List */}
                                        <div className="divide-y divide-border/30 max-h-[420px] overflow-y-auto">
                                            {filteredCandidates.length === 0 ? (
                                                <div className="py-16 text-center">
                                                    <Users className="h-10 w-10 mx-auto mb-2 text-muted-foreground/20" />
                                                    <p className="text-xs text-muted-foreground font-medium">
                                                        {availableCandidates.length === 0 ? 'Semua siswa sudah ditempatkan' : 'Tidak ada hasil pencarian'}
                                                    </p>
                                                </div>
                                            ) : filteredCandidates.map(item => {
                                                const checked = item.source === 'ppdb'
                                                    ? selectedRegIds.includes(item.id) : selectedStudentIds.includes(item.id);
                                                return (
                                                    <div key={`${item.source}-${item.id}`}
                                                        onClick={() => handleToggle(item.id, item.source)}
                                                        className={`px-5 py-3 flex items-center gap-3 cursor-pointer transition-all duration-150 ${checked ? 'bg-primary/5' : 'hover:bg-muted/30'}`}>
                                                        <Checkbox checked={checked}
                                                            onCheckedChange={() => handleToggle(item.id, item.source)}
                                                            onClick={e => e.stopPropagation()} />
                                                        {/* Avatar */}
                                                        <div className={`w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold shrink-0 ${
                                                            item.gender === 'L' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                                            : 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300'
                                                        }`}>
                                                            {item.name.charAt(0)}
                                                        </div>
                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex items-center gap-1.5">
                                                                <span className="font-semibold text-sm truncate">{item.name}</span>
                                                                <Badge variant="outline" className={`text-[9px] font-bold h-3.5 px-1 ${item.gender === 'L' ? 'bg-blue-50 text-blue-600 border-blue-200' : 'bg-pink-50 text-pink-600 border-pink-200'}`}>
                                                                    {item.gender}
                                                                </Badge>
                                                                <Badge variant="outline" className={`text-[9px] h-3.5 px-1 ${item.source === 'emis' ? 'bg-amber-50 text-amber-600 border-amber-200' : 'bg-sky-50 text-sky-600 border-sky-200'}`}>
                                                                    {item.source === 'emis' ? 'EMIS' : 'PPDB'}
                                                                </Badge>
                                                            </div>
                                                            <p className="text-[11px] text-muted-foreground truncate mt-0.5">
                                                                {item.registration_number}
                                                                {item.source === 'ppdb' && item.prev_school_name && ` · ${item.prev_school_name}`}
                                                            </p>
                                                        </div>
                                                        {item.source === 'ppdb' && (
                                                            <Link href={`/ppdb/registrations/${item.id}`} onClick={e => e.stopPropagation()}
                                                                className="text-[11px] text-primary/70 hover:text-primary shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                                Detail
                                                            </Link>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </Card>
                                ) : (
                                    /* Empty State: belum pilih rombel */
                                    <div className="py-10 rounded-2xl border border-dashed border-primary/20 bg-gradient-to-br from-primary/5 to-transparent text-center">
                                        <div className="w-12 h-12 rounded-2xl bg-primary/10 flex items-center justify-center mx-auto mb-3">
                                            <LayoutGrid className="h-6 w-6 text-primary/50" />
                                        </div>
                                        <p className="text-sm font-medium text-foreground">Pilih Rombel Terlebih Dahulu</p>
                                        <p className="text-xs text-muted-foreground mt-1 max-w-xs mx-auto">
                                            Klik salah satu kartu rombel di atas untuk melihat daftar siswa yang akan ditempatkan
                                        </p>
                                    </div>
                                )}
                            </TabsContent>
                        );
                    })}
                </Tabs>

                {/* ═══ RECENT PLACEMENTS ═══ */}
                {placedRegistrations.length > 0 && (
                    <Card className="shadow-sm">
                        <CardHeader className="p-4 pb-2">
                            <CardTitle className="text-xs font-semibold flex items-center gap-1.5">
                                <UserCheck className="h-3.5 w-3.5 text-emerald-600" />
                                10 Terakhir Ditempatkan
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0 px-4 pb-3">
                            <div className="divide-y divide-border/40">
                                {placedRegistrations.slice(0, 10).map(reg => (
                                    <div key={reg.id} className="py-2.5 flex items-center justify-between">
                                        <div className="min-w-0">
                                            <p className="text-xs font-medium truncate">{reg.name}</p>
                                            <p className="text-[10px] text-muted-foreground">
                                                {reg.converted_student?.cohorts?.[0]?.rombel?.name || '—'}
                                            </p>
                                        </div>
                                        <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500 shrink-0" />
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
