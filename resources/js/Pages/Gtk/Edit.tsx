import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Save, Lock, AlertCircle } from 'lucide-react';
import { toast } from 'sonner';

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
}

interface Props {
    staff: StaffDetail;
    ownership: Record<string, { owner_system: string; change_policy: string; label: string }>;
    editableFields: string[];
    readOnlyFields: string[];
}

export default function Edit({ staff, ownership, editableFields, readOnlyFields }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        full_name_with_titles: staff.full_name_with_titles || '',
        first_title: staff.first_title || '',
        last_title: staff.last_title || '',
        phone: staff.phone || '',
        email: staff.email || '',
        employment_status: staff.employment_status || 'GTT',
        functional_position: staff.functional_position || '',
        highest_education: staff.highest_education || '',
        university_name: staff.university_name || '',
        study_program: staff.study_program || '',
        graduation_year: staff.graduation_year || '',
        is_active: staff.is_active,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/gtk/${staff.id}`, {
            onSuccess: () => toast.success('Data pegawai GTK berhasil diperbarui.'),
            onError: () => toast.error('Gagal memperbarui data GTK.'),
        });
    };

    return (
        <AppLayout title={`Edit GTK - ${staff.full_name_with_titles}`}>
            <Head title={`Edit GTK - ${staff.full_name_with_titles}`} />

            <div className="flex items-center gap-3 mb-6">
                <Link href={`/gtk/${staff.id}`}>
                    <Button variant="outline" size="sm" className="gap-1.5">
                        <ArrowLeft className="w-4 h-4" />
                        Kembali
                    </Button>
                </Link>
                <div>
                    <h1 className="text-2xl font-bold text-slate-800">Edit Data Pegawai GTK</h1>
                    <p className="text-sm text-slate-500">{staff.full_name_with_titles}</p>
                </div>
            </div>

            <form onSubmit={handleSubmit} className="space-y-6">
                {/* Informasi Proteksi Data */}
                <div className="p-4 bg-amber-50/70 border border-amber-200 rounded-lg flex items-start gap-3">
                    <AlertCircle className="w-5 h-5 text-amber-600 mt-0.5 flex-shrink-0" />
                    <div className="text-xs text-amber-900 leading-relaxed">
                        <p className="font-semibold text-amber-950 mb-1">Kamus Kepemilikan Data (Data Ownership Policy)</p>
                        Sesuai standar integrasi madrasah, atribut resmi negara (NIK, NIP, NUPTK, Tempat/Tgl Lahir, dan Sertifikasi TPG) dikuasai langsung oleh pusat (EMIS) dan berstatus <strong>Read-Only</strong> di tingkat madrasah lokal. Anda dapat mengelola data kontak, gelar, riwayat pendidikan, dan status kepegawaian internal madrasah.
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Identitas Resmi — Terkunci (Read-Only) */}
                    <Card className="border-slate-200 bg-slate-50/50">
                        <CardHeader className="pb-3 border-b bg-slate-100/60">
                            <CardTitle className="text-sm font-semibold flex items-center gap-2 text-slate-700">
                                <Lock className="w-4 h-4 text-slate-400" />
                                Identitas Resmi (EMIS Read-Only)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-4 space-y-3.5 text-xs">
                            <div className="space-y-1">
                                <Label className="text-slate-500">Nomor Induk Kependudukan (NIK)</Label>
                                <Input value={staff.nik || '—'} disabled className="bg-slate-100 text-slate-500 cursor-not-allowed" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-slate-500">Nomor Induk Pegawai (NIP)</Label>
                                <Input value={staff.nip || '—'} disabled className="bg-slate-100 text-slate-500 cursor-not-allowed" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-slate-500">NUPTK</Label>
                                <Input value={staff.nuptk || '—'} disabled className="bg-slate-100 text-slate-500 cursor-not-allowed" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-slate-500">Tempat & Tanggal Lahir</Label>
                                <Input value={`${staff.birth_place || '—'}, ${staff.birth_date || '—'}`} disabled className="bg-slate-100 text-slate-500 cursor-not-allowed" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-slate-500">Status Sertifikasi TPG</Label>
                                <Input value={staff.tpg_status ? 'Bersertifikasi Pendidik' : 'Belum Bersertifikasi'} disabled className="bg-slate-100 text-slate-500 cursor-not-allowed" />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Data Lokal yang Boleh Diedit */}
                    <Card>
                        <CardHeader className="pb-3 border-b">
                            <CardTitle className="text-sm font-semibold text-teal-800">
                                Data Identitas & Kontak Lokal
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-4 space-y-4 text-xs">
                            <div className="space-y-1.5">
                                <Label>Nama Lengkap Beserta Gelar</Label>
                                <Input
                                    value={data.full_name_with_titles}
                                    onChange={e => setData('full_name_with_titles', e.target.value)}
                                    required
                                />
                                {errors.full_name_with_titles && <p className="text-destructive">{errors.full_name_with_titles}</p>}
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Gelar Depan</Label>
                                    <Input
                                        value={data.first_title}
                                        onChange={e => setData('first_title', e.target.value)}
                                        placeholder="cth: Dr., H."
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Gelar Belakang</Label>
                                    <Input
                                        value={data.last_title}
                                        onChange={e => setData('last_title', e.target.value)}
                                        placeholder="cth: S.Pd., M.Ag."
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>No. Telepon / WhatsApp</Label>
                                    <Input
                                        value={data.phone}
                                        onChange={e => setData('phone', e.target.value)}
                                        placeholder="08123456789"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Email</Label>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        onChange={e => setData('email', e.target.value)}
                                        placeholder="nama@madrasah.sch.id"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Status Kepegawaian</Label>
                                    <Select value={data.employment_status} onValueChange={v => setData('employment_status', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="PNS">PNS</SelectItem>
                                            <SelectItem value="PPPK">PPPK</SelectItem>
                                            <SelectItem value="GTT">GTT (Guru Tidak Tetap)</SelectItem>
                                            <SelectItem value="PTT">PTT (Pegawai Tidak Tetap)</SelectItem>
                                            <SelectItem value="GTY">GTY (Guru Tetap Yayasan)</SelectItem>
                                            <SelectItem value="Honorer">Honorer</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Jabatan Fungsional</Label>
                                    <Input
                                        value={data.functional_position}
                                        onChange={e => setData('functional_position', e.target.value)}
                                        placeholder="Guru Mata Pelajaran / Staf TU"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Riwayat Pendidikan */}
                <Card>
                    <CardHeader className="pb-3 border-b">
                        <CardTitle className="text-sm font-semibold text-slate-800">
                            Riwayat Pendidikan Terakhir
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="pt-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                        <div className="space-y-1.5">
                            <Label>Jenjang Pendidikan</Label>
                            <Input
                                value={data.highest_education}
                                onChange={e => setData('highest_education', e.target.value)}
                                placeholder="S1 / S2 / D3"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Nama Perguruan Tinggi</Label>
                            <Input
                                value={data.university_name}
                                onChange={e => setData('university_name', e.target.value)}
                                placeholder="UIN / UNY / dsb."
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Program Studi</Label>
                            <Input
                                value={data.study_program}
                                onChange={e => setData('study_program', e.target.value)}
                                placeholder="Pendidikan Agama Islam"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tahun Lulus</Label>
                            <Input
                                type="number"
                                min={1950}
                                max={2099}
                                value={data.graduation_year}
                                onChange={e => setData('graduation_year', e.target.value ? Number(e.target.value) : '')}
                                placeholder="2020"
                            />
                        </div>
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-3">
                    <Link href={`/gtk/${staff.id}`}>
                        <Button type="button" variant="outline">Batal</Button>
                    </Link>
                    <Button type="submit" disabled={processing} className="bg-teal-700 hover:bg-teal-800 gap-2">
                        <Save className="w-4 h-4" />
                        {processing ? 'Menyimpan...' : 'Simpan Perubahan'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
