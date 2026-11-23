import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Award, Plus, Eye, CheckCircle2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';

interface InstrumentItem {
    id: string;
    code: string;
    title: string;
    period_year: string;
    assessment_type: string;
    status: string;
    overall_score: number;
    predicate?: string | null;
    components_count: number;
    assessments_count: number;
    academic_year?: { id: string; year: string } | null;
    headmaster?: { id: string; name: string } | null;
    finalizer?: { id: string; name: string } | null;
}

export default function PkkmIndex({
    instruments,
    academicYears,
    headmasters,
}: {
    instruments: InstrumentItem[];
    academicYears: { id: string; year: string; is_active: boolean }[];
    headmasters: { id: string; name: string; role: string }[];
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openCreate, setOpenCreate] = useState(false);

    const activeYear = academicYears.find(y => y.is_active);

    const form = useForm({
        code: '',
        title: '',
        period_year: new Date().getFullYear().toString(),
        assessment_type: 'annual',
        academic_year_id: activeYear?.id ?? '',
        headmaster_user_id: headmasters[0]?.id ?? '',
        description: '',
        seed_default_components: true,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/pkkm', {
            onSuccess: () => {
                setOpenCreate(false);
                form.reset();
                toast.success('Instrumen PKKM dinamis berhasil dibuat.');
            },
        });
    }

    return (
        <AppLayout>
            <Head title="PKKM — Penilaian Kinerja Kepala Madrasah" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl p-2.5 bg-amber-500/10">
                        <Award className="w-5 h-5 text-amber-600" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">Penilaian Kinerja Kepala Madrasah (PKKM)</h1>
                        <p className="text-sm text-muted-foreground">
                            Instrumen penilaian kinerja berbasis bukti digital terintegrasi arsip dengan komponen indikator dinamis per periode.
                        </p>
                    </div>
                </div>
                <Button onClick={() => setOpenCreate(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Buat Instrumen Periode
                </Button>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Total Periode Instrumen</div>
                        <div className="text-2xl font-bold mt-1">{instruments.length}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Sudah Difinalisasi</div>
                        <div className="text-2xl font-bold text-emerald-600 mt-1">
                            {instruments.filter(i => i.status === 'finalized').length}
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Dalam Proses / Penilaian</div>
                        <div className="text-2xl font-bold text-amber-600 mt-1">
                            {instruments.filter(i => i.status !== 'finalized').length}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Daftar Instrumen PKKM</CardTitle>
                    <CardDescription className="text-xs">
                        Setiap instrumen mendukung penyesuaian bobot, penambahan indikator baru, verifikasi asesor, dan bukti fisik digital.
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Kode & Judul Instrumen</th>
                                    <th className="px-4 py-2.5 font-medium">Periode & Kepala Madrasah</th>
                                    <th className="px-4 py-2.5 font-medium">Komponen & Penilaian</th>
                                    <th className="px-4 py-2.5 font-medium">Skor Akhir & Predikat</th>
                                    <th className="px-4 py-2.5 font-medium">Status</th>
                                    <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {instruments.map(inst => (
                                    <tr key={inst.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs font-semibold text-amber-600">{inst.code}</code>
                                            <div className="font-medium mt-0.5">{inst.title}</div>
                                            <div className="text-xs text-muted-foreground capitalize">Tipe: {inst.assessment_type === 'annual' ? 'Tahunan' : '4 Tahunan'}</div>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="font-semibold">Tahun {inst.period_year}</div>
                                            <div className="text-muted-foreground">Kamad: {inst.headmaster?.name ?? '—'}</div>
                                            {inst.academic_year && <div className="text-muted-foreground">TA {inst.academic_year.year}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div>{inst.components_count} Komponen Indikator</div>
                                            <div className="text-muted-foreground">{inst.assessments_count} Dinilai</div>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="text-lg font-bold text-amber-600">{inst.overall_score}</div>
                                            {inst.predicate && <Badge variant="secondary" className="mt-0.5">{inst.predicate}</Badge>}
                                        </td>
                                        <td className="px-4 py-3">
                                            {inst.status === 'finalized' ? (
                                                <Badge className="bg-emerald-500/15 text-emerald-600 border-emerald-500/20 gap-1">
                                                    <CheckCircle2 className="w-3 h-3" /> Final
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline" className="uppercase text-[10px]">
                                                    {inst.status}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={`/pkkm/${inst.id}`}>
                                                <Button variant="outline" size="sm">
                                                    <Eye className="w-3.5 h-3.5 mr-1" /> Buka Instrumen
                                                </Button>
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {instruments.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-sm text-muted-foreground">
                                            Belum ada instrumen penilaian PKKM.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Buat Instrumen PKKM Baru</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-3.5">
                        <div className="space-y-1">
                            <Label>Nama / Judul Instrumen *</Label>
                            <Input
                                value={form.data.title}
                                onChange={e => form.setData('title', e.target.value)}
                                placeholder="Contoh: PKKM Tahunan 2026 MTs Terpadu"
                                required
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Tahun Penilaian *</Label>
                                <Input
                                    value={form.data.period_year}
                                    onChange={e => form.setData('period_year', e.target.value)}
                                    placeholder="2026"
                                    required
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Tipe Asesmen</Label>
                                <Select
                                    value={form.data.assessment_type}
                                    onValueChange={(v: string | null) => v && form.setData('assessment_type', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="annual">Tahunan</SelectItem>
                                        <SelectItem value="four_year">4 Tahunan</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Kepala Madrasah yang Dinilai</Label>
                            <Select
                                value={form.data.headmaster_user_id}
                                onValueChange={(v: string | null) => v && form.setData('headmaster_user_id', v)}
                            >
                                <SelectTrigger><SelectValue placeholder="Pilih Kepala Madrasah" /></SelectTrigger>
                                <SelectContent>
                                    {headmasters.map(h => <SelectItem key={h.id} value={h.id}>{h.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-center gap-2 pt-2 text-xs">
                            <input
                                type="checkbox"
                                id="seed_comp"
                                checked={form.data.seed_default_components}
                                onChange={e => form.setData('seed_default_components', e.target.checked)}
                            />
                            <label htmlFor="seed_comp" className="cursor-pointer">
                                Sertakan 5 Unsur Standar PKKM Awal (Dapat diubah & ditambah indikator baru kapan saja)
                            </label>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpenCreate(false)}>Batal</Button>
                            <Button type="submit" disabled={form.processing}>Buat Instrumen</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
