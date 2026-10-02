import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    ArrowLeft, Edit, Trash2, Printer, CheckCircle2,
    Clock, FileCheck2, ShieldCheck,
    User, Home, School, Users, FileText, Phone,
    Calendar, MapPin, Sparkles, AlertCircle, Loader2, ExternalLink
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem,
    DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger
} from '@/components/ui/dropdown-menu';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader,
    AlertDialogTitle, AlertDialogTrigger
} from '@/components/ui/alert-dialog';

interface Registration {
    id: string;
    registration_number: string;
    name: string;
    gender: 'L' | 'P';
    birth_place: string;
    birth_date: string;
    religion: string;
    nisn?: string;
    nik?: string;
    phone_number?: string;
    address?: string;
    rt?: string;
    rw?: string;
    village?: string;
    district?: string;
    regency?: string;
    postal_code?: string;
    prev_school_name?: string;
    prev_school_npsn?: string;
    graduation_year?: string;
    father_name?: string;
    father_nik?: string;
    father_job?: string;
    father_income?: number;
    father_phone?: string;
    mother_name?: string;
    mother_nik?: string;
    mother_job?: string;
    mother_income?: number;
    mother_phone?: string;
    guardian_name?: string;
    guardian_nik?: string;
    guardian_job?: string;
    guardian_phone?: string;
    notes?: string;
    status: 'registered' | 're_enrolled' | 'verified' | 'placed';
    created_at: string;
    updated_at: string;
    period?: {
        id: string;
        name: string;
        academic_year?: {
            year: string;
            semester: string;
        };
    };
    documents?: {
        id: string;
        document_type: string;
        document_name?: string;
        file_path: string;
        is_verified: boolean;
        status?: string;
        gdrive_view_url?: string;
        upload_retries?: number;
    }[];
    converted_student?: {
        id: string;
        name: string;
        nis_local?: string;
        status: string;
        cohorts?: {
            rombel?: {
                id: string;
                name: string;
            };
        }[];
    };
}

interface Props {
    registration: Registration;
}

const PIPELINE_STEPS = [
    { key: 'registered', label: '1. Registered', icon: Clock },
    { key: 're_enrolled', label: '2. Daftar Ulang', icon: FileCheck2 },
    { key: 'verified', label: '3. Terverifikasi', icon: ShieldCheck },
];

export default function ShowRegistration({ registration }: Props) {
    const [activeTab, setActiveTab] = useState('biodata');
    const [isScanning, setIsScanning] = useState(false);

    const handleUpdateStatus = (newStatus: string) => {
        router.put(`/ppdb/registrations/${registration.id}/status`, { status: newStatus }, {
            preserveScroll: true,
        });
    };

    const handleDelete = () => {
        router.delete(`/ppdb/registrations/${registration.id}`);
    };

    const handlePrint = () => {
        window.print();
    };

    // Calculate current step index
    const currentStepIndex = PIPELINE_STEPS.findIndex(s => s.key === registration.status);

    const formatDate = (dateStr?: string) => {
        if (!dateStr) return '-';
        return new Date(dateStr).toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    };

    const formatCurrency = (amount?: number) => {
        if (!amount) return '-';
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0,
        }).format(amount);
    };

    return (
        <AppLayout>
            <Head title={`Detail Pendaftar - ${registration.name}`} />

            <div className="max-w-5xl mx-auto space-y-6 pb-12 print:p-0 print:m-0 print:max-w-none">
                {/* Top Nav & Action Header (Hidden on print) */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 print:hidden">
                    <div className="flex items-center gap-3">
                        <Link href={'/ppdb/registrations'}>
                            <Button variant="outline" size="sm" className="h-8 px-2.5">
                                <ArrowLeft className="h-4 w-4 mr-1" />
                                Daftar Pendaftar
                            </Button>
                        </Link>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-bold tracking-tight text-foreground">
                                    {registration.name}
                                </h1>
                                <Badge variant="outline" className="font-mono text-xs">
                                    {registration.registration_number}
                                </Badge>
                            </div>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                {registration.period?.name || 'PPDB Madrasah'} • Terdaftar pada{' '}
                                {formatDate(registration.created_at)}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={handlePrint} className="gap-1 text-xs">
                            <Printer className="h-3.5 w-3.5" />
                            Cetak Bukti
                        </Button>

                        <Link href={`/ppdb/registrations/${registration.id}/edit`}>
                            <Button variant="outline" size="sm" className="gap-1 text-xs">
                                <Edit className="h-3.5 w-3.5" />
                                Edit
                            </Button>
                        </Link>

                        {/* Status Transition Action Buttons */}
                        {registration.status === 'registered' && (
                            <Button
                                size="sm"
                                onClick={() => handleUpdateStatus('re_enrolled')}
                                className="gap-1 text-xs bg-amber-600 hover:bg-amber-700 text-white shadow-sm"
                            >
                                <FileCheck2 className="h-3.5 w-3.5" />
                                Konfirmasi Daftar Ulang
                            </Button>
                        )}

                        {registration.status === 're_enrolled' && (
                            <Button
                                size="sm"
                                onClick={() => handleUpdateStatus('verified')}
                                className="gap-1 text-xs bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm"
                            >
                                <ShieldCheck className="h-3.5 w-3.5" />
                                Verifikasi Berkas
                            </Button>
                        )}

                        {registration.status !== 'placed' && (
                            <AlertDialog>
                                <AlertDialogTrigger render={<Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-destructive hover:text-destructive" />}>
                                        <Trash2 className="h-4 w-4" />
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>Hapus Calon Siswa Ini?</AlertDialogTitle>
                                        <AlertDialogDescription>
                                            Apakah Anda yakin ingin menghapus data calon siswa {registration.name}? Tindakan ini tidak dapat dibatalkan.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>Batal</AlertDialogCancel>
                                        <AlertDialogAction onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                                            Hapus
                                        </AlertDialogAction>
                                    </AlertDialogFooter>
                                </AlertDialogContent>
                            </AlertDialog>
                        )}
                    </div>
                </div>

                {/* Pipeline Status Progress Banner */}
                <Card className="shadow-sm border-border/80 overflow-hidden print:border-none print:shadow-none">
                    <CardContent className="p-4 sm:p-5">
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                            {PIPELINE_STEPS.map((step, idx) => {
                                const Icon = step.icon;
                                const isDone = idx < currentStepIndex;
                                const isCurrent = idx === currentStepIndex;

                                return (
                                    <div
                                        key={step.key}
                                        className={`relative p-3 rounded-lg border flex flex-col justify-between transition-all ${
                                            isCurrent
                                                ? 'border-primary bg-primary/5 dark:bg-primary/10 ring-1 ring-primary'
                                                : isDone
                                                ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/50 dark:bg-emerald-950/20'
                                                : 'border-border/50 bg-muted/20 opacity-60'
                                        }`}
                                    >
                                        <div className="flex items-center justify-between mb-1.5">
                                            <span className="text-xs font-semibold text-foreground">
                                                {step.label}
                                            </span>
                                            {isDone ? (
                                                <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                            ) : isCurrent ? (
                                                <Icon className="h-4 w-4 text-primary animate-pulse" />
                                            ) : (
                                                <Icon className="h-4 w-4 text-muted-foreground" />
                                            )}
                                        </div>
                                        <span className="text-[11px] text-muted-foreground">
                                            {isDone
                                                ? 'Selesai'
                                                : isCurrent
                                                ? 'Tahap Saat Ini'
                                                : 'Menunggu'}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* Main Tabs */}
                <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-4">
                    <TabsList className="bg-muted/60 p-1 print:hidden">
                        <TabsTrigger value="biodata" className="text-xs gap-1.5">
                            <User className="h-3.5 w-3.5" />
                            Biodata Calon Siswa
                        </TabsTrigger>
                        <TabsTrigger value="berkas" className="text-xs gap-1.5">
                            <FileText className="h-3.5 w-3.5" />
                            Berkas Persyaratan ({registration.documents?.length || 0})
                        </TabsTrigger>
                    </TabsList>

                    {/* Tab 1: Biodata */}
                    <TabsContent value="biodata" className="space-y-4">
                        {/* Section 1: Data Pribadi */}
                        <Card className="shadow-sm">
                            <CardHeader className="p-4 pb-2 border-b border-border/40">
                                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                    <User className="h-4 w-4 text-primary" />
                                    Data Pribadi Calon Siswa
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-y-3.5 gap-x-4 text-sm">
                                <div>
                                    <span className="text-xs text-muted-foreground block">Nama Lengkap</span>
                                    <span className="font-semibold text-foreground">{registration.name}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Jenis Kelamin</span>
                                    <span className="text-foreground">
                                        {registration.gender === 'L' ? 'Laki-laki (L)' : 'Perempuan (P)'}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Tempat, Tanggal Lahir</span>
                                    <span className="text-foreground">
                                        {registration.birth_place}, {formatDate(registration.birth_date)}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">NISN</span>
                                    <span className="font-mono text-foreground">{registration.nisn || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">NIK</span>
                                    <span className="font-mono text-foreground">{registration.nik || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Agama</span>
                                    <span className="text-foreground">{registration.religion || 'Islam'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">No. HP / WhatsApp</span>
                                    <span className="text-foreground">{registration.phone_number || '-'}</span>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Section 2: Alamat */}
                        <Card className="shadow-sm">
                            <CardHeader className="p-4 pb-2 border-b border-border/40">
                                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                    <Home className="h-4 w-4 text-primary" />
                                    Alamat Tempat Tinggal
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-y-3.5 gap-x-4 text-sm">
                                <div className="sm:col-span-2 md:col-span-3">
                                    <span className="text-xs text-muted-foreground block">Alamat Lengkap</span>
                                    <span className="text-foreground">{registration.address || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">RT / RW</span>
                                    <span className="text-foreground">
                                        RT {registration.rt || '-'} / RW {registration.rw || '-'}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Kelurahan / Desa</span>
                                    <span className="text-foreground">{registration.village || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Kecamatan</span>
                                    <span className="text-foreground">{registration.district || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Kabupaten / Kota</span>
                                    <span className="text-foreground">{registration.regency || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Kode Pos</span>
                                    <span className="text-foreground">{registration.postal_code || '-'}</span>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Section 3: Asal Sekolah */}
                        <Card className="shadow-sm">
                            <CardHeader className="p-4 pb-2 border-b border-border/40">
                                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                    <School className="h-4 w-4 text-primary" />
                                    Pendidikan Sebelumnya (SD / MI)
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-4 grid grid-cols-1 sm:grid-cols-3 gap-y-3.5 gap-x-4 text-sm">
                                <div className="sm:col-span-2">
                                    <span className="text-xs text-muted-foreground block">Nama Sekolah Asal</span>
                                    <span className="font-medium text-foreground">{registration.prev_school_name || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Tahun Kelulusan</span>
                                    <span className="text-foreground">{registration.graduation_year || '-'}</span>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Section 4: Data Orang Tua */}
                        <Card className="shadow-sm">
                            <CardHeader className="p-4 pb-2 border-b border-border/40">
                                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                    <Users className="h-4 w-4 text-primary" />
                                    Data Orang Tua / Wali
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-4 space-y-4 text-sm">
                                {/* Ayah */}
                                <div>
                                    <h4 className="text-xs font-bold uppercase text-foreground bg-muted/60 px-2 py-1 rounded mb-2">
                                        Data Ayah Kandung
                                    </h4>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                                        <div>
                                            <span className="text-xs text-muted-foreground block">Nama Ayah</span>
                                            <span className="font-medium text-foreground">{registration.father_name || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground block">NIK Ayah</span>
                                            <span className="font-mono text-foreground">{registration.father_nik || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground block">Pekerjaan</span>
                                            <span className="text-foreground">{registration.father_job || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground block">No. HP / WA</span>
                                            <span className="text-foreground">{registration.father_phone || '-'}</span>
                                        </div>
                                    </div>
                                </div>

                                {/* Ibu */}
                                <div>
                                    <h4 className="text-xs font-bold uppercase text-foreground bg-muted/60 px-2 py-1 rounded mb-2">
                                        Data Ibu Kandung
                                    </h4>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                                        <div>
                                            <span className="text-xs text-muted-foreground block">Nama Ibu</span>
                                            <span className="font-medium text-foreground">{registration.mother_name || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground block">NIK Ibu</span>
                                            <span className="font-mono text-foreground">{registration.mother_nik || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground block">Pekerjaan</span>
                                            <span className="text-foreground">{registration.mother_job || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground block">No. HP / WA</span>
                                            <span className="text-foreground">{registration.mother_phone || '-'}</span>
                                        </div>
                                    </div>
                                </div>

                                {/* Wali jika ada */}
                                {registration.guardian_name && (
                                    <div>
                                        <h4 className="text-xs font-bold uppercase text-muted-foreground bg-muted/40 px-2 py-1 rounded mb-2">
                                            Data Wali
                                        </h4>
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <div>
                                                <span className="text-xs text-muted-foreground block">Nama Wali</span>
                                                <span className="font-medium text-foreground">{registration.guardian_name}</span>
                                            </div>
                                            <div>
                                                <span className="text-xs text-muted-foreground block">Pekerjaan</span>
                                                <span className="text-foreground">{registration.guardian_job || '-'}</span>
                                            </div>
                                            <div>
                                                <span className="text-xs text-muted-foreground block">No. HP</span>
                                                <span className="text-foreground">{registration.guardian_phone || '-'}</span>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Catatan Khusus jika ada */}
                        {registration.notes && (
                            <Card className="shadow-sm">
                                <CardHeader className="p-4 pb-2 border-b border-border/40">
                                    <CardTitle className="text-sm font-semibold">Catatan Khusus</CardTitle>
                                </CardHeader>
                                <CardContent className="p-4 text-sm text-foreground">
                                    {registration.notes}
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>

                    {/* Tab 2: Berkas Persyaratan */}
                    <TabsContent value="berkas" className="space-y-4">
                        <Card className="shadow-sm">
                            <CardHeader className="p-5 pb-3">
                                <CardTitle className="text-base font-semibold flex items-center gap-2">
                                    <FileText className="h-4 w-4 text-primary" />
                                    Berkas Persyaratan Pendaftaran
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Dokumen lampiran calon siswa (Kartu Keluarga, Akta Kelahiran, Ijazah SD/MI, dll).
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="p-5 pt-0 space-y-4">
                                <div className="rounded-lg border border-dashed border-primary/40 bg-primary/5 p-6 flex flex-col items-center justify-center text-center">
                                    <Sparkles className="h-8 w-8 text-primary mx-auto mb-3" />
                                    <h4 className="text-base font-semibold text-foreground">Pindai Formulir PPDB (AI)</h4>
                                    <p className="text-sm text-muted-foreground max-w-md mx-auto mt-1 mb-4">
                                        Unggah Formulir PPDB yang telah diisi secara manual (PDF atau Gambar) untuk mengekstrak data menggunakan AI.
                                    </p>
                                    
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="file"
                                            id="document-upload"
                                            className="hidden"
                                            accept=".pdf,image/png,image/jpeg,image/jpg"
                                            onChange={(e) => {
                                                const file = e.target.files?.[0];
                                                if (!file) return;
                                                
                                                router.post(`/ppdb/registrations/${registration.id}/scan`, { document: file }, {
                                                    forceFormData: true,
                                                    preserveScroll: true,
                                                    onStart: () => setIsScanning(true),
                                                    onFinish: () => {
                                                        setIsScanning(false);
                                                        if (e.target) e.target.value = '';
                                                    },
                                                });
                                            }}
                                        />
                                        <Button 
                                            onClick={() => document.getElementById('document-upload')?.click()}
                                            disabled={isScanning}
                                            className="gap-2"
                                        >
                                            {isScanning ? (
                                                <>
                                                    <div className="h-4 w-4 rounded-full border-2 border-primary-foreground/20 border-t-primary-foreground animate-spin" />
                                                    Memindai AI (Tunggu ~10dtk)...
                                                </>
                                            ) : (
                                                <>
                                                    <FileCheck2 className="h-4 w-4" />
                                                    Pilih & Pindai Dokumen
                                                </>
                                            )}
                                        </Button>
                                    </div>
                                </div>

                                {(!registration.documents || registration.documents.length === 0) ? (
                                    <div className="py-8 text-center text-sm text-muted-foreground">
                                        Belum ada dokumen yang diunggah untuk pendaftar ini.
                                    </div>
                                ) : (
                                    <div className="divide-y divide-border/60">
                                        {registration.documents.map((doc) => (
                                            <div key={doc.id} className="py-3 flex flex-col gap-2">
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <p className="text-sm font-medium text-foreground">
                                                            {doc.document_name || doc.document_type}
                                                        </p>
                                                        <span className="text-xs text-muted-foreground flex items-center gap-2 mt-1">
                                                            {doc.status === 'failed' ? (
                                                                <span className="text-red-500 font-medium">Gagal Unggah ke GDrive</span>
                                                            ) : doc.status === 'pending' ? (
                                                                <span className="flex items-center gap-1 text-slate-500"><Loader2 className="h-3 w-3 animate-spin" /> Sedang diunggah...</span>
                                                            ) : (
                                                                <span className="text-emerald-600 font-medium">Tersimpan di GDrive</span>
                                                            )}
                                                            {doc.is_verified && <span className="px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">Terverifikasi AI</span>}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        {doc.status === 'failed' && (
                                                            <a href={`/archives/retry/ppdb/${doc.id}`} className="text-xs px-2 py-1 bg-red-100 text-red-600 hover:bg-red-200 rounded">
                                                                Coba Lagi
                                                            </a>
                                                        )}
                                                        {doc.gdrive_view_url && (
                                                            <a href={doc.gdrive_view_url} target="_blank" rel="noreferrer" className="text-blue-500 hover:text-blue-700">
                                                                <ExternalLink className="h-4 w-4" />
                                                            </a>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
