import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Save, Plus, CheckCircle, Lock, Unlock, Loader2, Image as ImageIcon } from 'lucide-react';

export default function SettingsIndex({ settings, academicYears }: { settings: any, academicYears: any[] }) {
    const { data: profileData, setData: setProfileData, post: postProfile, processing: processingProfile, errors: errorsProfile } = useForm({
        madrasah_name: settings.madrasah_name || '',
        nsm: settings.nsm || '',
        npsn: settings.npsn || '',
        headmaster_name: settings.headmaster_name || '',
        headmaster_nip: settings.headmaster_nip || '',
        school_address: settings.school_address || '',
        madrasah_photo: null as File | null,
    });

    const { data: integrationData, setData: setIntegrationData, post: postIntegration, processing: processingIntegration, errors: errorsIntegration } = useForm({
        gemini_api_key: settings.gemini_api_key || '',
        api_key: settings.api_key || '',
        madrasah_name: settings.madrasah_name || '', // required by backend update()
    });

    const [photoPreview, setPhotoPreview] = useState<string | null>(settings.madrasah_photo || null);

    const handlePhotoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setProfileData('madrasah_photo', file);
            setPhotoPreview(URL.createObjectURL(file));
        }
    };

    const { data: yearData, setData: setYearData, post: postYear, processing: processingYear, errors: errorsYear, reset: resetYear } = useForm({
        year: '',
        semester: 'Ganjil',
    });

    const submitProfile = (e: React.FormEvent) => {
        e.preventDefault();
        postProfile('/pengaturan', {
            preserveScroll: true,
        });
    };

    const submitIntegration = (e: React.FormEvent) => {
        e.preventDefault();
        postIntegration('/pengaturan', {
            preserveScroll: true,
        });
    };

    const submitYear = (e: React.FormEvent) => {
        e.preventDefault();
        postYear('/pengaturan/academic-years', {
            preserveScroll: true,
            onSuccess: () => resetYear(),
        });
    };

    const setActiveYear = (id: string) => {
        router.post(`/pengaturan/academic-years/${id}/set-active`, {}, { preserveScroll: true });
    };

    const toggleLockYear = (id: string) => {
        router.post(`/pengaturan/academic-years/${id}/toggle-lock`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Pengaturan Sistem" />

            {/* Changed from max-w-6xl to w-full max-w-full to utilize full width */}
            <div className="flex flex-col gap-6 w-full max-w-full mx-auto">
                <div className="px-4 sm:px-6">
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">Pengaturan Sistem</h1>
                    <p className="text-slate-500">Kelola identitas madrasah, tahun ajaran, dan preferensi aplikasi Anda.</p>
                </div>

                <Tabs defaultValue="profile" className="w-full">
                    <TabsList className="mb-4 mx-4 sm:mx-6">
                        <TabsTrigger value="profile">Profil Madrasah</TabsTrigger>
                        <TabsTrigger value="academic-years">Tahun Ajaran</TabsTrigger>
                        <TabsTrigger value="integration">Integrasi & API</TabsTrigger>
                    </TabsList>

                    <TabsContent value="profile" className="px-4 sm:px-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Profil Institusi</CardTitle>
                                <CardDescription>Data ini akan digunakan sebagai kop surat pada hasil cetak dokumen dan laporan.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submitProfile} className="space-y-6">
                                    <div className="flex flex-col md:flex-row gap-8">
                                        {/* Kolom Kiri: Form */}
                                        <div className="flex-1 space-y-6">
                                            <div className="space-y-2">
                                                <Label htmlFor="madrasah_name">Nama Madrasah</Label>
                                                <Input 
                                                    id="madrasah_name" 
                                                    value={profileData.madrasah_name} 
                                                    onChange={e => setProfileData('madrasah_name', e.target.value)} 
                                                />
                                                {errorsProfile.madrasah_name && <p className="text-red-500 text-xs">{errorsProfile.madrasah_name}</p>}
                                            </div>
                                            
                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="space-y-2">
                                                    <Label htmlFor="nsm">NSM</Label>
                                                    <Input 
                                                        id="nsm" 
                                                        value={profileData.nsm} 
                                                        onChange={e => setProfileData('nsm', e.target.value)} 
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="npsn">NPSN</Label>
                                                    <Input 
                                                        id="npsn" 
                                                        value={profileData.npsn} 
                                                        onChange={e => setProfileData('npsn', e.target.value)} 
                                                    />
                                                </div>
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="headmaster_name">Nama Kepala Madrasah</Label>
                                                <Input 
                                                    id="headmaster_name" 
                                                    value={profileData.headmaster_name} 
                                                    onChange={e => setProfileData('headmaster_name', e.target.value)} 
                                                />
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="headmaster_nip">NIP Kepala Madrasah</Label>
                                                <Input 
                                                    id="headmaster_nip" 
                                                    value={profileData.headmaster_nip} 
                                                    onChange={e => setProfileData('headmaster_nip', e.target.value)} 
                                                    placeholder="Kosongkan jika bukan ASN"
                                                />
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="school_address">Alamat Lengkap</Label>
                                                <Input 
                                                    id="school_address" 
                                                    value={profileData.school_address} 
                                                    onChange={e => setProfileData('school_address', e.target.value)} 
                                                />
                                            </div>
                                        </div>

                                        {/* Kolom Kanan: Upload Foto */}
                                        <div className="w-full md:w-64 space-y-4">
                                            <Label>Foto Madrasah (Kop / Logo)</Label>
                                            <div className="border-2 border-dashed rounded-lg flex flex-col items-center justify-center p-4 bg-slate-50 relative overflow-hidden h-48">
                                                {photoPreview ? (
                                                    <img src={photoPreview} alt="Preview" className="absolute inset-0 w-full h-full object-contain p-2" />
                                                ) : (
                                                    <div className="flex flex-col items-center text-slate-400">
                                                        <ImageIcon className="w-10 h-10 mb-2" />
                                                        <span className="text-xs text-center">Belum ada foto</span>
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Input 
                                                    type="file" 
                                                    accept="image/*"
                                                    onChange={handlePhotoChange}
                                                    className="text-xs cursor-pointer"
                                                />
                                            </div>
                                            {errorsProfile.madrasah_photo && <p className="text-red-500 text-xs">{errorsProfile.madrasah_photo}</p>}
                                        </div>
                                    </div>
                                    
                                    <Button type="submit" disabled={processingProfile} className="bg-emerald-700 hover:bg-emerald-800 text-white">
                                        {processingProfile ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                                        Simpan Profil
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="academic-years">
                        <div className="flex flex-col xl:flex-row gap-6 items-start px-0 sm:px-6">
                            <Card className="w-full xl:w-1/3 shrink-0 mx-4 sm:mx-0">
                                <CardHeader>
                                    <CardTitle>Tambah Tahun Ajaran</CardTitle>
                                    <CardDescription>Buat entri tahun ajaran baru (contoh: 2026/2027).</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={submitYear} className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="year">Tahun (Format: YYYY/YYYY)</Label>
                                            <Input 
                                                id="year" 
                                                placeholder="2026/2027"
                                                value={yearData.year} 
                                                onChange={e => setYearData('year', e.target.value)} 
                                            />
                                            {errorsYear.year && <p className="text-red-500 text-xs">{errorsYear.year}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Semester</Label>
                                            <select 
                                                className="flex h-10 w-full items-center justify-between rounded-md border border-slate-200 bg-white px-3 py-2 text-sm ring-offset-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                                value={yearData.semester}
                                                onChange={e => setYearData('semester', e.target.value)}
                                            >
                                                <option value="Ganjil">Ganjil</option>
                                                <option value="Genap">Genap</option>
                                            </select>
                                        </div>
                                        <Button type="submit" disabled={processingYear} className="w-full">
                                            {processingYear ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Plus className="mr-2 h-4 w-4" />}
                                            Tambah
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>

                            <Card className="w-full border-x-0 sm:border-x rounded-none sm:rounded-xl">
                                <CardHeader className="px-4 sm:px-6">
                                    <CardTitle>Daftar Tahun Ajaran</CardTitle>
                                    <CardDescription>Manajemen status aktif dan kunci data (Read-Only).</CardDescription>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm text-left">
                                            <thead className="bg-slate-50 text-slate-700 font-semibold border-y">
                                                <tr>
                                                    <th className="px-4 py-3 whitespace-nowrap">Tahun / Semester</th>
                                                    <th className="px-4 py-3 whitespace-nowrap">Status</th>
                                                    <th className="px-4 py-3 whitespace-nowrap">Kunci (Read-Only)</th>
                                                    <th className="px-4 py-3 text-right whitespace-nowrap">Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {academicYears.length > 0 ? (
                                                    academicYears.map((ay: any) => (
                                                        <tr key={ay.id} className="hover:bg-slate-50">
                                                            <td className="px-4 py-4 font-medium whitespace-nowrap">
                                                                {ay.year} - {ay.semester}
                                                            </td>
                                                            <td className="px-4 py-4 whitespace-nowrap">
                                                                {ay.is_active ? (
                                                                    <Badge className="bg-emerald-500 hover:bg-emerald-600">Aktif</Badge>
                                                                ) : (
                                                                    <Badge variant="outline" className="text-slate-500">Tidak Aktif</Badge>
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-4 whitespace-nowrap">
                                                                {ay.is_locked ? (
                                                                    <span className="flex items-center text-amber-600 text-xs font-medium"><Lock className="w-3 h-3 mr-1"/> Terkunci</span>
                                                                ) : (
                                                                    <span className="flex items-center text-slate-500 text-xs"><Unlock className="w-3 h-3 mr-1"/> Terbuka</span>
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-4 text-right space-x-2 whitespace-nowrap">
                                                                {!ay.is_active && (
                                                                    <Button variant="outline" size="sm" onClick={() => setActiveYear(ay.id)}>
                                                                        <CheckCircle className="w-4 h-4 mr-1 text-emerald-600" />
                                                                        Set Aktif
                                                                    </Button>
                                                                )}
                                                                <Button variant={ay.is_locked ? "secondary" : "outline"} size="sm" onClick={() => toggleLockYear(ay.id)}>
                                                                    {ay.is_locked ? 'Buka Kunci' : 'Kunci'}
                                                                </Button>
                                                            </td>
                                                        </tr>
                                                    ))
                                                ) : (
                                                    <tr>
                                                        <td colSpan={4} className="px-4 py-8 text-center text-slate-500">Belum ada data tahun ajaran.</td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    <TabsContent value="integration" className="px-4 sm:px-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Integrasi & Sistem</CardTitle>
                                <CardDescription>Konfigurasi koneksi ke layanan eksternal (Google Drive, Gemini AI, EMIS).</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submitIntegration} className="space-y-6">
                                    <div className="space-y-2 max-w-xl">
                                        <Label htmlFor="gemini_api_key">Gemini AI API Key</Label>
                                        <Input 
                                            id="gemini_api_key" 
                                            type="password"
                                            placeholder="AIzaSyA..."
                                            value={integrationData.gemini_api_key} 
                                            onChange={e => setIntegrationData('gemini_api_key', e.target.value)} 
                                        />
                                        <p className="text-xs text-slate-500">
                                            Dapatkan API Key secara gratis dari Google AI Studio. Key ini digunakan untuk fitur ekstraksi dokumen otomatis.
                                        </p>
                                        {errorsIntegration.gemini_api_key && <p className="text-red-500 text-xs">{errorsIntegration.gemini_api_key}</p>}
                                    </div>

                                    <div className="space-y-2 max-w-xl">
                                        <Label htmlFor="api_key">API Gateway Key (SIPSMAD / PTSP / E-Surat)</Label>
                                        <Input
                                            id="api_key"
                                            type="password"
                                            placeholder="Isi dengan string acak panjang, mis. pm_live_..."
                                            value={(integrationData as any).api_key}
                                            onChange={e => setIntegrationData('api_key' as never, e.target.value as never)}
                                        />
                                        <p className="text-xs text-slate-500">
                                            Aplikasi satelit wajib mengirim key ini via header X-API-KEY. Kosongkan untuk menonaktifkan API (status 503).
                                        </p>
                                        {(errorsIntegration as any).api_key && <p className="text-red-500 text-xs">{(errorsIntegration as any).api_key}</p>}
                                    </div>

                                    <Button type="submit" disabled={processingIntegration}>
                                        {processingIntegration ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />}
                                        Simpan Integrasi
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
