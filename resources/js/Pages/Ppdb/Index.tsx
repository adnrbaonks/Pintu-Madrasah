import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Users, UserCheck, FileCheck2, CheckCircle2, GraduationCap,
    Plus, ArrowRight, Calendar, Sparkles, AlertCircle, Clock,
    CheckCircle, ShieldCheck, ChevronRight, School, Award
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter,
    DialogHeader, DialogTitle, DialogTrigger
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';

interface AcademicYear {
    id: string;
    year: string;
    semester: string;
    is_active: boolean;
}

interface PpdbPeriod {
    id: string;
    academic_year_id: string;
    name: string;
    open_date: string;
    close_date: string;
    is_active: boolean;
    is_public_form_active: boolean;
    description?: string;
    registrations_count?: number;
    academic_year?: AcademicYear;
}

interface Registration {
    id: string;
    registration_number: string;
    name: string;
    gender: string;
    prev_school_name?: string;
    status: 'registered' | 're_enrolled' | 'verified' | 'placed';
    created_at: string;
    period?: PpdbPeriod;
}

interface Props {
    activePeriod: PpdbPeriod | null;
    periods: PpdbPeriod[];
    stats: {
        total: number;
        registered: number;
        re_enrolled: number;
        verified: number;
        placed: number;
    };
    recentRegistrations: Registration[];
    academicYears: AcademicYear[];
}

const STATUS_CONFIG = {
    registered: {
        label: 'Registered',
        color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800',
        badge: 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200',
    },
    re_enrolled: {
        label: 'Daftar Ulang',
        color: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800',
        badge: 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
    },
    verified: {
        label: 'Terverifikasi',
        color: 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-300 dark:border-indigo-800',
        badge: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200',
    },
    placed: {
        label: 'Ditempatkan',
        color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800',
        badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200',
    },
};

export default function PpdbIndex({ activePeriod, periods, stats, recentRegistrations, academicYears }: Props) {
    const [periodDialogOpen, setPeriodDialogOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        academic_year_id: academicYears.find(y => y.is_active)?.id || (academicYears[0]?.id ?? ''),
        name: '',
        open_date: new Date().toISOString().split('T')[0],
        close_date: new Date(Date.now() + 60 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
        is_active: true,
        description: '',
    });

    const handleCreatePeriod = (e: React.FormEvent) => {
        e.preventDefault();
        post('/ppdb/periods', {
            onSuccess: () => {
                setPeriodDialogOpen(false);
                reset();
            },
        });
    };

    const handleTogglePeriodActive = (periodId: string) => {
        router.put(`/ppdb/periods/${periodId}/toggle-active`);
    };

    const formatDate = (dateStr: string) => {
        if (!dateStr) return '-';
        return new Date(dateStr).toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    };

    return (
        <AppLayout>
            <Head title="Dashboard PPDB" />

            <div className="space-y-6">
                {/* Header Title & Top Controls */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight text-foreground">
                                Penerimaan Peserta Didik Baru (PPDB)
                            </h1>
                            <Badge variant="outline" className="text-xs bg-primary/10 text-primary border-primary/20">
                                Tahunan
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground mt-1">
                            Pusat manajemen penerimaan siswa baru, verifikasi berkas, dan penempatan rombongan belajar.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Dialog open={periodDialogOpen} onOpenChange={setPeriodDialogOpen}>
                            <DialogTrigger render={<Button variant="outline" size="sm" className="gap-1.5" />}>
                                    <Calendar className="h-4 w-4" />
                                    Kelola Periode
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-[480px]">
                                <form onSubmit={handleCreatePeriod}>
                                    <DialogHeader>
                                        <DialogTitle>Buat Periode PPDB Baru</DialogTitle>
                                        <DialogDescription>
                                            Buat periode pendaftaran tahunan baru untuk madrasah.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <div className="grid gap-4 py-4">
                                        <div className="space-y-1.5">
                                            <Label htmlFor="academic_year_id">Tahun Ajaran</Label>
                                            <Select
                                                value={data.academic_year_id}
                                                onValueChange={(val) => setData('academic_year_id', val)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Pilih Tahun Ajaran" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {academicYears.map((ay) => (
                                                        <SelectItem key={ay.id} value={ay.id}>
                                                            {ay.year} {ay.semester} {ay.is_active && '(Aktif)'}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {errors.academic_year_id && (
                                                <p className="text-xs text-destructive">{errors.academic_year_id}</p>
                                            )}
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="name">Nama Periode</Label>
                                            <Input
                                                id="name"
                                                placeholder="Contoh: PPDB TP 2026/2027"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                required
                                            />
                                            {errors.name && (
                                                <p className="text-xs text-destructive">{errors.name}</p>
                                            )}
                                        </div>

                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="space-y-1.5">
                                                <Label htmlFor="open_date">Tanggal Buka</Label>
                                                <Input
                                                    id="open_date"
                                                    type="date"
                                                    value={data.open_date}
                                                    onChange={(e) => setData('open_date', e.target.value)}
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label htmlFor="close_date">Tanggal Tutup</Label>
                                                <Input
                                                    id="close_date"
                                                    type="date"
                                                    value={data.close_date}
                                                    onChange={(e) => setData('close_date', e.target.value)}
                                                    required
                                                />
                                            </div>
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="description">Keterangan / Catatan</Label>
                                            <Textarea
                                                id="description"
                                                rows={3}
                                                placeholder="Informasi tambahan terkait gelombang pendaftaran..."
                                                value={data.description}
                                                onChange={(e) => setData('description', e.target.value)}
                                            />
                                        </div>

                                        <div className="flex items-center space-x-2 pt-1">
                                            <Checkbox
                                                id="is_active"
                                                checked={data.is_active}
                                                onCheckedChange={(checked) => setData('is_active', !!checked)}
                                            />
                                            <Label htmlFor="is_active" className="text-sm font-medium cursor-pointer">
                                                Jadikan sebagai periode aktif saat ini
                                            </Label>
                                        </div>
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setPeriodDialogOpen(false)}
                                        >
                                            Batal
                                        </Button>
                                        <Button type="submit" disabled={processing}>
                                            Simpan Periode
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>

                        <Link href={'/ppdb/registrations/create'}>
                            <Button size="sm" className="gap-1.5 shadow-sm">
                                <Plus className="h-4 w-4" />
                                Input Pendaftar
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Banner Periode Aktif */}
                {activePeriod ? (
                    <div className="relative overflow-hidden rounded-xl border border-primary/20 bg-gradient-to-r from-primary/10 via-primary/5 to-background p-5 shadow-sm">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <Badge className="bg-emerald-500/15 text-emerald-700 hover:bg-emerald-500/20 border-emerald-500/30">
                                        Periode Berjalan
                                    </Badge>
                                    <span className="text-xs text-muted-foreground">
                                        Tahun Ajaran: {activePeriod.academic_year?.year} ({activePeriod.academic_year?.semester})
                                    </span>
                                </div>
                                <h2 className="text-xl font-bold text-foreground">{activePeriod.name}</h2>
                                <p className="text-xs text-muted-foreground flex items-center gap-2">
                                    <Calendar className="h-3.5 w-3.5" />
                                    Jadwal: {formatDate(activePeriod.open_date)} s/d {formatDate(activePeriod.close_date)}
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                <Link href={'/ppdb/registrations'}>
                                    <Button variant="secondary" size="sm" className="gap-1 text-xs">
                                        Lihat Semua Data
                                        <ArrowRight className="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                                <Link href={'/ppdb/placement'}>
                                    <Button size="sm" className="gap-1 text-xs bg-emerald-600 hover:bg-emerald-700 text-white">
                                        <GraduationCap className="h-4 w-4" />
                                        Penempatan Rombel
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="rounded-xl border border-amber-200 bg-amber-50/70 dark:bg-amber-950/30 p-4 text-amber-800 dark:text-amber-200 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <AlertCircle className="h-5 w-5 shrink-0 text-amber-600" />
                            <div>
                                <p className="text-sm font-semibold">Belum Ada Periode PPDB Aktif</p>
                                <p className="text-xs text-amber-700 dark:text-amber-300">
                                    Aktifkan salah satu periode atau buat periode baru agar pendaftar dapat dikelompokkan secara rapi.
                                </p>
                            </div>
                        </div>
                        <Button size="sm" variant="outline" onClick={() => setPeriodDialogOpen(true)}>
                            Buat Sekarang
                        </Button>
                    </div>
                )}

                {/* Grid Statistik 4 Tahap */}
                <div className="grid grid-cols-2 lg:grid-cols-5 gap-3.5">
                    <Card className="shadow-sm border-border/80">
                        <CardHeader className="p-4 pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Total Pendaftar
                                </CardTitle>
                                <div className="h-8 w-8 rounded-lg bg-blue-50 dark:bg-blue-950/50 flex items-center justify-center text-blue-600">
                                    <Users className="h-4 w-4" />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-4 pt-0">
                            <div className="text-2xl font-bold text-foreground">{stats.total}</div>
                            <p className="text-[11px] text-muted-foreground mt-1">
                                Seluruh berkas masuk
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="shadow-sm border-border/80">
                        <CardHeader className="p-4 pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    1. Registered
                                </CardTitle>
                                <div className="h-8 w-8 rounded-lg bg-sky-50 dark:bg-sky-950/50 flex items-center justify-center text-sky-600">
                                    <Clock className="h-4 w-4" />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-4 pt-0">
                            <div className="text-2xl font-bold text-sky-600 dark:text-sky-400">{stats.registered}</div>
                            <p className="text-[11px] text-muted-foreground mt-1">
                                Menunggu daftar ulang
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="shadow-sm border-border/80">
                        <CardHeader className="p-4 pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    2. Daftar Ulang
                                </CardTitle>
                                <div className="h-8 w-8 rounded-lg bg-amber-50 dark:bg-amber-950/50 flex items-center justify-center text-amber-600">
                                    <FileCheck2 className="h-4 w-4" />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-4 pt-0">
                            <div className="text-2xl font-bold text-amber-600 dark:text-amber-400">{stats.re_enrolled}</div>
                            <p className="text-[11px] text-muted-foreground mt-1">
                                Menunggu verifikasi berkas
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="shadow-sm border-border/80">
                        <CardHeader className="p-4 pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    3. Terverifikasi
                                </CardTitle>
                                <div className="h-8 w-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 flex items-center justify-center text-indigo-600">
                                    <ShieldCheck className="h-4 w-4" />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-4 pt-0">
                            <div className="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{stats.verified}</div>
                            <p className="text-[11px] text-muted-foreground mt-1">
                                Siap penempatan rombel
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="shadow-sm border-border/80 col-span-2 lg:col-span-1">
                        <CardHeader className="p-4 pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    4. Ditempatkan
                                </CardTitle>
                                <div className="h-8 w-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 flex items-center justify-center text-emerald-600">
                                    <GraduationCap className="h-4 w-4" />
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-4 pt-0">
                            <div className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{stats.placed}</div>
                            <p className="text-[11px] text-muted-foreground mt-1">
                                Resmi masuk kelas 7
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Konten 2 Kolom: Pendaftar Terbaru & Riwayat Periode */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    {/* Kolom Kiri: Pendaftar Terbaru (7 cols) */}
                    <div className="lg:col-span-7 space-y-4">
                        <Card className="shadow-sm">
                            <CardHeader className="p-5 pb-3 flex flex-row items-center justify-between space-y-0">
                                <div>
                                    <CardTitle className="text-base font-semibold">Pendaftar Terbaru</CardTitle>
                                    <CardDescription className="text-xs">
                                        5 calon siswa yang baru saja terdaftar di sistem.
                                    </CardDescription>
                                </div>
                                <Link href={'/ppdb/registrations'}>
                                    <Button variant="ghost" size="sm" className="text-xs gap-1">
                                        Semua Data
                                        <ChevronRight className="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                            </CardHeader>
                            <CardContent className="p-0">
                                {recentRegistrations.length === 0 ? (
                                    <div className="p-8 text-center text-sm text-muted-foreground">
                                        Belum ada data pendaftar pada periode ini.
                                    </div>
                                ) : (
                                    <div className="divide-y divide-border/60">
                                        {recentRegistrations.map((reg) => {
                                            const statusInfo = STATUS_CONFIG[reg.status] || STATUS_CONFIG.registered;
                                            return (
                                                <div
                                                    key={reg.id}
                                                    className="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors"
                                                >
                                                    <div className="min-w-0 pr-4">
                                                        <div className="flex items-center gap-2">
                                                            <Link
                                                                href={`/ppdb/registrations/${reg.id}`}
                                                                className="text-sm font-semibold text-foreground hover:text-primary transition-colors truncate"
                                                            >
                                                                {reg.name}
                                                            </Link>
                                                            <span className="text-[10px] font-mono bg-muted px-1.5 py-0.5 rounded text-muted-foreground shrink-0">
                                                                {reg.registration_number}
                                                            </span>
                                                        </div>
                                                        <p className="text-xs text-muted-foreground mt-0.5 truncate">
                                                            {reg.gender === 'L' ? 'Laki-laki' : 'Perempuan'} • Asal: {reg.prev_school_name || 'Tidak dicantumkan'}
                                                        </p>
                                                    </div>

                                                    <div className="flex items-center gap-3 shrink-0">
                                                        <Badge variant="outline" className={`text-[11px] font-medium ${statusInfo.badge}`}>
                                                            {statusInfo.label}
                                                        </Badge>
                                                        <Link href={`/ppdb/registrations/${reg.id}`}>
                                                            <Button variant="outline" size="sm" className="h-7 px-2 text-xs">
                                                                Detail
                                                            </Button>
                                                        </Link>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Kolom Kanan: Daftar Periode PPDB (5 cols) */}
                    <div className="lg:col-span-5 space-y-4">
                        <Card className="shadow-sm">
                            <CardHeader className="p-5 pb-3">
                                <CardTitle className="text-base font-semibold">Daftar Periode PPDB</CardTitle>
                                <CardDescription className="text-xs">
                                    Riwayat gelombang dan tahun ajaran pendaftaran.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="p-4 pt-0 space-y-3">
                                {periods.length === 0 ? (
                                    <div className="py-6 text-center text-xs text-muted-foreground">
                                        Belum ada periode PPDB terdaftar.
                                    </div>
                                ) : (
                                    periods.map((p) => (
                                        <div
                                            key={p.id}
                                            className={`p-3.5 rounded-lg border transition-all ${
                                                p.is_active
                                                    ? 'border-primary/40 bg-primary/5 dark:bg-primary/10'
                                                    : 'border-border/70 hover:border-border'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <h4 className="text-sm font-semibold text-foreground">{p.name}</h4>
                                                        {p.is_active && (
                                                            <Badge className="bg-emerald-600 text-white text-[10px] h-4 px-1.5">
                                                                Aktif
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-muted-foreground mt-0.5">
                                                        TA {p.academic_year?.year} • {p.registrations_count ?? 0} Pendaftar
                                                    </p>
                                                    <p className="text-[11px] text-muted-foreground/80 mt-1">
                                                        {formatDate(p.open_date)} - {formatDate(p.close_date)}
                                                    </p>
                                                </div>

                                                <Button
                                                    size="sm"
                                                    variant={p.is_active ? 'outline' : 'secondary'}
                                                    className="h-7 text-xs px-2 shrink-0"
                                                    onClick={() => handleTogglePeriodActive(p.id)}
                                                >
                                                    {p.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                                </Button>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
