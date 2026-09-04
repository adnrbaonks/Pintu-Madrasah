import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Printer, ChevronLeft, Edit, ShieldCheck, AlertTriangle } from 'lucide-react';
import { Separator } from '@/components/ui/separator';

export default function Show({ student }: { student: any }) {
    const renderField = (label: string, value: any) => (
        <div className="flex flex-col mb-4">
            <span className="text-sm text-slate-500">{label}</span>
            <span className="font-medium text-slate-900">{value || '-'}</span>
        </div>
    );

    return (
        <AppLayout>
            <Head title={`Detail Siswa - ${student.name}`} />

            <div className="flex flex-col gap-6 max-w-5xl mx-auto">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <Link href={route('students.index')}>
                            <Button variant="outline" size="icon">
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-slate-900">{student.name}</h1>
                            <p className="text-slate-500">NISN: {student.nisn || 'Belum ada'} • NIK: {student.nik || '-'}</p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" className="gap-2">
                            <Edit className="h-4 w-4" />
                            Edit Data
                        </Button>
                        <a href={route('students.print-biodata', student.id)} target="_blank">
                            <Button className="gap-2 bg-slate-900 hover:bg-slate-800 text-white">
                                <Printer className="h-4 w-4" />
                                Cetak Biodata
                            </Button>
                        </a>
                    </div>
                </div>

                {student.is_verified_by_ai && (
                    <div className="bg-lime-50 border border-lime-200 text-lime-800 p-4 rounded-lg flex items-start gap-3">
                        <ShieldCheck className="h-5 w-5 text-lime-600 mt-0.5" />
                        <div>
                            <h4 className="font-semibold text-sm">Terverifikasi AI (Gemini)</h4>
                            <p className="text-sm opacity-90">
                                Identitas utama siswa ini telah dicocokkan dengan dokumen asli (KK/Akta) menggunakan AI.
                                Data yang terkunci tidak akan tertimpa saat sinkronisasi EMIS.
                            </p>
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Left Column - Identitas */}
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle>Identitas Pribadi</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {renderField('Nama Lengkap', student.name)}
                                {renderField('Jenis Kelamin', student.gender === 'L' ? 'Laki-laki' : 'Perempuan')}
                                {renderField('Tempat Lahir', student.birth_place)}
                                {renderField('Tanggal Lahir', student.birth_date)}
                                {renderField('Agama', student.religion)}
                                {renderField('Anak ke / Jml Saudara', `${student.child_order || '-'} dari ${student.siblings_count || '-'} bersaudara`)}
                            </div>
                            <Separator className="my-6" />
                            <h3 className="font-semibold mb-4 text-slate-900">Alamat Tempat Tinggal</h3>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="sm:col-span-2">
                                    {renderField('Jalan / Dusun', student.address)}
                                </div>
                                {renderField('RT / RW', `${student.rt || '-'} / ${student.rw || '-'}`)}
                                {renderField('Kelurahan / Desa', student.village)}
                                {renderField('Kecamatan', student.district)}
                                {renderField('Kabupaten / Kota', student.regency)}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Right Column - Status & Ortu */}
                    <div className="flex flex-col gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Data Orang Tua</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    <div>
                                        <h4 className="font-semibold text-sm mb-2 text-slate-700">Ibu Kandung (Kunci Verval)</h4>
                                        <div className="bg-slate-50 p-3 rounded border">
                                            <p className="font-medium">{student.mother_name || '-'}</p>
                                            <p className="text-xs text-slate-500">NIK: {student.mother_nik || '-'}</p>
                                        </div>
                                    </div>
                                    <div>
                                        <h4 className="font-semibold text-sm mb-2 text-slate-700">Ayah Kandung</h4>
                                        <div className="bg-slate-50 p-3 rounded border">
                                            <p className="font-medium">{student.father_name || '-'}</p>
                                            <p className="text-xs text-slate-500">NIK: {student.father_nik || '-'}</p>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Bantuan Sosial</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-wrap gap-2">
                                    {student.pip_eligible ? <Badge className="bg-lime-500">Penerima PIP</Badge> : null}
                                    {student.pkh_eligible ? <Badge variant="outline">PKH</Badge> : null}
                                    {student.kks_eligible ? <Badge variant="outline">KKS</Badge> : null}
                                    {!student.pip_eligible && !student.pkh_eligible && !student.kks_eligible && (
                                        <span className="text-sm text-slate-500">Tidak ada data bansos</span>
                                    )}
                                </div>
                                {student.kip_number && (
                                    <div className="mt-4">
                                        <span className="text-sm text-slate-500 block mb-1">Nomor KIP:</span>
                                        <span className="font-mono bg-slate-100 px-2 py-1 rounded text-sm">{student.kip_number}</span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
