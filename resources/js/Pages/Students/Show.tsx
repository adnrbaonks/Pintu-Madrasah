import React, { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Progress } from '@/components/ui/progress';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import {
    Printer, ChevronLeft, Edit, ShieldCheck, Upload, FileText, FileImage,
    ExternalLink, Loader2, RefreshCw, CloudDownload, Save, X, Camera, History, Trash2
} from 'lucide-react';
import { toast } from 'sonner';

// ── Helper components — HARUS di luar Show agar tidak re-mount setiap render ──
const ViewField = ({ label, value }: { label: string; value?: string | null }) => (
    <div className="flex flex-col mb-3">
        <span className="text-sm text-slate-500">{label}</span>
        <span className="font-medium text-slate-900">{value || '-'}</span>
    </div>
);

const EField = ({
    label, value, onChange, type = 'text', required = false, error,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    type?: string;
    required?: boolean;
    error?: string;
}) => (
    <div className="flex flex-col mb-3 gap-1">
        <Label className="text-sm text-slate-500">
            {label}{required && <span className="text-red-500 ml-0.5">*</span>}
        </Label>
        <Input
            type={type}
            value={value}
            onChange={e => onChange(e.target.value)}
            className="h-8 text-sm"
            required={required}
        />
        {error && <p className="text-xs text-red-500">{error}</p>}
    </div>
);
// ────────────────────────────────────────────────────────────────────────────

export default function Show({ student, activityLogs }: { student: any; activityLogs: any[] }) {

    const [isEditing, setIsEditing] = useState(false);

    const formatDate = (dateStr: string | null | undefined) => {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' });
    };

    const { data, setData, put, processing, errors, reset } = useForm({
        name: student.name || '',
        nis_local: student.nis_local || '',
        nisn: student.nisn || '',
        nik: student.nik || '',
        gender: student.gender || 'L',
        birth_place: student.birth_place || '',
        birth_date: student.birth_date ? student.birth_date.substring(0, 10) : '',
        religion: student.religion || '',
        address: student.address || '',
        rt: student.rt || '',
        rw: student.rw || '',
        village: student.village || '',
        district: student.district || '',
        regency: student.regency || '',
        postal_code: student.postal_code || '',
        distance_to_school: student.distance_to_school || '',
        transportation: student.transportation || '',
        father_name: student.father_name || '',
        father_nik: student.father_nik || '',
        father_job: student.father_job || '',
        father_income: student.father_income || '',
        father_phone: student.father_phone || '',
        mother_name: student.mother_name || '',
        mother_nik: student.mother_nik || '',
        mother_job: student.mother_job || '',
        mother_income: student.mother_income || '',
        mother_phone: student.mother_phone || '',

        guardian_name: student.guardian_name || '',
        guardian_nik: student.guardian_nik || '',
        guardian_job: student.guardian_job || '',
        guardian_phone: student.guardian_phone || '',
        kip_number: student.kip_number || '',
        kk_number: student.kk_number || '',
        prev_school_name: student.prev_school_name || '',
        enrollment_type: student.enrollment_type || '',
        graduation_year: student.graduation_year || '',
        pip_eligible: student.pip_eligible ? '1' : '0',
        pkh_eligible: student.pkh_eligible ? '1' : '0',
        kks_eligible: student.kks_eligible ? '1' : '0',
    });

    const cancelEdit = () => { reset(); setIsEditing(false); };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/students/${student.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditing(false);
                toast.success('Data siswa berhasil diperbarui.');
                router.reload({ only: ['student'] });
            },
            onError: () => toast.error('Ada kesalahan saat menyimpan.'),
        });
    };

    // ── Dokumen ──────────────────────────────────────────────────────────────
    const [docData, setDocData] = useState<{ document_type: string; file: File | null }>({ document_type: '', file: null });
    const [processingDoc, setProcessingDoc] = useState(false);
    const [docProgress, setDocProgress] = useState<number>(0);
    const [docErrors, setDocErrors] = useState<Record<string, string>>({});
    const [updateDocType, setUpdateDocType] = useState<string | null>(null);
    const [showPhotoModal, setShowPhotoModal] = useState(false);
    const [photoFile, setPhotoFile] = useState<File | null>(null);
    const [processingPhoto, setProcessingPhoto] = useState(false);

    const handleUploadPhoto = () => {
        if (!photoFile) return;
        setProcessingPhoto(true);
        const formData = new FormData();
        formData.append('photo', photoFile);
        formData.append('_method', 'POST');
        router.post(`/students/${student.id}/photo`, formData as any, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { toast.success('Foto profil berhasil diperbarui!'); setShowPhotoModal(false); setPhotoFile(null); },
            onError: (e) => toast.error(e.photo || 'Gagal mengunggah foto.'),
            onFinish: () => setProcessingPhoto(false),
        });
    };

    const handleDeletePhoto = () => {
        if (!confirm('Hapus foto profil?')) return;
        router.delete(`/students/${student.id}/photo`, {
            preserveScroll: true,
            onSuccess: () => { toast.success('Foto profil dihapus.'); setShowPhotoModal(false); },
        });
    };

    const submitDocument = async (e: React.FormEvent, type: string) => {
        e.preventDefault();
        if (!docData.file) return;
        setDocErrors({});
        setProcessingDoc(true);
        setDocProgress(0);

        const formData = new FormData();
        formData.append('document_type', type);
        formData.append('file', docData.file);
        formData.append('_token', (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '');

        try {
            await axios.post(`/students/${student.id}/documents`, formData, {
                headers: { 'Content-Type': 'multipart/form-data', 'X-Requested-With': 'XMLHttpRequest' },
                onUploadProgress: (event) => {
                    if (event.total) {
                        setDocProgress(Math.round((event.loaded * 100) / event.total));
                    }
                },
            });
            setDocData({ document_type: '', file: null });
            setDocProgress(0);
            router.reload({ only: ['student'] });
            toast.success('Dokumen berhasil diunggah. Sedang diproses oleh AI...');
        } catch (err: any) {
            const errors = err?.response?.data?.errors;
            if (errors) {
                const mapped: Record<string, string> = {};
                Object.keys(errors).forEach(k => { mapped[k] = errors[k][0]; });
                setDocErrors(mapped);
            }
            toast.error(err?.response?.data?.message || 'Gagal mengunggah. Coba lagi.');
        } finally {
            setProcessingDoc(false);
        }
    };


    const [isSyncingEmis, setIsSyncingEmis] = useState(false);
    const [showPreviewModal, setShowPreviewModal] = useState(false);
    const [previewChanges, setPreviewChanges] = useState<any[]>([]);
    const [updateData, setUpdateData] = useState<any>({});
    const [selectedChanges, setSelectedChanges] = useState<string[]>([]);
    const [isCommitting, setIsCommitting] = useState(false);

    // Auto-polling jika ada dokumen yang sedang diproses AI
    React.useEffect(() => {
        const hasProcessingDocs = student.documents?.some((doc: any) => doc.status === 'processing');
        if (hasProcessingDocs) {
            const interval = setInterval(() => {
                router.reload({ only: ['student'] });
            }, 3000);
            return () => clearInterval(interval);
        }
    }, [student]);

    const handleSyncEmisDetail = async () => {
        setIsSyncingEmis(true);
        try {
            const response = await axios.post(`/students/${student.id}/preview-emis-detail`);
            const { changes, update_data } = response.data;

            if (changes && changes.length > 0) {
                setPreviewChanges(changes);
                setUpdateData(update_data);

                const autoSelected = changes
                    .filter((c: any) => c.old === '(Kosong)')
                    .map((c: any) => c.field);

                setSelectedChanges(autoSelected);
                setShowPreviewModal(true);
            } else {
                toast.info('Data sudah sinkron. Tidak ada perbedaan dengan server EMIS.');
            }
        } catch (error: any) {
            toast.error(error.response?.data?.message || 'Gagal menarik data dari EMIS.');
        } finally {
            setIsSyncingEmis(false);
        }
    };

    const handleCommitEmisDetail = () => {
        setIsCommitting(true);

        const finalUpdateData: any = {};
        selectedChanges.forEach(field => {
            if (updateData[field] !== undefined) {
                finalUpdateData[field] = updateData[field];
            }
        });

        if (Object.keys(finalUpdateData).length === 0) {
            toast.info('Tidak ada data yang dipilih untuk disinkronkan.');
            setIsCommitting(false);
            setShowPreviewModal(false);
            return;
        }

        router.post(`/students/${student.id}/commit-emis-detail`, { update_data: finalUpdateData }, {
            preserveScroll: true,
            onSuccess: (page) => {
                toast.success((page.props.flash as any)?.success || 'Berhasil menyimpan detail EMIS.');
                setShowPreviewModal(false);
            },
            onError: (errs) => toast.error((errs as any).message || 'Gagal menyimpan data ke database.'),
            onFinish: () => setIsCommitting(false),
        });
    };

    const handleRefresh = () => router.reload({ only: ['student'] });

    const docTypes = [
        { id: 'kartu_keluarga', label: 'Kartu Keluarga (KK)' },
        { id: 'akta_kelahiran', label: 'Akta Kelahiran' },
        { id: 'ijazah_sd', label: 'Ijazah SD/MI' },
    ];

    return (
        <>
        <AppLayout>
            <Head title={`Detail Siswa - ${student.name}`} />

            <form onSubmit={submitEdit}>
                <div className="flex flex-col gap-6 w-full mx-auto">

                    {/* Header */}
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div className="flex items-center gap-4">
                            <Link href="/students">
                                <Button type="button" variant="outline" size="icon">
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                            </Link>
                            {/* Avatar Foto Profil */}
                            <button
                                type="button"
                                onClick={() => setShowPhotoModal(true)}
                                className="relative w-14 h-14 rounded-full overflow-hidden border-2 border-slate-200 hover:border-blue-400 transition-colors group shrink-0 focus:outline-none focus:ring-2 focus:ring-blue-400"
                                title="Klik untuk ubah foto profil"
                            >
                                {student.profile_photo ? (
                                    <img
                                        src={student.profile_photo.startsWith("archives/") ? `/${student.profile_photo}` : `/storage/${student.profile_photo}`}
                                        alt={student.name}
                                        className="w-full h-full object-cover"
                                    />
                                ) : (
                                    <div className={`w-full h-full flex items-center justify-center text-xl font-bold text-white ${student.gender === 'P' ? 'bg-pink-400' : 'bg-blue-400'}`}>
                                        {student.name?.charAt(0)?.toUpperCase() || '?'}
                                    </div>
                                )}
                                <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <Camera className="h-5 w-5 text-white" />
                                </div>
                            </button>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                                        {student.name}
                                    </h1>
                                    {student.current_rombel_name && (
                                        <span className="text-lg font-medium text-slate-500">
                                            ({student.current_rombel_name})
                                        </span>
                                    )}
                                    {student.is_verified_by_ai && !isEditing && (
                                        <HoverCard>
                                            <HoverCardTrigger>
                                                <div className="flex items-center justify-center rounded-full bg-lime-100 p-1 cursor-help hover:bg-lime-200 transition-colors">
                                                    <ShieldCheck className="h-5 w-5 text-lime-600" />
                                                </div>
                                            </HoverCardTrigger>
                                            <HoverCardContent align="start" className="w-80 p-3 shadow-lg border-slate-200">
                                                <div className="space-y-2">
                                                    <h4 className="text-sm font-semibold flex items-center gap-2 text-lime-700">
                                                        <ShieldCheck className="h-4 w-4" />
                                                        Terverifikasi AI (Gemini)
                                                    </h4>
                                                    <p className="text-xs text-slate-600 leading-relaxed font-normal">
                                                        Identitas utama telah dicocokkan dengan dokumen asli. Tidak akan tertimpa saat sinkronisasi EMIS.
                                                    </p>
                                                </div>
                                            </HoverCardContent>
                                        </HoverCard>
                                    )}
                                </div>

                                {/* ── Compact Progress Bar ── */}
                                {(student as any).data_completeness && (
                                    <div className="flex items-center gap-3 mt-1.5">
                                        <div className="w-48">
                                            <Progress value={(student as any).data_completeness.percentage} className="h-2" />
                                        </div>
                                        <span className="text-xs font-medium text-slate-600">
                                            {(student as any).data_completeness.percentage}% Lengkap
                                        </span>
                                        {(student as any).data_completeness.missing_fields?.length > 0 && (
                                            <HoverCard>
                                                <HoverCardTrigger render={<Badge variant="outline" className="text-[10px] h-5 px-1.5 cursor-pointer border-red-200 text-red-600 bg-red-50 hover:bg-red-100 transition-colors" />}>
                                                        {(student as any).data_completeness.missing_fields.length} Kosong
                                                </HoverCardTrigger>
                                                <HoverCardContent className="w-72 p-3">
                                                    <div className="space-y-2">
                                                        <h4 className="text-xs font-semibold">Kolom belum diisi:</h4>
                                                        <div className="flex flex-wrap gap-1">
                                                            {(student as any).data_completeness.missing_fields.map((field: string, idx: number) => (
                                                                <span key={idx} className="text-[10px] bg-red-100 text-red-700 px-1.5 py-0.5 rounded border border-red-200">
                                                                    {field}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </HoverCardContent>
                                            </HoverCard>
                                        )}
                                        {(student as any).data_completeness.percentage === 100 && (
                                            <Badge variant="outline" className="text-[10px] h-5 px-1.5 border-lime-200 text-lime-700 bg-lime-50">
                                                Lengkap
                                            </Badge>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {isEditing ? (
                                <>
                                    <Button type="button" variant="outline" className="gap-2" onClick={cancelEdit}>
                                        <X className="h-4 w-4" /> Batal
                                    </Button>
                                    <Button type="submit" disabled={processing} className="gap-2 bg-slate-900 hover:bg-slate-800 text-white">
                                        {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                                        Simpan Perubahan
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <Button type="button" variant="outline" className="gap-2" onClick={() => setIsEditing(true)}>
                                        <Edit className="h-4 w-4" /> Edit Data
                                    </Button>
                                    <Button type="button" variant="outline" className="gap-2 text-lime-700 border-lime-300 hover:bg-lime-50" onClick={handleSyncEmisDetail} disabled={isSyncingEmis || !student.emis_student_id}>
                                        {isSyncingEmis ? <Loader2 className="h-4 w-4 animate-spin" /> : <CloudDownload className="h-4 w-4" />}
                                        Tarik Detail EMIS
                                    </Button>
                                    <a href={`/students/${student.id}/print-biodata`} target="_blank">
                                        <Button type="button" className="gap-2 bg-slate-900 hover:bg-slate-800 text-white">
                                            <Printer className="h-4 w-4" /> Cetak Biodata
                                        </Button>
                                    </a>
                                </>
                            )}
                        </div>
                    </div>

                    {/* Banner edit mode */}
                    {isEditing && (
                        <div className="bg-amber-50 border border-amber-200 text-amber-800 p-3 rounded-lg flex items-center gap-3 text-sm">
                            <Edit className="h-4 w-4 text-amber-500 shrink-0" />
                            <span>Mode edit aktif — ubah data yang diperlukan lalu klik <strong>Simpan Perubahan</strong>.</span>
                        </div>
                    )}

                    {/* Banner AI dipindah ke samping nama kelas */}

                    {/* Identitas + Dokumen */}
                    <div className="grid grid-cols-1 xl:grid-cols-3 gap-6 items-stretch">
                        <Card className="xl:col-span-2 h-full flex flex-col">
                            <CardHeader><CardTitle>Identitas Pribadi</CardTitle></CardHeader>
                            <CardContent className="flex-grow">
                                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-x-4">
                                    {isEditing ? (
                                        <>
                                            <div className="md:col-span-2">
                                                <EField label="Nama Lengkap" value={data.name} onChange={v => setData('name', v)} required error={errors.name} />
                                            </div>
                                            <div className="flex flex-col mb-3 gap-1">
                                                <Label className="text-sm text-slate-500">Jenis Kelamin <span className="text-red-500">*</span></Label>
                                                <Select value={data.gender} onValueChange={v => setData('gender', v)}>
                                                    <SelectTrigger className="h-8 text-sm"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="L">Laki-laki</SelectItem>
                                                        <SelectItem value="P">Perempuan</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <EField label="Tempat Lahir" value={data.birth_place} onChange={v => setData('birth_place', v)} />
                                            <EField label="Tanggal Lahir" value={data.birth_date} onChange={v => setData('birth_date', v)} type="date" />
                                            <EField label="Agama" value={data.religion} onChange={v => setData('religion', v)} />
                                            <EField label="NIK" value={data.nik} onChange={v => setData('nik', v)} />
                                            <EField label="NISN" value={data.nisn} onChange={v => setData('nisn', v)} />
                                            <EField label="NISM" value={data.nis_local} onChange={v => setData('nis_local', v)} />
                                            <EField label="Asal Sekolah Dasar" value={data.prev_school_name} onChange={v => setData('prev_school_name', v)} />
                                            <EField label="Tahun Lulus" value={data.graduation_year} onChange={v => setData('graduation_year', v)} type="number" />
                                            <EField label="Status Masuk" value={data.enrollment_type} onChange={v => setData('enrollment_type', v)} />
                                        </>
                                    ) : (
                                        <>
                                            <ViewField label="Nama Lengkap" value={student.name} />
                                            <ViewField label="NISN" value={student.nisn} />
                                            <ViewField label="NISM" value={student.nis_local} />
                                            <ViewField label="NIK" value={student.nik} />
                                            <ViewField label="Jenis Kelamin" value={student.gender === 'L' ? 'Laki-laki' : 'Perempuan'} />
                                            <ViewField label="Tempat Lahir" value={student.birth_place} />
                                            <ViewField label="Tanggal Lahir" value={formatDate(student.birth_date)} />
                                            <ViewField label="Agama" value={student.religion} />
                                            <ViewField label="Anak ke / Jml Saudara" value={`${student.child_order || '-'} dari ${student.siblings_count || '-'} bersaudara`} />
                                            <ViewField label="Status Siswa" value={student.status} />
                                            <ViewField label="Status Masuk" value={student.enrollment_type} />
                                            <ViewField label="Asal Sekolah Dasar" value={`${student.prev_school_name || '-'}${student.graduation_year ? ` (${student.graduation_year})` : ''}`} />
                                        </>
                                    )}
                                </div>

                                <Separator className="my-4" />
                                <h3 className="font-semibold mb-2 text-slate-900">Alamat Tempat Tinggal</h3>
                                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-x-4">
                                    {isEditing ? (
                                        <>
                                            <EField label="Jalan / Dusun" value={data.address} onChange={v => setData('address', v)} />
                                            <div className="flex gap-2">
                                                <div className="flex-1"><EField label="RT" value={data.rt} onChange={v => setData('rt', v)} /></div>
                                                <div className="flex-1"><EField label="RW" value={data.rw} onChange={v => setData('rw', v)} /></div>
                                            </div>
                                            <EField label="Kelurahan / Desa" value={data.village} onChange={v => setData('village', v)} />
                                            <EField label="Kecamatan" value={data.district} onChange={v => setData('district', v)} />
                                            <EField label="Kabupaten / Kota" value={data.regency} onChange={v => setData('regency', v)} />
                                            <EField label="Kode Pos" value={data.postal_code} onChange={v => setData('postal_code', v)} />
                                            <div className="flex flex-col mb-3 gap-1">
                                                <Label className="text-sm text-slate-500">Jarak ke Sekolah</Label>
                                                <Select value={data.distance_to_school} onValueChange={v => setData('distance_to_school', v)}>
                                                    <SelectTrigger className="h-8 text-sm"><SelectValue placeholder="Pilih jarak" /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="< 1 km">{"< 1 km"}</SelectItem>
                                                        <SelectItem value="1-3 km">1-3 km</SelectItem>
                                                        <SelectItem value="> 3 km">{"> 3 km"}</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <EField label="Transportasi" value={data.transportation} onChange={v => setData('transportation', v)} />
                                        </>
                                    ) : (
                                        <>
                                            <ViewField label="Jalan / Dusun" value={student.address} />
                                            <ViewField label="RT / RW" value={`${student.rt || '-'} / ${student.rw || '-'}`} />
                                            <ViewField label="Kelurahan / Desa" value={student.village} />
                                            <ViewField label="Kecamatan" value={student.district} />
                                            <ViewField label="Kabupaten / Kota" value={student.regency} />
                                            <ViewField label="Kode Pos" value={student.postal_code} />
                                            <ViewField label="Jarak ke Sekolah" value={student.distance_to_school} />
                                            <ViewField label="Transportasi" value={student.transportation} />
                                        </>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Dokumen — tidak berubah di mode edit */}
                        <div className="flex flex-col gap-6 h-full">
                            <Card className="h-full flex flex-col">
                                <CardHeader className="pb-3 flex flex-row items-center justify-between space-y-0">
                                    <div>
                                        <CardTitle>Arsip & Dokumen</CardTitle>
                                        <CardDescription>Upload dokumen kelengkapan siswa</CardDescription>
                                    </div>
                                    <Button type="button" variant="ghost" size="icon" onClick={handleRefresh}>
                                        <RefreshCw className="h-4 w-4 text-slate-500" />
                                    </Button>
                                </CardHeader>
                                <CardContent className="flex-grow">
                                    <div className="space-y-3">
                                        {docTypes.map((typeObj) => {
                                            const doc = student.documents?.find((d: any) => d.document_type === typeObj.id);
                                            if (doc) {
                                                return (
                                                    <div key={doc.id} className="bg-slate-50 p-3 rounded-md border flex flex-col gap-2">
                                                        <div className="flex items-center justify-between">
                                                            <div className="flex items-center gap-2 text-sm font-medium">
                                                                {doc.document_type === 'kartu_keluarga' || doc.document_type === 'akta_kelahiran'
                                                                    ? <FileText className="h-4 w-4 text-blue-500" />
                                                                    : <FileImage className="h-4 w-4 text-purple-500" />}
                                                                <span className="uppercase text-xs">{typeObj.label}</span>
                                                            </div>
                                                            {doc.gdrive_view_url && (
                                                                <a href={doc.gdrive_view_url} target="_blank" rel="noreferrer">
                                                                    <ExternalLink className="h-4 w-4 text-slate-400 hover:text-slate-700" />
                                                                </a>
                                                            )}
                                                        </div>
                                                        {(doc.status === 'uploaded' || doc.status === 'pending' || doc.status === 'verified') && doc.ai_verification_result ? (
                                                            <div className="mt-1 flex items-center justify-between text-xs bg-white p-2 rounded border shadow-sm">
                                                                <div className="flex items-center gap-2">
                                                                    <HoverCard>
                                                                        <HoverCardTrigger>
                                                                            <Badge variant={doc.is_verified_ai ? "default" : "secondary"} className={`cursor-help ${doc.is_verified_ai ? "bg-lime-500 hover:bg-lime-600 text-white" : ""}`}>
                                                                                Skor AI: {doc.ai_verification_result.match_score || 0}%
                                                                            </Badge>
                                                                        </HoverCardTrigger>
                                                                        <HoverCardContent align="start" className="w-80 p-3 shadow-lg border-slate-200">
                                                                            <div className="space-y-2">
                                                                                <h4 className="text-sm font-semibold flex items-center gap-2 text-slate-800">
                                                                                    <ShieldCheck className="h-4 w-4 text-blue-500" />
                                                                                    Catatan Analisis AI
                                                                                </h4>
                                                                                <p className="text-xs text-slate-600 leading-relaxed">
                                                                                    {doc.ai_verification_result.notes}
                                                                                </p>
                                                                                <Separator className="my-2" />
                                                                                <div className="space-y-1 text-xs">
                                                                                    {doc.document_type === 'ijazah_sd' ? (
                                                                                        <>
                                                                                            <p><span className="text-slate-400">NISN:</span> <span className="font-medium">{doc.ai_verification_result.nisn}</span></p>
                                                                                            <p><span className="text-slate-400">Nama:</span> <span className="font-medium">{doc.ai_verification_result.name}</span></p>
                                                                                            <p><span className="text-slate-400">Sekolah:</span> <span className="font-medium">{doc.ai_verification_result.prev_school_name}</span></p>
                                                                                        </>
                                                                                    ) : (
                                                                                        <>
                                                                                            <p><span className="text-slate-400">NIK:</span> <span className="font-medium">{doc.ai_verification_result.nik}</span></p>
                                                                                            <p><span className="text-slate-400">Nama:</span> <span className="font-medium">{doc.ai_verification_result.name}</span></p>
                                                                                        </>
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                        </HoverCardContent>
                                                                    </HoverCard>
                                                                    <span className="text-slate-400 italic">
                                                                        {doc.is_verified_ai ? 'Data Valid' : 'Cek Manual'}
                                                                    </span>
                                                                </div>
                                                                <div className="text-right flex flex-col">
                                                                    {doc.document_type === 'ijazah_sd' ? (
                                                                        <span className="font-semibold text-slate-700">{doc.ai_verification_result.nisn}</span>
                                                                    ) : (
                                                                        <span className="font-semibold text-slate-700">{doc.ai_verification_result.nik}</span>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ) : doc.status === 'failed' ? (
                                                            <div className="mt-1 flex items-center justify-between text-xs bg-red-50 text-red-600 p-2 rounded border border-red-100">
                                                                <div className="flex items-center gap-1 font-medium">
                                                                    <ShieldCheck className="h-3 w-3" />
                                                                    Gagal memproses dokumen
                                                                </div>
                                                                <a 
                                                                    href={`/archives/retry/student_document/${doc.id}`}
                                                                    className="px-2 py-1 bg-red-100 hover:bg-red-200 rounded transition-colors text-red-700 font-medium"
                                                                >
                                                                    Coba Lagi
                                                                </a>
                                                            </div>
                                                        ) : (
                                                            <div className="text-xs text-slate-500 italic mt-1 flex items-center gap-1">
                                                                <Loader2 className="h-3 w-3 animate-spin" />
                                                                {doc.status === 'processing' ? 'Sedang diproses oleh AI...' : 'Sedang diupload ke Google Drive...'}
                                                            </div>
                                                        )}
                                                        {/* Tombol Update untuk dokumen yang sudah terverifikasi / uploaded */}
                                                        {(doc.status === 'uploaded' || doc.status === 'verified') && (
                                                            <>
                                                                {updateDocType === typeObj.id ? (
                                                                    <div className="flex items-center gap-2 mt-2 pt-2 border-t">
                                                                        <Input
                                                                            type="file"
                                                                            onChange={e => {
                                                                                setDocData(prev => ({
                                                                                    ...prev,
                                                                                    document_type: typeObj.id,
                                                                                    file: e.target.files ? e.target.files[0] : null,
                                                                                }));
                                                                            }}
                                                                            accept=".pdf,.jpg,.jpeg,.png"
                                                                            className="text-xs h-8 flex-1"
                                                                        />
                                                                        <Button
                                                                            type="button"
                                                                            onClick={(e) => {
                                                                                submitDocument(e, typeObj.id);
                                                                                setUpdateDocType(null);
                                                                            }}
                                                                            size="sm"
                                                                            className="h-8 min-w-[80px] bg-amber-500 hover:bg-amber-600 text-white"
                                                                            disabled={processingDoc || docData.document_type !== typeObj.id || !docData.file}
                                                                        >
                                                                            {processingDoc && docData.document_type === typeObj.id ? (
                                                                                <><Loader2 className="h-4 w-4 animate-spin mr-1" />Proses</>
                                                                            ) : (
                                                                                <><Upload className="h-4 w-4 mr-1" />Simpan</>
                                                                            )}
                                                                        </Button>
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="h-8"
                                                                            onClick={() => {
                                                                                setUpdateDocType(null);
                                                                                setDocData({ document_type: '', file: null });
                                                                            }}
                                                                        >
                                                                            Batal
                                                                        </Button>
                                                                    </div>
                                                                ) : (
                                                                    <div className="flex justify-end mt-1 pt-1 border-t">
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="h-7 text-xs gap-1 text-amber-600 border-amber-200 hover:bg-amber-50"
                                                                            onClick={() => setUpdateDocType(typeObj.id)}
                                                                        >
                                                                            <Upload className="h-3 w-3" />
                                                                            Perbarui Dokumen
                                                                        </Button>
                                                                    </div>
                                                                )}
                                                            </>
                                                        )}
                                                    </div>
                                                );
                                            } else {
                                                return (
                                                    <div key={typeObj.id} className="border border-dashed p-3 rounded-md flex flex-col gap-2">
                                                        <div className="flex items-center gap-2">
                                                            <FileText className="h-4 w-4 text-slate-400" />
                                                            <h4 className="text-sm font-semibold text-slate-700">{typeObj.label}</h4>
                                                        </div>
                                                        {doc?.status === 'failed' ? (
                                                            <p className="text-xs text-red-500 font-medium">Gagal memproses. Silakan unggah ulang.</p>
                                                        ) : (
                                                            <p className="text-xs text-slate-500">Belum diunggah</p>
                                                        )}
                                                        <div className="flex items-center gap-2 mt-1">
                                                            <Input
                                                                type="file"
                                                                onChange={e => {
                                                                    setDocData(prev => ({
                                                                        ...prev,
                                                                        document_type: typeObj.id,
                                                                        file: e.target.files ? e.target.files[0] : null,
                                                                    }));
                                                                }}
                                                                accept=".pdf,.jpg,.jpeg,.png"
                                                                className="text-xs h-8 flex-1"
                                                            />
                                                            <Button
                                                                type="button"
                                                                onClick={(e) => submitDocument(e, typeObj.id)}
                                                                size="sm"
                                                                className="h-8 min-w-[90px]"
                                                                disabled={processingDoc || docData.document_type !== typeObj.id || !docData.file}
                                                                title={!docData.file ? "Pilih file terlebih dahulu" : "Mulai unggah dokumen"}
                                                            >
                                                                {processingDoc && docData.document_type === typeObj.id ? (
                                                                    <><Loader2 className="h-4 w-4 animate-spin mr-1" /> Proses</>
                                                                ) : (
                                                                    <><Upload className="h-4 w-4 mr-1" /> Unggah</>
                                                                )}
                                                            </Button>
                                                        </div>
                                                        {processingDoc && docData.document_type === typeObj.id && docProgress > 0 && (
                                                            <Progress value={docProgress} className="h-1.5 w-full mt-1" />
                                                        )}
                                                        {docErrors.file && docData.document_type === typeObj.id && <p className="text-red-500 text-xs">{docErrors.file}</p>}
                                                    </div>
                                                );
                                            }
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>

                    {/* Data Orang Tua */}
                    <Card className="w-full">
                        <CardHeader className="pb-2">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                                <div className="md:col-span-1">
                                    <CardTitle>Data Orang Tua & Wali</CardTitle>
                                    <CardDescription>Informasi lengkap terkait orang tua dan wali murid</CardDescription>
                                </div>
                                <div className="md:col-span-1 flex items-center gap-2 bg-transparent pb-1">
                                    <span className="text-sm font-semibold text-slate-700">No. KK:</span>
                                    {isEditing ? (
                                        <Input
                                            value={data.kk_number}
                                            onChange={e => setData('kk_number', e.target.value)}
                                            className="h-7 text-xs w-40 bg-white"
                                            placeholder="Masukkan No. KK"
                                        />
                                    ) : (
                                        <Badge variant="outline" className="text-sm font-bold bg-white">{student.kk_number || '-'}</Badge>
                                    )}
                                </div>
                                <div className="md:col-span-1"></div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                {/* Ibu */}
                                <div className="border rounded-lg p-4 bg-slate-50">
                                    <h4 className="font-semibold text-md text-slate-800 border-b pb-2 mb-3 flex items-center justify-between">
                                        Ibu Kandung
                                        <Badge variant="outline" className="text-xs font-normal bg-white">Kunci Verval PD</Badge>
                                    </h4>
                                    {isEditing ? (
                                        <>
                                            <EField label="Nama Ibu" value={data.mother_name} onChange={v => setData('mother_name', v)} />
                                            <EField label="NIK Ibu" value={data.mother_nik} onChange={v => setData('mother_nik', v)} />
                                            <EField label="Pekerjaan" value={data.mother_job} onChange={v => setData('mother_job', v)} />
                                            <EField label="Penghasilan / Bulan (Rp)" value={data.mother_income.toString()} onChange={v => setData('mother_income', v)} type="number" />
                                            <EField label="No. Telepon / HP" value={data.mother_phone} onChange={v => setData('mother_phone', v)} />
                                        </>
                                    ) : (
                                        <div className="space-y-3">
                                            <ViewField label="Nama Ibu" value={student.mother_name} />
                                            <ViewField label="NIK Ibu" value={student.mother_nik} />
                                            <ViewField label="Pekerjaan" value={student.mother_job} />
                                            <ViewField label="Penghasilan / Bulan" value={student.mother_income ? `Rp ${Number(student.mother_income).toLocaleString('id-ID')}` : undefined} />
                                            <ViewField label="No. Telepon / HP" value={student.mother_phone} />
                                        </div>
                                    )}
                                </div>

                                {/* Ayah */}
                                <div className="border rounded-lg p-4 bg-slate-50">
                                    <h4 className="font-semibold text-md text-slate-800 border-b pb-2 mb-3">Ayah Kandung</h4>
                                    {isEditing ? (
                                        <>
                                            <EField label="Nama Ayah" value={data.father_name} onChange={v => setData('father_name', v)} />
                                            <EField label="NIK Ayah" value={data.father_nik} onChange={v => setData('father_nik', v)} />
                                            <EField label="Pekerjaan" value={data.father_job} onChange={v => setData('father_job', v)} />
                                            <EField label="Penghasilan / Bulan (Rp)" value={data.father_income.toString()} onChange={v => setData('father_income', v)} type="number" />
                                            <EField label="No. Telepon / HP" value={data.father_phone} onChange={v => setData('father_phone', v)} />
                                        </>
                                    ) : (
                                        <div className="space-y-3">
                                            <ViewField label="Nama Ayah" value={student.father_name} />
                                            <ViewField label="NIK Ayah" value={student.father_nik} />
                                            <ViewField label="Pekerjaan" value={student.father_job} />
                                            <ViewField label="Penghasilan / Bulan" value={student.father_income ? `Rp ${Number(student.father_income).toLocaleString('id-ID')}` : undefined} />
                                            <ViewField label="No. Telepon / HP" value={student.father_phone} />
                                        </div>
                                    )}
                                </div>

                                {/* Bantuan Sosial */}
                                <div className="border rounded-lg p-4 bg-slate-50">
                                    <h4 className="font-semibold text-md text-slate-800 border-b pb-2 mb-3">Bantuan Sosial</h4>
                                    {isEditing ? (
                                        <>
                                            <EField label="Nomor KIP" value={data.kip_number} onChange={v => setData('kip_number', v)} />
                                            <div className="flex flex-col mb-3 gap-1">
                                                <Label className="text-sm text-slate-500">Status PIP</Label>
                                                <Select value={data.pip_eligible} onValueChange={v => setData('pip_eligible', v)}>
                                                    <SelectTrigger className="h-8 text-sm"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="1">Menerima PIP</SelectItem>
                                                        <SelectItem value="0">Tidak Menerima</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="flex flex-col mb-3 gap-1">
                                                <Label className="text-sm text-slate-500">Status PKH</Label>
                                                <Select value={data.pkh_eligible} onValueChange={v => setData('pkh_eligible', v)}>
                                                    <SelectTrigger className="h-8 text-sm"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="1">Menerima PKH</SelectItem>
                                                        <SelectItem value="0">Tidak Menerima</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="flex flex-col mb-3 gap-1">
                                                <Label className="text-sm text-slate-500">Status KKS</Label>
                                                <Select value={data.kks_eligible} onValueChange={v => setData('kks_eligible', v)}>
                                                    <SelectTrigger className="h-8 text-sm"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="1">Menerima KKS</SelectItem>
                                                        <SelectItem value="0">Tidak Menerima</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </>
                                    ) : (
                                        <div className="space-y-3">
                                            <ViewField label="Nomor KIP" value={student.kip_number} />
                                            <div className="flex flex-col mb-1 gap-1">
                                                <span className="text-xs text-slate-500">Status PIP</span>
                                                <div>
                                                    {student.pip_eligible ? (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 border border-blue-200">
                                                            <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                            Penerima PIP
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                                            Tidak Menerima
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex flex-col mb-1 gap-1">
                                                <span className="text-xs text-slate-500">Status PKH</span>
                                                <div>
                                                    {student.pkh_eligible ? (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                            <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                            Penerima PKH
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                                            Tidak Menerima
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex flex-col mb-1 gap-1">
                                                <span className="text-xs text-slate-500">Status KKS</span>
                                                <div>
                                                    {student.kks_eligible ? (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-violet-100 text-violet-800 border border-violet-200">
                                                            <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                                            Penerima KKS
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                                            Tidak Menerima
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Riwayat Kelas & Riwayat Perubahan — berdampingan */}
                    {(student.cohorts?.length > 0 || (activityLogs && activityLogs.length > 0)) && (
                        <div className="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">

                            {/* Riwayat Kelas */}
                            {student.cohorts && student.cohorts.length > 0 && (
                                <Card className="h-full">
                                    <CardHeader className="pb-3">
                                        <CardTitle className="flex items-center gap-2">
                                            <History className="h-5 w-5 text-slate-500" />
                                            Riwayat Kelas
                                        </CardTitle>
                                        <CardDescription>Rekaman perjalanan akademik dari tahun ke tahun</CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-sm text-left">
                                                <thead className="bg-slate-50 text-slate-600 text-xs uppercase">
                                                    <tr>
                                                        <th className="px-3 py-2 border-b">Tahun Ajaran</th>
                                                        <th className="px-3 py-2 border-b">Semester</th>
                                                        <th className="px-3 py-2 border-b">Kelas / Rombel</th>
                                                        <th className="px-3 py-2 border-b text-center">Absen</th>
                                                        <th className="px-3 py-2 border-b text-center">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y">
                                                    {[...student.cohorts].reverse().map((cohort: any) => (
                                                        <tr key={cohort.id} className="hover:bg-slate-50">
                                                            <td className="px-3 py-2 font-medium">{cohort.academic_year?.name || '-'}</td>
                                                            <td className="px-3 py-2">{cohort.semester === '1' ? 'Ganjil' : cohort.semester === '2' ? 'Genap' : cohort.semester || '-'}</td>
                                                            <td className="px-3 py-2">{cohort.rombel?.name || '-'}</td>
                                                            <td className="px-3 py-2 text-center">{cohort.attendance_number || '-'}</td>
                                                            <td className="px-3 py-2 text-center">
                                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                                                                    cohort.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'
                                                                }`}>
                                                                    {cohort.status === 'active' ? 'Aktif' : 'Selesai'}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Riwayat Perubahan Data */}
                            {activityLogs && activityLogs.length > 0 && (
                                <Card className="h-full">
                                    <CardHeader className="pb-3">
                                        <CardTitle className="flex items-center gap-2">
                                            <History className="h-5 w-5 text-slate-500" />
                                            Riwayat Perubahan Data
                                        </CardTitle>
                                        <CardDescription>5 perubahan data terakhir yang tercatat sistem</CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            {activityLogs.slice(0, 5).map((log: any) => {
                                                const changedKeys = log.after_payload ? Object.keys(log.after_payload).filter((k: string) => k !== 'updated_at') : [];
                                                const actionLabel = log.action === 'created' ? 'Dibuat' : log.action === 'updated' ? 'Diperbarui' : 'Dihapus';
                                                const actionColor = log.action === 'created' ? 'bg-green-100 text-green-700' : log.action === 'updated' ? 'bg-blue-100 text-blue-700' : 'bg-red-100 text-red-700';
                                                return (
                                                    <div key={log.id} className="flex gap-3 items-start text-sm border-b pb-3 last:border-0 last:pb-0">
                                                        <div className="shrink-0 w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center">
                                                            <span className="text-sm font-medium">{log.user?.name?.charAt(0)?.toUpperCase() || '?'}</span>
                                                        </div>
                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex items-center justify-between gap-2">
                                                                <div className="flex items-center gap-2 overflow-hidden">
                                                                    <span className="font-medium text-slate-900 truncate" title={log.user?.name || 'Sistem'}>{log.user?.name || 'Sistem'}</span>
                                                                    <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-medium shrink-0 ${actionColor}`}>{actionLabel}</span>
                                                                </div>
                                                                <span className="text-[10px] text-slate-400 shrink-0">
                                                                    {log.created_at ? new Date(log.created_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-'}
                                                                </span>
                                                            </div>
                                                            {changedKeys.length > 0 && (
                                                                <div className="mt-1 flex flex-nowrap items-center gap-1 overflow-hidden mask-image-right">
                                                                    <span className="text-[10px] text-slate-500 shrink-0">{changedKeys.length} kolom:</span>
                                                                    {changedKeys.slice(0, 5).map((k: string) => (
                                                                        <span key={k} className="text-[10px] bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded border shrink-0">{k}</span>
                                                                    ))}
                                                                    {changedKeys.length > 5 && <span className="text-[10px] text-slate-400 shrink-0">+{changedKeys.length - 5} lagi</span>}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                        </div>
                    )}

                    {/* Tombol simpan bawah */}
                    {isEditing && (
                        <div className="flex justify-end gap-2 pb-4">
                            <Button type="button" variant="outline" onClick={cancelEdit}>
                                <X className="h-4 w-4 mr-2" /> Batal
                            </Button>
                            <Button type="submit" disabled={processing} className="gap-2 bg-slate-900 hover:bg-slate-800 text-white">
                                {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                                Simpan Perubahan
                            </Button>
                        </div>
                    )}
                </div>
            </form>

            {/* Modal Preview EMIS */}
            <Dialog open={showPreviewModal} onOpenChange={setShowPreviewModal}>
                <DialogContent className="sm:max-w-3xl md:max-w-4xl max-w-[95vw]">
                    <DialogHeader>
                        <DialogTitle>Konfirmasi Perubahan Data EMIS</DialogTitle>
                        <DialogDescription>
                            Terdapat perbedaan antara data lokal di aplikasi ini dengan data dari server EMIS. Silakan periksa perubahan di bawah ini sebelum menyimpan.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="max-h-[60vh] overflow-y-auto border rounded-md">
                        <table className="w-full text-sm text-left">
                            <thead className="bg-slate-50 text-slate-700 sticky top-0 shadow-sm">
                                <tr>
                                    <th className="px-4 py-2 border-b w-12 text-center">
                                        <Checkbox
                                            checked={selectedChanges.length === previewChanges.length}
                                            onCheckedChange={(checked) => {
                                                if (checked) {
                                                    setSelectedChanges(previewChanges.map(c => c.field));
                                                } else {
                                                    setSelectedChanges([]);
                                                }
                                            }}
                                        />
                                    </th>
                                    <th className="px-4 py-2 border-b w-1/3">Kolom / Data</th>
                                    <th className="px-4 py-2 border-b w-1/3 text-red-600 bg-red-50/50">Lokal Saat Ini</th>
                                    <th className="px-4 py-2 border-b w-1/3 text-green-700 bg-green-50/50">EMIS (Baru)</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {previewChanges.map((change, idx) => {
                                    const isSelected = selectedChanges.includes(change.field);
                                    return (
                                        <tr key={idx} className={`hover:bg-slate-50 ${isSelected ? '' : 'opacity-60 grayscale'}`}>
                                            <td className="px-4 py-2 text-center">
                                                <Checkbox
                                                    checked={isSelected}
                                                    onCheckedChange={(checked) => {
                                                        if (checked) {
                                                            setSelectedChanges([...selectedChanges, change.field]);
                                                        } else {
                                                            setSelectedChanges(selectedChanges.filter(f => f !== change.field));
                                                        }
                                                    }}
                                                />
                                            </td>
                                            <td className="px-4 py-2 font-medium text-slate-900">{change.label}</td>
                                            <td className="px-4 py-2 text-red-600 line-through decoration-red-300">{change.old}</td>
                                            <td className="px-4 py-2 text-green-700 font-medium">{change.new}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <DialogFooter className="mt-4">
                        <Button variant="outline" onClick={() => setShowPreviewModal(false)} disabled={isCommitting}>
                            Batalkan
                        </Button>
                        <Button onClick={handleCommitEmisDetail} disabled={isCommitting} className="bg-blue-600 hover:bg-blue-700 text-white gap-2">
                            {isCommitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                            Konfirmasi & Simpan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

        </AppLayout>

        {/* Modal Upload Foto Profil */}
        <Dialog open={showPhotoModal} onOpenChange={setShowPhotoModal}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Foto Profil Siswa</DialogTitle>
                    <DialogDescription>Upload foto siswa. Format: JPG/PNG, maksimal 1MB.</DialogDescription>
                </DialogHeader>
                <div className="flex flex-col items-center gap-4 py-2">
                    {/* Preview */}
                    <div className="w-28 h-28 rounded-full overflow-hidden border-2 border-slate-200">
                        {photoFile ? (
                            <img src={URL.createObjectURL(photoFile)} alt="preview" className="w-full h-full object-cover" />
                        ) : student.profile_photo ? (
                            <img src={student.profile_photo.startsWith("archives/") ? `/${student.profile_photo}` : `/storage/${student.profile_photo}`} alt={student.name} className="w-full h-full object-cover" />
                        ) : (
                            <div className={`w-full h-full flex items-center justify-center text-3xl font-bold text-white ${student.gender === 'P' ? 'bg-pink-400' : 'bg-blue-400'}`}>
                                {student.name?.charAt(0)?.toUpperCase() || '?'}
                            </div>
                        )}
                    </div>
                    <Input
                        type="file"
                        accept="image/jpeg,image/png"
                        onChange={e => setPhotoFile(e.target.files ? e.target.files[0] : null)}
                        className="text-sm"
                    />
                </div>
                <div className="flex justify-between items-center">
                    {student.profile_photo && (
                        <Button type="button" variant="ghost" size="sm" className="text-red-500 hover:text-red-700 gap-1" onClick={handleDeletePhoto}>
                            <Trash2 className="h-4 w-4" /> Hapus Foto
                        </Button>
                    )}
                    <div className="flex gap-2 ml-auto">
                        <Button type="button" variant="outline" onClick={() => { setShowPhotoModal(false); setPhotoFile(null); }}>Batal</Button>
                        <Button type="button" onClick={handleUploadPhoto} disabled={!photoFile || processingPhoto} className="gap-2">
                            {processingPhoto ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
                            Simpan Foto
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
        </>
    );
}
