import { Head, useForm, Link } from '@inertiajs/react';
import { School, Send, ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

export default function PublicForm({ activePeriod, madrasahName, madrasahAddress }: {
    activePeriod: { id: string; name: string } | null;
    madrasahName: string;
    madrasahAddress: string;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        gender: 'L',
        birth_place: '',
        birth_date: '',
        nisn: '',
        nik: '',
        phone_number: '',
        address: '',
        village: '',
        district: '',
        regency: '',
        prev_school_name: '',
        father_name: '',
        father_phone: '',
        mother_name: '',
        mother_phone: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/ppdb/daftar');
    }

    return (
        <>
            <Head title={`PPDB — ${madrasahName}`} />
            <div className="min-h-screen bg-slate-50 py-8 px-4">
                <div className="max-w-2xl mx-auto">
                    <div className="text-center mb-6">
                        <div className="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-emerald-600 mb-3">
                            <School className="w-7 h-7 text-white" />
                        </div>
                        <h1 className="text-2xl font-bold">Pendaftaran Siswa Baru</h1>
                        <p className="text-sm text-muted-foreground mt-1">{madrasahName}</p>
                        {madrasahAddress && <p className="text-xs text-muted-foreground">{madrasahAddress}</p>}
                    </div>

                    {!activePeriod ? (
                        <Card>
                            <CardContent className="py-10 text-center">
                                <p className="font-semibold">Pendaftaran PPDB sedang ditutup.</p>
                                <p className="text-sm text-muted-foreground mt-1">Silakan hubungi TU madrasah untuk informasi gelombang berikutnya.</p>
                                <Link href="/login" className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline mt-4">
                                    <ArrowLeft className="w-4 h-4" /> Login operator
                                </Link>
                            </CardContent>
                        </Card>
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Formulir Pendaftaran</CardTitle>
                                <CardDescription>Periode: {activePeriod.name} • Isi dengan data sebenar-benarnya sesuai KK/Akta.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submit} className="space-y-4">
                                    <div className="space-y-1.5">
                                        <Label>Nama Lengkap (sesuai Akta/KK) *</Label>
                                        <Input value={data.name} onChange={e => setData('name', e.target.value)} required />
                                        {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <Label>Jenis Kelamin *</Label>
                                            <Select value={data.gender} onValueChange={(v: string | null) => v && setData('gender', v)}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="L">Laki-laki</SelectItem>
                                                    <SelectItem value="P">Perempuan</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>No. HP/WA Aktif *</Label>
                                            <Input value={data.phone_number} onChange={e => setData('phone_number', e.target.value)} placeholder="08..." required />
                                            {errors.phone_number && <p className="text-xs text-destructive">{errors.phone_number}</p>}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <Label>Tempat Lahir *</Label>
                                            <Input value={data.birth_place} onChange={e => setData('birth_place', e.target.value)} required />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Tanggal Lahir *</Label>
                                            <Input type="date" value={data.birth_date} onChange={e => setData('birth_date', e.target.value)} required />
                                            {errors.birth_date && <p className="text-xs text-destructive">{errors.birth_date}</p>}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <Label>NISN (jika ada)</Label>
                                            <Input value={data.nisn} onChange={e => setData('nisn', e.target.value)} inputMode="numeric" />
                                            {errors.nisn && <p className="text-xs text-destructive">{errors.nisn}</p>}
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>NIK (jika ada)</Label>
                                            <Input value={data.nik} onChange={e => setData('nik', e.target.value)} inputMode="numeric" />
                                        </div>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Alamat Lengkap *</Label>
                                        <Textarea value={data.address} onChange={e => setData('address', e.target.value)} rows={2} required />
                                    </div>
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="space-y-1.5">
                                            <Label>Desa</Label>
                                            <Input value={data.village} onChange={e => setData('village', e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Kecamatan</Label>
                                            <Input value={data.district} onChange={e => setData('district', e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Kab/Kota</Label>
                                            <Input value={data.regency} onChange={e => setData('regency', e.target.value)} />
                                        </div>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Asal Sekolah (SD/MI) *</Label>
                                        <Input value={data.prev_school_name} onChange={e => setData('prev_school_name', e.target.value)} required />
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <Label>Nama Ayah</Label>
                                            <Input value={data.father_name} onChange={e => setData('father_name', e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>No. HP Ayah</Label>
                                            <Input value={data.father_phone} onChange={e => setData('father_phone', e.target.value)} />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <Label>Nama Ibu Kandung *</Label>
                                            <Input value={data.mother_name} onChange={e => setData('mother_name', e.target.value)} required />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>No. HP Ibu</Label>
                                            <Input value={data.mother_phone} onChange={e => setData('mother_phone', e.target.value)} />
                                        </div>
                                    </div>
                                    <Button type="submit" disabled={processing} className="w-full h-11">
                                        <Send className="w-4 h-4 mr-2" />
                                        {processing ? 'Mengirim...' : 'Kirim Pendaftaran'}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
