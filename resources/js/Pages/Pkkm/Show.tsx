import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { ArrowLeft, Plus, Award, CheckCircle2, ShieldCheck, Paperclip, Lock } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';

interface EvidenceItem {
    id: string;
    title: string;
    original_name?: string | null;
    external_url?: string | null;
    verification_status: string;
    archive?: { id: string; archive_number: string; title: string } | null;
    uploader?: { id: string; name: string } | null;
}

interface ComponentItem {
    id: string;
    category_code: string;
    category_name: string;
    code: string;
    title: string;
    indicator_description?: string | null;
    required_evidence_hint?: string | null;
    weight: number;
    max_score: number;
    pic?: { id: string; name: string } | null;
    assessment?: {
        id: string;
        self_score: number;
        verified_score?: number | null;
        self_notes?: string | null;
        assessor_notes?: string | null;
        status: string;
        assessor?: { id: string; name: string } | null;
    } | null;
    evidence: EvidenceItem[];
}

interface InstrumentDetail {
    id: string;
    code: string;
    title: string;
    period_year: string;
    assessment_type: string;
    status: string;
    overall_score: number;
    predicate?: string | null;
    headmaster?: { id: string; name: string } | null;
    finalizer?: { id: string; name: string } | null;
    components: ComponentItem[];
}

export default function PkkmShow({
    instrument,
    users,
    archives,
}: {
    instrument: InstrumentDetail;
    users: { id: string; name: string; role: string }[];
    archives: { id: string; archive_number: string; title: string }[];
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openComponent, setOpenComponent] = useState(false);
    const [assessTarget, setAssessTarget] = useState<ComponentItem | null>(null);
    const [evidenceTarget, setEvidenceTarget] = useState<ComponentItem | null>(null);

    const isFinalized = instrument.status === 'finalized';

    const componentForm = useForm({
        category_code: 'A-USAHA',
        category_name: 'A. Usaha Pengembangan Madrasah',
        code: '',
        title: '',
        indicator_description: '',
        required_evidence_hint: '',
        weight: '1.0',
        max_score: '100',
        pic_user_id: '',
    });

    const assessForm = useForm({
        self_score: '80',
        verified_score: '85',
        self_notes: '',
        assessor_notes: '',
    });

    const evidenceForm = useForm<{
        title: string;
        archive_id: string;
        file: File | null;
        external_url: string;
        notes: string;
    }>({
        title: '',
        archive_id: '',
        file: null,
        external_url: '',
        notes: '',
    });

    function submitComponent(e: React.FormEvent) {
        e.preventDefault();
        componentForm.post(`/pkkm/${instrument.id}/components`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpenComponent(false);
                componentForm.reset();
                toast.success('Komponen indikator berhasil ditambahkan.');
            },
        });
    }

    function submitAssess(e: React.FormEvent) {
        e.preventDefault();
        if (!assessTarget) return;
        assessForm.post(`/pkkm/components/${assessTarget.id}/assess`, {
            preserveScroll: true,
            onSuccess: () => {
                setAssessTarget(null);
                toast.success('Nilai komponen diperbarui.');
            },
        });
    }

    function submitEvidence(e: React.FormEvent) {
        e.preventDefault();
        if (!evidenceTarget) return;
        evidenceForm.post(`/pkkm/components/${evidenceTarget.id}/evidence`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setEvidenceTarget(null);
                evidenceForm.reset();
                toast.success('Bukti fisik berhasil dikaitkan.');
            },
        });
    }

    function handleFinalize() {
        if (!confirm('Apakah Anda yakin ingin memfinalisasi instrumen PKKM ini? Skor dan penilaian akan dikunci.')) {
            return;
        }
        router.post(`/pkkm/${instrument.id}/finalize`, {}, {
            preserveScroll: true,
            onSuccess: () => toast.success('Instrumen PKKM resmi difinalisasi.'),
        });
    }

    return (
        <AppLayout>
            <Head title={`Instrumen ${instrument.code}`} />

            <div className="mb-4">
                <Link href="/pkkm" className="inline-flex items-center text-xs text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="w-3.5 h-3.5 mr-1" /> Kembali ke Daftar Instrumen PKKM
                </Link>
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div>
                    <div className="flex items-center gap-2">
                        <code className="font-mono text-xs font-semibold text-amber-600">{instrument.code}</code>
                        <Badge variant="outline">Tahun {instrument.period_year}</Badge>
                        <Badge variant="secondary" className="capitalize">{instrument.assessment_type === 'annual' ? 'Tahunan' : '4 Tahunan'}</Badge>
                        {isFinalized && (
                            <Badge className="bg-emerald-500/15 text-emerald-600 border-emerald-500/20 gap-1">
                                <Lock className="w-3 h-3" /> Terkunci (Final)
                            </Badge>
                        )}
                    </div>
                    <h1 className="text-xl font-bold mt-1">{instrument.title}</h1>
                    <p className="text-xs text-muted-foreground">Kepala Madrasah: {instrument.headmaster?.name ?? '—'}</p>
                </div>

                <div className="flex items-center gap-2">
                    <div className="text-right mr-3">
                        <div className="text-xs text-muted-foreground">Skor Akhir & Predikat</div>
                        <div className="flex items-center gap-2 justify-end">
                            <span className="text-2xl font-bold text-amber-600">{instrument.overall_score}</span>
                            {instrument.predicate && <Badge>{instrument.predicate}</Badge>}
                        </div>
                    </div>
                    {!isFinalized && (
                        <>
                            <Button variant="outline" size="sm" onClick={() => setOpenComponent(true)}>
                                <Plus className="w-3.5 h-3.5 mr-1" /> Tambah Indikator
                            </Button>
                            <Button size="sm" onClick={handleFinalize}>
                                <CheckCircle2 className="w-3.5 h-3.5 mr-1" /> Finalisasi Hasil
                            </Button>
                        </>
                    )}
                </div>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="space-y-4">
                {instrument.components.map(comp => (
                    <Card key={comp.id} className="border-l-4 border-l-amber-500">
                        <CardHeader className="py-3 px-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <div className="text-xs font-semibold text-muted-foreground">{comp.category_name}</div>
                                <CardTitle className="text-sm flex items-center gap-2 mt-0.5">
                                    <code className="font-mono text-xs bg-muted px-1.5 py-0.5 rounded text-amber-600">
                                        {comp.code}
                                    </code>
                                    <span>{comp.title}</span>
                                    <Badge variant="outline" className="text-[10px]">Bobot: {comp.weight}</Badge>
                                </CardTitle>
                                {comp.indicator_description && (
                                    <CardDescription className="text-xs mt-1">{comp.indicator_description}</CardDescription>
                                )}
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                <div className="text-right text-xs mr-2">
                                    <div className="text-muted-foreground">Evaluasi Diri / Verifikasi</div>
                                    <div className="font-semibold">
                                        {comp.assessment?.self_score ?? 0} / <span className="text-amber-600">{comp.assessment?.verified_score ?? '—'}</span>
                                    </div>
                                </div>
                                {!isFinalized && (
                                    <>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setAssessTarget(comp);
                                                assessForm.setData({
                                                    self_score: comp.assessment?.self_score?.toString() ?? '80',
                                                    verified_score: comp.assessment?.verified_score?.toString() ?? '85',
                                                    self_notes: comp.assessment?.self_notes ?? '',
                                                    assessor_notes: comp.assessment?.assessor_notes ?? '',
                                                });
                                            }}
                                        >
                                            <ShieldCheck className="w-3.5 h-3.5 mr-1" /> Nilai
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setEvidenceTarget(comp);
                                                evidenceForm.reset();
                                            }}
                                        >
                                            <Paperclip className="w-3.5 h-3.5 mr-1" /> Bukti ({comp.evidence.length})
                                        </Button>
                                    </>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="px-4 py-2 border-t bg-muted/20 text-xs">
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                <div className="text-muted-foreground">
                                    <span className="font-medium text-foreground">Bukti yang Disyaratkan: </span>
                                    {comp.required_evidence_hint ?? 'Dokumen kebijakan/program/laporan terkait'}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {comp.evidence.map(ev => (
                                        <div key={ev.id} className="inline-flex items-center gap-1 bg-background border px-2 py-0.5 rounded text-[11px] text-indigo-600">
                                            <Paperclip className="w-3 h-3" />
                                            <span>{ev.title}</span>
                                            {ev.archive && (
                                                <Link href={`/arsip-digital/${ev.archive.id}`} className="underline font-mono">
                                                    ({ev.archive.archive_number})
                                                </Link>
                                            )}
                                        </div>
                                    ))}
                                    {comp.evidence.length === 0 && (
                                        <span className="text-amber-600 italic">Belum ada bukti fisik diunggah.</span>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {/* Dialog Tambah Indikator Baru */}
            <Dialog open={openComponent} onOpenChange={setOpenComponent}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Tambah Komponen Indikator PKKM</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitComponent} className="space-y-3">
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label>Kode Kategori *</Label>
                                <Input value={componentForm.data.category_code} onChange={e => componentForm.setData('category_code', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Nama Unsur/Kategori *</Label>
                                <Input value={componentForm.data.category_name} onChange={e => componentForm.setData('category_name', e.target.value)} required />
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            <div>
                                <Label>Kode Indikator *</Label>
                                <Input value={componentForm.data.code} onChange={e => componentForm.setData('code', e.target.value)} placeholder="1.2" required />
                            </div>
                            <div>
                                <Label>Bobot *</Label>
                                <Input type="number" step="0.1" value={componentForm.data.weight} onChange={e => componentForm.setData('weight', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Skor Maks *</Label>
                                <Input type="number" value={componentForm.data.max_score} onChange={e => componentForm.setData('max_score', e.target.value)} required />
                            </div>
                        </div>
                        <div>
                            <Label>Nama / Pernyataan Indikator *</Label>
                            <Input value={componentForm.data.title} onChange={e => componentForm.setData('title', e.target.value)} required />
                        </div>
                        <div>
                            <Label>Deskripsi Indikator</Label>
                            <Input value={componentForm.data.indicator_description} onChange={e => componentForm.setData('indicator_description', e.target.value)} />
                        </div>
                        <div>
                            <Label>Petunjuk Bukti Fisik</Label>
                            <Input value={componentForm.data.required_evidence_hint} onChange={e => componentForm.setData('required_evidence_hint', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={componentForm.processing}>Simpan Komponen</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog Penilaian Skor */}
            <Dialog open={!!assessTarget} onOpenChange={open => !open && setAssessTarget(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Penilaian Komponen {assessTarget?.code}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitAssess} className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Skor Evaluasi Diri (0-{assessTarget?.max_score}) *</Label>
                                <Input
                                    type="number"
                                    step="0.1"
                                    value={assessForm.data.self_score}
                                    onChange={e => assessForm.setData('self_score', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label>Skor Asesor / Pengawas</Label>
                                <Input
                                    type="number"
                                    step="0.1"
                                    value={assessForm.data.verified_score}
                                    onChange={e => assessForm.setData('verified_score', e.target.value)}
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Catatan Evaluasi Diri</Label>
                            <Input value={assessForm.data.self_notes} onChange={e => assessForm.setData('self_notes', e.target.value)} />
                        </div>
                        <div>
                            <Label>Catatan Verifikasi Asesor</Label>
                            <Input value={assessForm.data.assessor_notes} onChange={e => assessForm.setData('assessor_notes', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={assessForm.processing}>Simpan Nilai</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog Bukti Fisik */}
            <Dialog open={!!evidenceTarget} onOpenChange={open => !open && setEvidenceTarget(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Kaitkan Bukti Fisik {evidenceTarget?.code}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitEvidence} className="space-y-3">
                        <div>
                            <Label>Judul Bukti Fisik *</Label>
                            <Input value={evidenceForm.data.title} onChange={e => evidenceForm.setData('title', e.target.value)} placeholder="Contoh: SK Tim Pengembang Madrasah" required />
                        </div>
                        <div>
                            <Label>Kaitkan Dokumen Arsip Digital</Label>
                            <Select
                                value={evidenceForm.data.archive_id || '__none__'}
                                onValueChange={(v: string | null) => evidenceForm.setData('archive_id', !v || v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="— Pilih dari Arsip Digital —" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">— Tidak dikaitkan —</SelectItem>
                                    {archives.map(a => <SelectItem key={a.id} value={a.id}>{a.archive_number} — {a.title}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Atau Unggah Berkas Fisik</Label>
                            <Input type="file" onChange={e => evidenceForm.setData('file', e.target.files?.[0] ?? null)} />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={evidenceForm.processing}>Simpan Bukti</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
