import React, { FormEvent } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardHeader, CardTitle, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ChevronLeft, Save, Loader2 } from 'lucide-react';
import { Separator } from '@/components/ui/separator';

export default function Edit({ student }: { student: any }) {
    const { data, setData, put, processing, errors } = useForm({
        name: student.name || '',
        nisn: student.nisn || '',
        nik: student.nik || '',
        gender: student.gender || 'L',
        birth_place: student.birth_place || '',
        birth_date: student.birth_date || '',
        religion: student.religion || '',
        address: student.address || '',
        village: student.village || '',
        district: student.district || '',
        regency: student.regency || '',
        father_name: student.father_name || '',
        father_nik: student.father_nik || '',
        father_job: student.father_job || '',
        father_phone: student.father_phone || '',
        mother_name: student.mother_name || '',
        mother_nik: student.mother_nik || '',
        mother_job: student.mother_job || '',
        mother_phone: student.mother_phone || '',
        kip_number: student.kip_number || '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(`/students/${student.id}`);
    };

    return (
        <AppLayout>
            <Head title={`Edit Siswa - ${student.name}`} />

            <div className="flex flex-col gap-6 max-w-4xl mx-auto">
                <div className="flex items-center gap-4">
                    <Link href={`/students/${student.id}`}>
                        <Button variant="outline" size="icon">
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Edit Data Siswa</h1>
                        <p className="text-slate-500">Ubah profil, alamat, dan data orang tua murid</p>
                    </div>
                </div>

                <form onSubmit={submit}>
                    <div className="flex flex-col gap-6">
                        {/* Identitas */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Identitas Pribadi</CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="name">Nama Lengkap <span className="text-red-500">*</span></Label>
                                    <Input id="name" value={data.name} onChange={e => setData('name', e.target.value)} required />
                                    {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="nik">NIK</Label>
                                    <Input id="nik" value={data.nik} onChange={e => setData('nik', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="nisn">NISN</Label>
                                    <Input id="nisn" value={data.nisn} onChange={e => setData('nisn', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="gender">Jenis Kelamin <span className="text-red-500">*</span></Label>
                                    <Select value={data.gender} onValueChange={(val: any) => setData('gender', val)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="L">Laki-laki</SelectItem>
                                            <SelectItem value="P">Perempuan</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="religion">Agama</Label>
                                    <Input id="religion" value={data.religion} onChange={e => setData('religion', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="birth_place">Tempat Lahir</Label>
                                    <Input id="birth_place" value={data.birth_place} onChange={e => setData('birth_place', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="birth_date">Tanggal Lahir</Label>
                                    <Input id="birth_date" type="date" value={data.birth_date} onChange={e => setData('birth_date', e.target.value)} />
                                </div>
                            </CardContent>
                        </Card>

                        {/* Alamat */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Alamat & Kontak</CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="address">Jalan / Dusun / RT RW</Label>
                                    <Input id="address" value={data.address} onChange={e => setData('address', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="village">Kelurahan / Desa</Label>
                                    <Input id="village" value={data.village} onChange={e => setData('village', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="district">Kecamatan</Label>
                                    <Input id="district" value={data.district} onChange={e => setData('district', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="regency">Kabupaten / Kota</Label>
                                    <Input id="regency" value={data.regency} onChange={e => setData('regency', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="kip_number">Nomor KIP (Opsional)</Label>
                                    <Input id="kip_number" value={data.kip_number} onChange={e => setData('kip_number', e.target.value)} />
                                </div>
                            </CardContent>
                        </Card>

                        {/* Orang Tua */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Data Orang Tua</CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                {/* Ayah */}
                                <div className="space-y-4">
                                    <h3 className="font-semibold text-slate-800 border-b pb-2">Ayah Kandung</h3>
                                    <div className="space-y-2">
                                        <Label htmlFor="father_name">Nama Ayah</Label>
                                        <Input id="father_name" value={data.father_name} onChange={e => setData('father_name', e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="father_nik">NIK Ayah</Label>
                                        <Input id="father_nik" value={data.father_nik} onChange={e => setData('father_nik', e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="father_job">Pekerjaan</Label>
                                        <Input id="father_job" value={data.father_job} onChange={e => setData('father_job', e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="father_phone">No HP</Label>
                                        <Input id="father_phone" value={data.father_phone} onChange={e => setData('father_phone', e.target.value)} />
                                    </div>
                                </div>
                                
                                {/* Ibu */}
                                <div className="space-y-4">
                                    <h3 className="font-semibold text-slate-800 border-b pb-2">Ibu Kandung</h3>
                                    <div className="space-y-2">
                                        <Label htmlFor="mother_name">Nama Ibu</Label>
                                        <Input id="mother_name" value={data.mother_name} onChange={e => setData('mother_name', e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="mother_nik">NIK Ibu</Label>
                                        <Input id="mother_nik" value={data.mother_nik} onChange={e => setData('mother_nik', e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="mother_job">Pekerjaan</Label>
                                        <Input id="mother_job" value={data.mother_job} onChange={e => setData('mother_job', e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="mother_phone">No HP</Label>
                                        <Input id="mother_phone" value={data.mother_phone} onChange={e => setData('mother_phone', e.target.value)} />
                                    </div>
                                </div>
                            </CardContent>
                            <CardFooter className="flex justify-end bg-slate-50 border-t p-4">
                                <Button type="submit" disabled={processing} className="gap-2 bg-slate-900 hover:bg-slate-800">
                                    {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                                    Simpan Perubahan
                                </Button>
                            </CardFooter>
                        </Card>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
