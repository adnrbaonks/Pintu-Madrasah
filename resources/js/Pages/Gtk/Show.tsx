import React from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    ArrowLeft, Edit, UserCheck, UserX, Shield, Award, Phone,
    Building, GraduationCap, Lock, FileText, Upload, Trash2, ExternalLink
} from 'lucide-react';

interface StaffDocumentItem {
    id: string;
    document_type: string;
    title: string;
    file_path: string | null;
    mime_type: string | null;
    file_size: number;
    gdrive_file_id: string | null;
    gdrive_view_url: string | null;
    status: string;
    created_at: string;
}

interface StaffDetail {
    id: string;
    nip: string | null;
    nik: string;
    npk: string | null;
    nuptk: string | null;
    full_name_with_titles: string;
    first_title: string | null;
    last_title: string | null;
    gender: string;
    birth_place: string;
    birth_date: string;
    phone: string | null;
    email: string | null;
    employment_status: string;
    civil_rank: string | null;
    civil_rank_tmt: string | null;
    tpg_status: boolean;
    functional_position: string | null;
    highest_education: string | null;
    university_name: string | null;
    study_program: string | null;
    graduation_year: number | null;
    is_active: boolean;
    user?: {
        id: string;
        username: string;
        role: string;
        is_active: boolean;
    } | null;
    homeroom_rombels?: {
        id: string;
        name: string;
        academic_year?: { year: string; semester: string };
    }[];
    documents?: StaffDocumentItem[];
}

interface Props {
    staff: StaffDetail;
    ownership: Record<string, { owner_system: string; change_policy: string; label: string }>;
}

const DOCUMENT_TYPE_LABELS: Record<string, string> = {
    sk_pengangkatan: 'SK Pengangkatan',
    ijazah: 'Ijazah Pendidikan',
    sertifikat_pendidik: 'Sertifikat Pendidik',
    ktp: 'KTP',
    kk: 'Kartu Keluarga',
    lainnya: 'Dokumen Lainnya',
};

export default function Show({ staff }: Props) {
    const { auth } = usePage<{ auth: { user?: { role: string } } }>().props;
    const canEdit = ['admin', 'kaur_tu', 'staf', 'operator'].includes(auth?.user?.role || '');

    const { data, setData, post, processing, errors, reset } = useForm<{
        document_type: string;
        title: string;
        file: File | null;
    }>({
        document_type: 'sk_pengangkatan',
        title: '',
        file: null,
    });

    const handleUpload = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/gtk/${staff.id}/documents`, {
            forceFormData: true,
            onSuccess: () => reset('title', 'file'),
        });
    };

    const handleDeleteDocument = (documentId: string, title: string) => {
        if (!window.confirm(`Hapus dokumen "${title}"?`)) {
            return;
        }
        router.delete(`/gtk/${staff.id}/documents/${documentId}`);
    };

    const getArchiveFilename = (filePath: string | null) => {
        if (!filePath) return null;
        const parts = filePath.split('/');
        return parts[parts.length - 1];
    };

    return (
        <AppLayout title={`Profil GTK - ${staff.full_name_with_titles}`}>
            <Head title={`Profil GTK - ${staff.full_name_with_titles}`} />

            {/* Back button & Action */}
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                <div className="flex items-center gap-3">
                    <Link href="/gtk">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <ArrowLeft className="w-4 h-4" />
                            Kembali
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold text-slate-800">{staff.full_name_with_titles}</h1>
                        <p className="text-sm text-slate-500">
                            {staff.functional_position || 'Pegawai'} • {staff.employment_status?.toUpperCase()}
                        </p>
                    </div>
                </div>
                {canEdit && (
                    <Link href={`/gtk/${staff.id}/edit`}>
                        <Button className="gap-2 bg-teal-700 hover:bg-teal-800">
                            <Edit className="w-4 h-4" />
                            Edit Data GTK
                        </Button>
                    </Link>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Kolom Kiri: Ringkasan Status & Akun */}
                <div className="space-y-6">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Status Kepegawaian</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex justify-between items-center text-sm py-1 border-b">
                                <span className="text-slate-500">Status Aktif</span>
                                {staff.is_active ? (
                                    <Badge className="bg-emerald-100 text-emerald-800 border-emerald-200">
                                        <UserCheck className="w-3 h-3 mr-1" /> Aktif
                                    </Badge>
                                ) : (
                                    <Badge variant="secondary">
                                        <UserX className="w-3 h-3 mr-1" /> Nonaktif
                                    </Badge>
                                )}
                            </div>
                            <div className="flex justify-between items-center text-sm py-1 border-b">
                                <span className="text-slate-500">Status Pegawai</span>
                                <Badge variant="outline" className="uppercase font-semibold">
                                    {staff.employment_status || '-'}
                                </Badge>
                            </div>
                            <div className="flex justify-between items-center text-sm py-1 border-b">
                                <span className="text-slate-500">TPG / Sertifikasi</span>
                                {staff.tpg_status ? (
                                    <Badge className="bg-amber-100 text-amber-800 border-amber-300">
                                        <Award className="w-3 h-3 mr-1" /> Bersertifikasi
                                    </Badge>
                                ) : (
                                    <span className="text-xs text-slate-400">Belum Sertifikasi</span>
                                )}
                            </div>
                            <div className="flex justify-between items-center text-sm py-1 border-b">
                                <span className="text-slate-500">Golongan / Pangkat</span>
                                <span className="font-medium text-slate-700">{staff.civil_rank || '—'}</span>
                            </div>
                            <div className="flex justify-between items-center text-sm py-1">
                                <span className="text-slate-500">TMT Golongan</span>
                                <span className="text-slate-700">{staff.civil_rank_tmt || '—'}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Akun Pengguna Terkait */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base flex items-center gap-2">
                                <Shield className="w-4 h-4 text-teal-600" />
                                Akun Login Madrasah
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {staff.user ? (
                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between py-1 border-b">
                                        <span className="text-slate-500">Username</span>
                                        <span className="font-semibold text-slate-800">{staff.user.username}</span>
                                    </div>
                                    <div className="flex justify-between py-1 border-b">
                                        <span className="text-slate-500">Role Sistem</span>
                                        <Badge variant="outline" className="capitalize">{staff.user.role}</Badge>
                                    </div>
                                    <div className="flex justify-between py-1">
                                        <span className="text-slate-500">Status Akun</span>
                                        <span className={staff.user.is_active ? 'text-emerald-600 font-medium' : 'text-rose-500'}>
                                            {staff.user.is_active ? 'Aktif' : 'Dinonaktifkan'}
                                        </span>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-xs text-slate-400 py-2">
                                    Pegawai ini belum memiliki akun login di sistem. Anda dapat membuatnya di menu Pengguna & Akses.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Riwayat Wali Kelas */}
                    {staff.homeroom_rombels && staff.homeroom_rombels.length > 0 && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Building className="w-4 h-4 text-teal-600" />
                                    Penugasan Wali Kelas
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="space-y-2 text-sm">
                                    {staff.homeroom_rombels.map(r => (
                                        <li key={r.id} className="p-2.5 bg-slate-50 rounded border flex justify-between">
                                            <span className="font-medium text-slate-700">{r.name}</span>
                                            <span className="text-xs text-slate-500">
                                                {r.academic_year?.year} ({r.academic_year?.semester})
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Kolom Kanan: Rincian Data Lengkap & Arsip Dokumen */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Identitas Resmi (Otoritas EMIS) */}
                    <Card>
                        <CardHeader className="pb-3 border-b">
                            <div className="flex justify-between items-center">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Lock className="w-4 h-4 text-amber-600" />
                                    Nomor Identitas Resmi
                                </CardTitle>
                                <Badge variant="outline" className="text-xs bg-amber-50 text-amber-700 border-amber-200">
                                    Otoritas EMIS Kemenag (Read-Only)
                                </Badge>
                            </div>
                            <CardDescription className="text-xs">
                                Data ini diselaraskan otomatis dengan database EMIS Pusat.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div className="p-3 bg-slate-50 rounded border">
                                <p className="text-xs text-slate-500 mb-0.5">Nomor Induk Pegawai (NIP)</p>
                                <p className="font-semibold text-slate-800">{staff.nip || '—'}</p>
                            </div>
                            <div className="p-3 bg-slate-50 rounded border">
                                <p className="text-xs text-slate-500 mb-0.5">Nomor Induk Kependudukan (NIK)</p>
                                <p className="font-semibold text-slate-800">{staff.nik || '—'}</p>
                            </div>
                            <div className="p-3 bg-slate-50 rounded border">
                                <p className="text-xs text-slate-500 mb-0.5">NUPTK</p>
                                <p className="font-semibold text-slate-800">{staff.nuptk || '—'}</p>
                            </div>
                            <div className="p-3 bg-slate-50 rounded border">
                                <p className="text-xs text-slate-500 mb-0.5">NPK (Nomor Pendidik Kemenag)</p>
                                <p className="font-semibold text-slate-800">{staff.npk || '—'}</p>
                            </div>
                            <div className="p-3 bg-slate-50 rounded border">
                                <p className="text-xs text-slate-500 mb-0.5">Tempat & Tanggal Lahir</p>
                                <p className="font-medium text-slate-800">
                                    {staff.birth_place || '—'}, {staff.birth_date || '—'}
                                </p>
                            </div>
                            <div className="p-3 bg-slate-50 rounded border">
                                <p className="text-xs text-slate-500 mb-0.5">Jenis Kelamin</p>
                                <p className="font-medium text-slate-800">
                                    {staff.gender === 'L' ? 'Laki-laki' : staff.gender === 'P' ? 'Perempuan' : '—'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Kontak & Informasi Lokal Madrasah */}
                    <Card>
                        <CardHeader className="pb-3 border-b">
                            <div className="flex justify-between items-center">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Phone className="w-4 h-4 text-teal-600" />
                                    Kontak & Administrasi Lokal
                                </CardTitle>
                                <Badge variant="outline" className="text-xs bg-teal-50 text-teal-700 border-teal-200">
                                    Otoritas Lokal Madrasah
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p className="text-xs text-slate-500">Nomor Telepon / WhatsApp</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.phone || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Alamat Email</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.email || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Gelar Depan</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.first_title || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Gelar Belakang</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.last_title || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Jabatan Fungsional</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.functional_position || '—'}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Riwayat Pendidikan */}
                    <Card>
                        <CardHeader className="pb-3 border-b">
                            <CardTitle className="text-base flex items-center gap-2">
                                <GraduationCap className="w-4 h-4 text-teal-600" />
                                Pendidikan Terakhir
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p className="text-xs text-slate-500">Jenjang Pendidikan</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.highest_education || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Tahun Lulus</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.graduation_year || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Perguruan Tinggi</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.university_name || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-500">Program Studi</p>
                                <p className="font-medium text-slate-800 mt-0.5">{staff.study_program || '—'}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Arsip Dokumen Kepegawaian GTK */}
                    <Card>
                        <CardHeader className="pb-3 border-b">
                            <div className="flex justify-between items-center">
                                <div>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <FileText className="w-4 h-4 text-teal-600" />
                                        Arsip Dokumen Kepegawaian
                                    </CardTitle>
                                    <CardDescription className="text-xs mt-1">
                                        SK Pengangkatan, Ijazah, Sertifikat Pendidik, dan dokumen kepegawaian lainnya.
                                    </CardDescription>
                                </div>
                                <Badge variant="secondary" className="text-xs">
                                    {staff.documents?.length || 0} Dokumen
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-4 space-y-5">
                            {canEdit && (
                                <form onSubmit={handleUpload} className="p-4 bg-slate-50 rounded-lg border space-y-3">
                                    <p className="text-xs font-semibold text-slate-700 uppercase tracking-wide">
                                        Unggah Dokumen Baru
                                    </p>
                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div className="space-y-1">
                                            <Label htmlFor="document_type" className="text-xs">Jenis Dokumen</Label>
                                            <select
                                                id="document_type"
                                                value={data.document_type}
                                                onChange={e => setData('document_type', e.target.value)}
                                                className="flex h-9 w-full rounded-md border border-input bg-white px-3 py-1 text-sm shadow-sm"
                                            >
                                                {Object.entries(DOCUMENT_TYPE_LABELS).map(([key, label]) => (
                                                    <option key={key} value={key}>{label}</option>
                                                ))}
                                            </select>
                                            {errors.document_type && (
                                                <p className="text-xs text-rose-500">{errors.document_type}</p>
                                            )}
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="title" className="text-xs">Judul / Keterangan</Label>
                                            <Input
                                                id="title"
                                                placeholder="Contoh: SK PNS 2024"
                                                value={data.title}
                                                onChange={e => setData('title', e.target.value)}
                                                className="h-9 bg-white"
                                                required
                                            />
                                            {errors.title && (
                                                <p className="text-xs text-rose-500">{errors.title}</p>
                                            )}
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="file" className="text-xs">Berkas (PDF/JPG/PNG, maks 5MB)</Label>
                                            <Input
                                                id="file"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                onChange={e => setData('file', e.target.files?.[0] || null)}
                                                className="h-9 bg-white text-xs"
                                                required
                                            />
                                            {errors.file && (
                                                <p className="text-xs text-rose-500">{errors.file}</p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex justify-end">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                            className="gap-1.5 bg-teal-700 hover:bg-teal-800"
                                        >
                                            <Upload className="w-3.5 h-3.5" />
                                            {processing ? 'Mengunggah...' : 'Simpan Dokumen'}
                                        </Button>
                                    </div>
                                </form>
                            )}

                            {staff.documents && staff.documents.length > 0 ? (
                                <div className="divide-y border rounded-lg">
                                    {staff.documents.map(doc => {
                                        const filename = getArchiveFilename(doc.file_path);
                                        const viewUrl = doc.gdrive_view_url || (filename ? `/archives/staff_document/${filename}` : null);

                                        return (
                                            <div key={doc.id} className="p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                                <div className="space-y-0.5">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium text-sm text-slate-800">{doc.title}</span>
                                                        <Badge variant="outline" className="text-xs">
                                                            {DOCUMENT_TYPE_LABELS[doc.document_type] || doc.document_type}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-xs text-slate-500">
                                                        {(doc.file_size / 1024).toFixed(1)} KB • Status: {doc.status}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {viewUrl && (
                                                        <a
                                                            href={viewUrl}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            <Button variant="outline" size="sm" className="h-8 gap-1 text-xs">
                                                                <ExternalLink className="w-3.5 h-3.5" />
                                                                Lihat
                                                            </Button>
                                                        </a>
                                                    )}
                                                    {canEdit && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-8 px-2 text-rose-600 hover:text-rose-700 hover:bg-rose-50"
                                                            onClick={() => handleDeleteDocument(doc.id, doc.title)}
                                                        >
                                                            <Trash2 className="w-3.5 h-3.5" />
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-6">
                                    Belum ada arsip dokumen kepegawaian yang diunggah untuk pegawai ini.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
