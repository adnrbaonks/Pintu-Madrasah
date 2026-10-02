import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    GraduationCap, Users, CheckCircle2, AlertTriangle,
    ArrowRight, Check, Search, Filter, School, ShieldCheck,
    UserCheck, ChevronRight
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Progress } from '@/components/ui/progress';
import { Input } from '@/components/ui/input';

interface RombelInfo {
    id: string;
    name: string;
    current_students: number;
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
    converted_student?: {
        id: string;
        name: string;
        cohorts?: {
            rombel?: {
                id: string;
                name: string;
            };
        }[];
    };
}

interface Props {
    activeYear: {
        id: string;
        year: string;
        semester: string;
    } | null;
    activePeriod: {
        id: string;
        name: string;
    } | null;
    verifiedRegistrations: Registration[];
    placedRegistrations: Registration[];
    rombels: RombelInfo[];
}

export default function PlacementIndex({
    activeYear,
    activePeriod,
    verifiedRegistrations,
    placedRegistrations,
    rombels,
}: Props) {
    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [selectedRombelId, setSelectedRombelId] = useState<string>('');
    const [search, setSearch] = useState<string>('');
    const [processing, setProcessing] = useState(false);

    // Filter verified registrations by search
    const filteredVerified = verifiedRegistrations.filter((reg) => {
        if (!search) return true;
        const q = search.toLowerCase();
        return (
            reg.name.toLowerCase().includes(q) ||
            reg.registration_number.toLowerCase().includes(q) ||
            (reg.nisn && reg.nisn.includes(q)) ||
            (reg.prev_school_name && reg.prev_school_name.toLowerCase().includes(q))
        );
    });

    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedIds(filteredVerified.map((r) => r.id));
        } else {
            setSelectedIds([]);
        }
    };

    const handleToggleCandidate = (id: string) => {
        setSelectedIds((prev) =>
            prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
        );
    };

    const handleAssign = () => {
        if (!selectedRombelId || selectedIds.length === 0) return;
        
        const targetRombel = rombels.find((r) => r.id === selectedRombelId);
        if (targetRombel && targetRombel.current_students + selectedIds.length > targetRombel.max_capacity) {
            alert(`Gagal! Kapasitas rombel tidak mencukupi.\nAnda mencoba menempatkan ${selectedIds.length} siswa, tapi sisa kapasitas hanya ${targetRombel.remaining_capacity} kursi.`);
            return;
        }

        setProcessing(true);
        router.post(
            '/ppdb/placement/assign',
            {
                registration_ids: selectedIds,
                rombel_id: selectedRombelId,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedIds([]);
                    setSelectedRombelId('');
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    const targetRombel = rombels.find((r) => r.id === selectedRombelId);
    const wouldExceedCapacity =
        targetRombel && targetRombel.current_students + selectedIds.length > targetRombel.max_capacity;

    return (
        <AppLayout>
            <Head title="Penempatan Siswa Baru ke Rombel" />

            <div className="space-y-6">
                {/* Top Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight text-foreground">
                                Penempatan Siswa ke Rombel
                            </h1>
                            <Badge variant="outline" className="text-xs bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300">
                                Tahap Akhir PPDB
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground mt-1">
                            Tempatkan calon siswa yang telah terverifikasi ke dalam rombongan belajar kelas 7. Kapasitas standar maksimal 32 siswa per kelas.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link href={'/ppdb/registrations'}>
                            <Button variant="outline" size="sm" className="text-xs">
                                Daftar Pendaftar
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Info Bar */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="p-3.5 rounded-lg border bg-card flex items-center justify-between">
                        <div>
                            <span className="text-xs text-muted-foreground">Tahun Ajaran Aktif</span>
                            <p className="text-sm font-bold text-foreground">
                                {activeYear ? `${activeYear.year} (${activeYear.semester})` : 'Belum diatur'}
                            </p>
                        </div>
                        <GraduationCap className="h-5 w-5 text-primary/60" />
                    </div>

                    <div className="p-3.5 rounded-lg border bg-card flex items-center justify-between">
                        <div>
                            <span className="text-xs text-muted-foreground">Siap Ditempatkan</span>
                            <p className="text-sm font-bold text-indigo-600 dark:text-indigo-400">
                                {verifiedRegistrations.length} Calon Siswa
                            </p>
                        </div>
                        <ShieldCheck className="h-5 w-5 text-indigo-500/60" />
                    </div>

                    <div className="p-3.5 rounded-lg border bg-card flex items-center justify-between">
                        <div>
                            <span className="text-xs text-muted-foreground">Sudah Ditempatkan</span>
                            <p className="text-sm font-bold text-emerald-600 dark:text-emerald-400">
                                {placedRegistrations.length} Siswa
                            </p>
                        </div>
                        <CheckCircle2 className="h-5 w-5 text-emerald-500/60" />
                    </div>
                </div>

                {/* 2-Column Grid: Penempatan & Kapasitas Rombel */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    {/* Kolom Kiri (7 Cols): Calon Siswa Siap Ditempatkan */}
                    <div className="lg:col-span-7 space-y-4">
                        <Card className="shadow-sm">
                            <CardHeader className="p-5 pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="text-base font-semibold">
                                            Calon Siswa Terverifikasi
                                        </CardTitle>
                                        <CardDescription className="text-xs">
                                            Centang siswa yang akan dimasukkan ke rombel pilihan.
                                        </CardDescription>
                                    </div>
                                    <Badge variant="secondary" className="text-xs">
                                        {filteredVerified.length} siswa
                                    </Badge>
                                </div>

                                <div className="pt-3 flex items-center justify-between gap-3">
                                    <div className="relative flex-1">
                                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                        <Input
                                            placeholder="Cari nama, NISN, atau asal sekolah..."
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            className="h-8 pl-8 text-xs"
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2 shrink-0">
                                        <Checkbox
                                            id="select-all"
                                            checked={
                                                filteredVerified.length > 0 &&
                                                selectedIds.length === filteredVerified.length
                                            }
                                            onCheckedChange={handleSelectAll}
                                        />
                                        <label
                                            htmlFor="select-all"
                                            className="text-xs font-medium text-muted-foreground cursor-pointer select-none"
                                        >
                                            Pilih Semua
                                        </label>
                                    </div>
                                </div>
                            </CardHeader>

                            {/* Batch Action Toolbar */}
                            {selectedIds.length > 0 && (
                                <div className="mx-5 p-3.5 rounded-lg border border-primary/30 bg-primary/5 dark:bg-primary/10 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <Badge className="bg-primary text-primary-foreground font-bold">
                                            {selectedIds.length}
                                        </Badge>
                                        <span className="text-xs font-medium text-foreground">
                                            Calon Siswa Terpilih
                                        </span>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Select value={selectedRombelId} onValueChange={setSelectedRombelId}>
                                            <SelectTrigger className="h-8 text-xs w-48 bg-background">
                                                <SelectValue placeholder="Pilih Rombel Tujuan" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {rombels.map((rombel) => (
                                                    <SelectItem
                                                        key={rombel.id}
                                                        value={rombel.id}
                                                        disabled={rombel.is_full}
                                                    >
                                                        {rombel.name} (Sisa: {rombel.remaining_capacity})
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>

                                        <Button
                                            size="sm"
                                            onClick={handleAssign}
                                            disabled={!selectedRombelId || processing}
                                            className="h-8 px-3 text-xs bg-emerald-600 hover:bg-emerald-700 text-white gap-1 shrink-0"
                                        >
                                            <Check className="h-3.5 w-3.5" />
                                            {processing ? 'Memproses...' : 'Tempatkan'}
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {wouldExceedCapacity && (
                                <div className="mx-5 p-3 rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-200 text-xs flex items-center gap-2">
                                    <AlertTriangle className="h-4 w-4 shrink-0 text-amber-600" />
                                    <span>
                                        Perhatian: Penempatan ini akan membuat <strong>{targetRombel.name}</strong>{' '}
                                        melebihi kapasitas standar 32 siswa ({targetRombel.current_students + selectedIds.length} siswa).
                                    </span>
                                </div>
                            )}

                            <CardContent className="p-0 pt-2">
                                {filteredVerified.length === 0 ? (
                                    <div className="py-12 text-center text-xs text-muted-foreground">
                                        <Users className="h-8 w-8 mx-auto mb-2 text-muted-foreground/40" />
                                        {verifiedRegistrations.length === 0
                                            ? 'Tidak ada calon siswa berstatus Terverifikasi saat ini.'
                                            : 'Tidak ada siswa yang sesuai dengan pencarian.'}
                                    </div>
                                ) : (
                                    <div className="divide-y divide-border/60 max-h-[500px] overflow-y-auto">
                                        {filteredVerified.map((reg) => {
                                            const isChecked = selectedIds.includes(reg.id);
                                            return (
                                                <div
                                                    key={reg.id}
                                                    onClick={() => handleToggleCandidate(reg.id)}
                                                    className={`p-3.5 flex items-center justify-between gap-3 cursor-pointer transition-colors ${
                                                        isChecked
                                                            ? 'bg-primary/5 dark:bg-primary/10'
                                                            : 'hover:bg-muted/30'
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-3 min-w-0">
                                                        <Checkbox
                                                            checked={isChecked}
                                                            onCheckedChange={() => handleToggleCandidate(reg.id)}
                                                            onClick={(e) => e.stopPropagation()}
                                                        />
                                                        <div className="min-w-0">
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-semibold text-sm text-foreground truncate">
                                                                    {reg.name}
                                                                </span>
                                                                <Badge
                                                                    variant="outline"
                                                                    className={`text-[10px] font-bold h-4 px-1.5 ${
                                                                        reg.gender === 'L'
                                                                            ? 'bg-blue-50 text-blue-700 border-blue-200'
                                                                            : 'bg-pink-50 text-pink-700 border-pink-200'
                                                                    }`}
                                                                >
                                                                    {reg.gender}
                                                                </Badge>
                                                            </div>
                                                            <p className="text-xs text-muted-foreground mt-0.5 truncate">
                                                                {reg.registration_number} • Asal: {reg.prev_school_name || '-'}
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <Link
                                                        href={`/ppdb/registrations/${reg.id}`}
                                                        onClick={(e) => e.stopPropagation()}
                                                        className="text-xs text-primary hover:underline shrink-0"
                                                    >
                                                        Lihat Detail
                                                    </Link>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Kolom Kanan (5 Cols): Kapasitas Rombel Kelas 7 */}
                    <div className="lg:col-span-5 space-y-4">
                        <Card className="shadow-sm">
                            <CardHeader className="p-5 pb-3">
                                <CardTitle className="text-base font-semibold flex items-center gap-2">
                                    <School className="h-4 w-4 text-primary" />
                                    Kapasitas Rombel Kelas 7
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Batas ideal per kelas adalah maksimal 32 siswa.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="p-5 pt-0 space-y-4">
                                {rombels.length === 0 ? (
                                    <div className="py-8 text-center text-xs text-muted-foreground">
                                        Belum ada rombel Kelas 7 yang terdaftar pada tahun ajaran ini.
                                    </div>
                                ) : (
                                    rombels.map((rombel) => {
                                        const percent = Math.min(100, Math.round((rombel.current_students / rombel.max_capacity) * 100));
                                        const isAlmostFull = rombel.remaining_capacity <= 2 && !rombel.is_full;

                                        return (
                                            <div
                                                key={rombel.id}
                                                className={`p-3.5 rounded-lg border transition-all ${
                                                    selectedRombelId === rombel.id
                                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                        : 'border-border/60 hover:border-border'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between mb-1.5">
                                                    <span className="font-bold text-sm text-foreground">
                                                        {rombel.name}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className={`text-[11px] font-semibold ${
                                                            rombel.is_full
                                                                ? 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40'
                                                                : isAlmostFull
                                                                ? 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40'
                                                                : 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40'
                                                        }`}
                                                    >
                                                        {rombel.current_students} / {rombel.max_capacity} Siswa
                                                    </Badge>
                                                </div>

                                                <Progress
                                                    value={percent}
                                                    className="h-2 mb-1.5"
                                                />

                                                <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                                                    <span>
                                                        {rombel.is_full
                                                            ? 'Kapasitas Penuh'
                                                            : `Tersisa ${rombel.remaining_capacity} kursi`}
                                                    </span>
                                                    <span>{percent}% terisi</span>
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </CardContent>
                        </Card>

                        {/* Recent Placements Card */}
                        {placedRegistrations.length > 0 && (
                            <Card className="shadow-sm">
                                <CardHeader className="p-4 pb-2">
                                    <CardTitle className="text-sm font-semibold flex items-center gap-1.5">
                                        <UserCheck className="h-4 w-4 text-emerald-600" />
                                        Baru Saja Ditempatkan
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <div className="divide-y divide-border/60 max-h-60 overflow-y-auto">
                                        {placedRegistrations.slice(0, 5).map((reg) => (
                                            <div
                                                key={reg.id}
                                                className="p-3 flex items-center justify-between text-xs hover:bg-muted/20"
                                            >
                                                <div className="min-w-0 pr-2">
                                                    <p className="font-semibold text-foreground truncate">
                                                        {reg.name}
                                                    </p>
                                                    <span className="text-muted-foreground">
                                                        {reg.converted_student?.cohorts?.[0]?.rombel?.name || 'Kelas 7'}
                                                    </span>
                                                </div>
                                                <Badge variant="outline" className="text-[10px] text-emerald-700 bg-emerald-50 border-emerald-200 shrink-0">
                                                    Placed
                                                </Badge>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
