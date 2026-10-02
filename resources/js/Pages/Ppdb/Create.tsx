import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    ArrowLeft, Save, UserPlus, School, Home, Users,
    FileText, Sparkles, AlertCircle
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';

interface Period {
    id: string;
    name: string;
    is_active: boolean;
}

interface Props {
    activePeriod: Period | null;
    periods: Period[];
}

export default function CreateRegistration({ activePeriod, periods }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        ppdb_period_id: activePeriod?.id || (periods[0]?.id ?? ''),
        name: '',
        gender: 'L',
        birth_place: '',
        birth_date: '',
        religion: 'Islam',
        nisn: '',
        nik: '',
        phone_number: '',

        // Alamat
        address: '',
        rt: '',
        rw: '',
        village: '',
        district: '',
        regency: 'Sumedang',
        postal_code: '',

        // Asal Sekolah
        prev_school_name: '',
        prev_school_npsn: '',
        graduation_year: new Date().getFullYear().toString(),

        // Data Ayah
        father_name: '',
        father_nik: '',
        father_job: '',
        father_income: '',
        father_phone: '',

        // Data Ibu
        mother_name: '',
        mother_nik: '',
        mother_job: '',
        mother_income: '',
        mother_phone: '',

        // Data Wali
        guardian_name: '',
        guardian_nik: '',
        guardian_job: '',
        guardian_phone: '',

        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/ppdb/registrations');
    };

    return (
        <AppLayout>
            <Head title="Input Pendaftar PPDB" />

            <div className="max-w-5xl mx-auto space-y-6 pb-12">
                {/* Top Nav & Title */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link href={'/ppdb/registrations'}>
                            <Button variant="outline" size="sm" className="h-8 px-2.5">
                                <ArrowLeft className="h-4 w-4 mr-1" />
                                Kembali
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight text-foreground">
                                Form Input Pendaftar Calon Siswa
                            </h1>
                            <p className="text-xs text-muted-foreground">
                                Input biodata calon peserta didik baru oleh staff/panitia PPDB.
                            </p>
                        </div>
                    </div>

                    <Button
                        type="submit"
                        form="ppdb-create-form"
                        disabled={processing}
                        className="gap-1.5 shadow-sm"
                    >
                        <Save className="h-4 w-4" />
                        {processing ? 'Menyimpan...' : 'Simpan Pendaftar'}
                    </Button>
                </div>

                <form id="ppdb-create-form" onSubmit={handleSubmit} className="space-y-6">
                    {/* 1. Periode PPDB */}
                    <Card className="shadow-sm border-primary/20 bg-primary/5 dark:bg-primary/10">
                        <CardHeader className="p-4 pb-2">
                            <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                <FileText className="h-4 w-4 text-primary" />
                                Gelombang / Periode PPDB
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-4 pt-0">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="ppdb_period_id">Pilih Periode</Label>
                                    <Select
                                        value={data.ppdb_period_id}
                                        onValueChange={(val) => setData('ppdb_period_id', val)}
                                    >
                                        <SelectTrigger className="bg-background">
                                            <SelectValue placeholder="Pilih Periode PPDB" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {periods.map((p) => (
                                                <SelectItem key={p.id} value={p.id}>
                                                    {p.name} {p.is_active && '(Periode Aktif)'}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.ppdb_period_id && (
                                        <p className="text-xs text-destructive">{errors.ppdb_period_id}</p>
                                    )}
                                </div>
                                <div className="text-xs text-muted-foreground flex items-center pt-2">
                                    Calon siswa akan otomatis mendapatkan nomor pendaftaran baru berformat{' '}
                                    <span className="font-mono font-semibold ml-1 text-foreground">
                                        PPDB-{new Date().getFullYear()}-XXXX
                                    </span>
                                    .
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* 2. Biodata Calon Siswa */}
                    <Card className="shadow-sm">
                        <CardHeader className="p-5 pb-3">
                            <CardTitle className="text-base font-semibold flex items-center gap-2">
                                <UserPlus className="h-4 w-4 text-primary" />
                                Data Pribadi Calon Siswa
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Pastikan penulisan nama lengkap dan NIK sesuai dokumen Kartu Keluarga / Akta Kelahiran.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-5 pt-0 space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="name" className="text-xs font-semibold">
                                    Nama Lengkap <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="name"
                                    placeholder="NAMA LENGKAP SISWA (OTOMATIS KAPITAL)"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value.toUpperCase())}
                                    required
                                />
                                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="gender" className="text-xs font-semibold">
                                        Jenis Kelamin <span className="text-destructive">*</span>
                                    </Label>
                                    <Select value={data.gender} onValueChange={(val) => setData('gender', val)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih Jenis Kelamin" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="L">Laki-laki (L)</SelectItem>
                                            <SelectItem value="P">Perempuan (P)</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="nisn" className="text-xs font-semibold">
                                        NISN (10 Digit)
                                    </Label>
                                    <Input
                                        id="nisn"
                                        placeholder="Contoh: 0081234567"
                                        maxLength={10}
                                        value={data.nisn}
                                        onChange={(e) => setData('nisn', e.target.value)}
                                    />
                                    {errors.nisn && <p className="text-xs text-destructive">{errors.nisn}</p>}
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="nik" className="text-xs font-semibold">
                                        NIK Calon Siswa (16 Digit)
                                    </Label>
                                    <Input
                                        id="nik"
                                        placeholder="3211..."
                                        maxLength={16}
                                        value={data.nik}
                                        onChange={(e) => setData('nik', e.target.value)}
                                    />
                                    {errors.nik && <p className="text-xs text-destructive">{errors.nik}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="birth_place" className="text-xs font-semibold">
                                        Tempat Lahir <span className="text-destructive">*</span>
                                    </Label>
                                    <Input
                                        id="birth_place"
                                        placeholder="Contoh: Sumedang"
                                        value={data.birth_place}
                                        onChange={(e) => setData('birth_place', e.target.value)}
                                        required
                                    />
                                    {errors.birth_place && (
                                        <p className="text-xs text-destructive">{errors.birth_place}</p>
                                    )}
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="birth_date" className="text-xs font-semibold">
                                        Tanggal Lahir <span className="text-destructive">*</span>
                                    </Label>
                                    <Input
                                        id="birth_date"
                                        type="date"
                                        value={data.birth_date}
                                        onChange={(e) => setData('birth_date', e.target.value)}
                                        required
                                    />
                                    {errors.birth_date && (
                                        <p className="text-xs text-destructive">{errors.birth_date}</p>
                                    )}
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="phone_number" className="text-xs font-semibold">
                                        No. WhatsApp / HP Siswa
                                    </Label>
                                    <Input
                                        id="phone_number"
                                        placeholder="08..."
                                        value={data.phone_number}
                                        onChange={(e) => setData('phone_number', e.target.value)}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* 3. Asal Sekolah */}
                    <Card className="shadow-sm">
                        <CardHeader className="p-5 pb-3">
                            <CardTitle className="text-base font-semibold flex items-center gap-2">
                                <School className="h-4 w-4 text-primary" />
                                Data Sekolah Asal (SD / MI)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-5 pt-0 space-y-4">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div className="sm:col-span-2 space-y-1.5">
                                    <Label htmlFor="prev_school_name" className="text-xs font-semibold">
                                        Nama Sekolah / Madrasah Asal
                                    </Label>
                                    <Input
                                        id="prev_school_name"
                                        placeholder="Contoh: SDN Ujungjaya I / MI Al-Hidayah"
                                        value={data.prev_school_name}
                                        onChange={(e) => setData('prev_school_name', e.target.value)}
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="graduation_year" className="text-xs font-semibold">
                                        Tahun Lulus
                                    </Label>
                                    <Input
                                        id="graduation_year"
                                        placeholder="2026"
                                        maxLength={4}
                                        value={data.graduation_year}
                                        onChange={(e) => setData('graduation_year', e.target.value)}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* 4. Alamat Tempat Tinggal */}
                    <Card className="shadow-sm">
                        <CardHeader className="p-5 pb-3">
                            <CardTitle className="text-base font-semibold flex items-center gap-2">
                                <Home className="h-4 w-4 text-primary" />
                                Alamat Tempat Tinggal
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-5 pt-0 space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="address" className="text-xs font-semibold">
                                    Alamat Jalan / Dusun / Blok
                                </Label>
                                <Input
                                    id="address"
                                    placeholder="Contoh: Jl. Raden Ali Sadikin No. 12, Dusun Sukasari"
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                />
                            </div>

                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="rt" className="text-xs font-semibold">RT</Label>
                                    <Input
                                        id="rt"
                                        placeholder="001"
                                        maxLength={5}
                                        value={data.rt}
                                        onChange={(e) => setData('rt', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="rw" className="text-xs font-semibold">RW</Label>
                                    <Input
                                        id="rw"
                                        placeholder="002"
                                        maxLength={5}
                                        value={data.rw}
                                        onChange={(e) => setData('rw', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="village" className="text-xs font-semibold">Desa / Kelurahan</Label>
                                    <Input
                                        id="village"
                                        placeholder="Ujungjaya"
                                        value={data.village}
                                        onChange={(e) => setData('village', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="district" className="text-xs font-semibold">Kecamatan</Label>
                                    <Input
                                        id="district"
                                        placeholder="Ujungjaya"
                                        value={data.district}
                                        onChange={(e) => setData('district', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="regency" className="text-xs font-semibold">Kabupaten / Kota</Label>
                                    <Input
                                        id="regency"
                                        value={data.regency}
                                        onChange={(e) => setData('regency', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="postal_code" className="text-xs font-semibold">Kode Pos</Label>
                                    <Input
                                        id="postal_code"
                                        placeholder="45383"
                                        maxLength={6}
                                        value={data.postal_code}
                                        onChange={(e) => setData('postal_code', e.target.value)}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* 5. Data Orang Tua & Wali */}
                    <Card className="shadow-sm">
                        <CardHeader className="p-5 pb-3">
                            <CardTitle className="text-base font-semibold flex items-center gap-2">
                                <Users className="h-4 w-4 text-primary" />
                                Data Orang Tua / Wali
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-5 pt-0 space-y-6">
                            {/* Data Ayah */}
                            <div className="space-y-3">
                                <h4 className="text-xs font-bold text-foreground uppercase tracking-wider bg-muted/60 p-2 rounded">
                                    A. Data Ayah Kandung
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="father_name" className="text-xs">Nama Ayah</Label>
                                        <Input
                                            id="father_name"
                                            placeholder="Nama lengkap ayah"
                                            value={data.father_name}
                                            onChange={(e) => setData('father_name', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="father_job" className="text-xs">Pekerjaan</Label>
                                        <Input
                                            id="father_job"
                                            placeholder="Wiraswasta / Petani / dll"
                                            value={data.father_job}
                                            onChange={(e) => setData('father_job', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="father_phone" className="text-xs">No. HP / WhatsApp</Label>
                                        <Input
                                            id="father_phone"
                                            placeholder="08..."
                                            value={data.father_phone}
                                            onChange={(e) => setData('father_phone', e.target.value)}
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Data Ibu */}
                            <div className="space-y-3">
                                <h4 className="text-xs font-bold text-foreground uppercase tracking-wider bg-muted/60 p-2 rounded">
                                    B. Data Ibu Kandung
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="mother_name" className="text-xs">Nama Ibu</Label>
                                        <Input
                                            id="mother_name"
                                            placeholder="Nama lengkap ibu"
                                            value={data.mother_name}
                                            onChange={(e) => setData('mother_name', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="mother_job" className="text-xs">Pekerjaan</Label>
                                        <Input
                                            id="mother_job"
                                            placeholder="Ibu Rumah Tangga / dll"
                                            value={data.mother_job}
                                            onChange={(e) => setData('mother_job', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="mother_phone" className="text-xs">No. HP / WhatsApp</Label>
                                        <Input
                                            id="mother_phone"
                                            placeholder="08..."
                                            value={data.mother_phone}
                                            onChange={(e) => setData('mother_phone', e.target.value)}
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Data Wali (Opsional) */}
                            <div className="space-y-3">
                                <h4 className="text-xs font-bold text-muted-foreground uppercase tracking-wider bg-muted/40 p-2 rounded">
                                    C. Data Wali (Opsional, jika tinggal bersama wali)
                                </h4>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="guardian_name" className="text-xs">Nama Wali</Label>
                                        <Input
                                            id="guardian_name"
                                            placeholder="Nama lengkap wali"
                                            value={data.guardian_name}
                                            onChange={(e) => setData('guardian_name', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="guardian_job" className="text-xs">Pekerjaan Wali</Label>
                                        <Input
                                            id="guardian_job"
                                            value={data.guardian_job}
                                            onChange={(e) => setData('guardian_job', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="guardian_phone" className="text-xs">No. HP Wali</Label>
                                        <Input
                                            id="guardian_phone"
                                            value={data.guardian_phone}
                                            onChange={(e) => setData('guardian_phone', e.target.value)}
                                        />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* 6. Catatan Khusus */}
                    <Card className="shadow-sm">
                        <CardHeader className="p-4 pb-2">
                            <CardTitle className="text-sm font-semibold">Catatan Khusus (Opsional)</CardTitle>
                        </CardHeader>
                        <CardContent className="p-4 pt-0">
                            <Textarea
                                rows={3}
                                placeholder="Prestasi siswa, kebutuhan khusus, atau keterangan lainnya..."
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                            />
                        </CardContent>
                    </Card>

                    {/* Bottom Submit Actions */}
                    <div className="flex items-center justify-end gap-3 pt-2">
                        <Link href={'/ppdb/registrations'}>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing} className="gap-1.5 px-6">
                            <Save className="h-4 w-4" />
                            {processing ? 'Menyimpan...' : 'Simpan Pendaftar'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
