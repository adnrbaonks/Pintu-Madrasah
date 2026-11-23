import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { ArrowLeft, Plus, FileCheck, CheckCircle2, Send, Paperclip } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Progress } from '@/components/ui/progress';
import { toast } from 'sonner';

interface ActivityItem {
    id: string;
    title: string;
    target_indicator?: string | null;
    scheduled_start?: string | null;
    scheduled_end?: string | null;
    budget_allocation: number;
    budget_realization: number;
    progress_percent: number;
    status: string;
    pic?: { id: string; name: string } | null;
}

interface ReportItem {
    id: string;
    title: string;
    report_date: string;
    summary: string;
    outcomes?: string | null;
    realized_budget: number;
    achieved_percent: number;
    status: string;
    review_notes?: string | null;
    activity?: { id: string; title: string } | null;
    submitter?: { id: string; name: string } | null;
    verifier?: { id: string; name: string } | null;
    approver?: { id: string; name: string } | null;
    evidence: {
        id: string;
        title: string;
        original_name?: string | null;
        external_url?: string | null;
        archive?: { id: string; archive_number: string; title: string } | null;
    }[];
}

interface ProgramDetail {
    id: string;
    code: string;
    title: string;
    description?: string | null;
    budget_amount: number;
    status: string;
    progress_percent: number;
    academic_year?: { id: string; year: string } | null;
    organization_unit?: { id: string; name: string } | null;
    pic?: { id: string; name: string } | null;
    activities: ActivityItem[];
    reports: ReportItem[];
}

export default function ProgramShow({
    program,
    archives,
}: {
    program: ProgramDetail;
    users: { id: string; name: string; role: string }[];
    archives: { id: string; archive_number: string; title: string }[];
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openActivity, setOpenActivity] = useState(false);
    const [openReport, setOpenReport] = useState(false);

    const activityForm = useForm({
        title: '',
        target_indicator: '',
        scheduled_start: new Date().toISOString().slice(0, 10),
        budget_allocation: '0',
    });

    const reportForm = useForm<{
        program_activity_id: string;
        title: string;
        report_date: string;
        summary: string;
        outcomes: string;
        realized_budget: string;
        achieved_percent: string;
        evidence_title: string;
        evidence_archive_id: string;
        evidence_file: File | null;
    }>({
        program_activity_id: program.activities[0]?.id ?? '',
        title: '',
        report_date: new Date().toISOString().slice(0, 10),
        summary: '',
        outcomes: '',
        realized_budget: '0',
        achieved_percent: '100',
        evidence_title: '',
        evidence_archive_id: '',
        evidence_file: null,
    });

    function submitActivity(e: React.FormEvent) {
        e.preventDefault();
        activityForm.post(`/program-kerja/${program.id}/activities`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpenActivity(false);
                activityForm.reset();
                toast.success('Kegiatan ditambahkan.');
            },
        });
    }

    function submitReport(e: React.FormEvent) {
        e.preventDefault();
        reportForm.post(`/program-kerja/${program.id}/reports`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setOpenReport(false);
                reportForm.reset();
                toast.success('Laporan kegiatan dikirim.');
            },
        });
    }

    return (
        <AppLayout>
            <Head title={`Program ${program.code}`} />

            <div className="mb-4">
                <Link href="/program-kerja" className="inline-flex items-center text-xs text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="w-3.5 h-3.5 mr-1" /> Kembali ke Daftar Program Kerja
                </Link>
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div>
                    <div className="flex items-center gap-2">
                        <code className="font-mono text-xs font-semibold text-teal-600">{program.code}</code>
                        <Badge variant="outline" className="uppercase">{program.status}</Badge>
                    </div>
                    <h1 className="text-xl font-bold mt-1">{program.title}</h1>
                    {program.description && <p className="text-sm text-muted-foreground">{program.description}</p>}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {program.status === 'draft' && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => router.post(`/program-kerja/${program.id}/status`, { status: 'submitted' })}
                        >
                            <Send className="w-3.5 h-3.5 mr-1" /> Ajukan Program
                        </Button>
                    )}
                    {program.status === 'submitted' && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => router.post(`/program-kerja/${program.id}/status`, { status: 'verified' })}
                        >
                            Verifikasi TU
                        </Button>
                    )}
                    {(program.status === 'verified' || program.status === 'submitted') && (
                        <Button
                            variant="default"
                            size="sm"
                            onClick={() => router.post(`/program-kerja/${program.id}/status`, { status: 'approved' })}
                        >
                            <CheckCircle2 className="w-3.5 h-3.5 mr-1" /> Setujui (Kamad)
                        </Button>
                    )}
                    <Button variant="outline" size="sm" onClick={() => setOpenActivity(true)}>
                        <Plus className="w-3.5 h-3.5 mr-1" /> Tambah Kegiatan
                    </Button>
                    <Button size="sm" onClick={() => setOpenReport(true)}>
                        <FileCheck className="w-3.5 h-3.5 mr-1" /> Kirim Laporan Realisasi
                    </Button>
                </div>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="space-y-6">
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Rincian Kegiatan ({program.activities.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2 font-medium">Kegiatan & Indikator</th>
                                    <th className="px-4 py-2 font-medium">Jadwal</th>
                                    <th className="px-4 py-2 font-medium">Alokasi vs Realisasi</th>
                                    <th className="px-4 py-2 font-medium">Progres</th>
                                </tr>
                            </thead>
                            <tbody>
                                {program.activities.map(act => (
                                    <tr key={act.id} className="border-b last:border-0">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{act.title}</div>
                                            {act.target_indicator && (
                                                <div className="text-xs text-muted-foreground">Indikator: {act.target_indicator}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-xs">{act.scheduled_start ?? '—'}</td>
                                        <td className="px-4 py-3 text-xs">
                                            <div>Pagu: Rp {act.budget_allocation.toLocaleString('id-ID')}</div>
                                            <div className="text-muted-foreground">
                                                Realisasi: Rp {act.budget_realization.toLocaleString('id-ID')}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 w-40">
                                            <div className="flex justify-between text-xs mb-1">
                                                <span>{act.status}</span>
                                                <span className="font-semibold">{act.progress_percent}%</span>
                                            </div>
                                            <Progress value={act.progress_percent} className="h-1.5" />
                                        </td>
                                    </tr>
                                ))}
                                {program.activities.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-4 py-8 text-center text-xs text-muted-foreground">
                                            Belum ada rincian kegiatan.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Laporan Realisasi & Bukti Fisik ({program.reports.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2 font-medium">Tanggal & Judul Laporan</th>
                                    <th className="px-4 py-2 font-medium">Capaian & Realisasi</th>
                                    <th className="px-4 py-2 font-medium">Bukti Pendukung</th>
                                    <th className="px-4 py-2 font-medium">Status Review</th>
                                    <th className="px-4 py-2 font-medium text-right">Verifikasi / Setujui</th>
                                </tr>
                            </thead>
                            <tbody>
                                {program.reports.map(rep => (
                                    <tr key={rep.id} className="border-b last:border-0">
                                        <td className="px-4 py-3">
                                            <div className="text-xs text-muted-foreground">{rep.report_date}</div>
                                            <div className="font-medium">{rep.title}</div>
                                            <p className="text-xs text-muted-foreground mt-0.5">{rep.summary}</p>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="font-semibold">{rep.achieved_percent}% tercapai</div>
                                            <div>Rp {rep.realized_budget.toLocaleString('id-ID')}</div>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {rep.evidence.map(ev => (
                                                <div key={ev.id} className="flex items-center gap-1 text-indigo-600">
                                                    <Paperclip className="w-3 h-3" />
                                                    <span>{ev.title}</span>
                                                    {ev.archive && (
                                                        <Link href={`/arsip-digital/${ev.archive.id}`} className="underline ml-1">
                                                            ({ev.archive.archive_number})
                                                        </Link>
                                                    )}
                                                </div>
                                            ))}
                                            {rep.evidence.length === 0 && <span className="text-muted-foreground">—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <Badge variant="outline" className="uppercase text-[10px]">{rep.status}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-1">
                                                {rep.status === 'submitted' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => router.post(`/program-kerja/reports/${rep.id}/review`, { action: 'verify' })}
                                                    >
                                                        Verifikasi TU
                                                    </Button>
                                                )}
                                                {rep.status !== 'approved' && (
                                                    <Button
                                                        variant="default"
                                                        size="sm"
                                                        onClick={() => router.post(`/program-kerja/reports/${rep.id}/review`, { action: 'approve' })}
                                                    >
                                                        Setujui Kamad
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {program.reports.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-xs text-muted-foreground">
                                            Belum ada laporan pelaksanaan yang dikirim.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={openActivity} onOpenChange={setOpenActivity}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Tambah Kegiatan Program</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitActivity} className="space-y-3">
                        <div>
                            <Label>Nama Kegiatan *</Label>
                            <Input value={activityForm.data.title} onChange={e => activityForm.setData('title', e.target.value)} required />
                        </div>
                        <div>
                            <Label>Indikator Target</Label>
                            <Input value={activityForm.data.target_indicator} onChange={e => activityForm.setData('target_indicator', e.target.value)} />
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label>Jadwal Mulai</Label>
                                <Input type="date" value={activityForm.data.scheduled_start} onChange={e => activityForm.setData('scheduled_start', e.target.value)} />
                            </div>
                            <div>
                                <Label>Alokasi Anggaran (Rp)</Label>
                                <Input type="number" value={activityForm.data.budget_allocation} onChange={e => activityForm.setData('budget_allocation', e.target.value)} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={activityForm.processing}>Simpan Kegiatan</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={openReport} onOpenChange={setOpenReport}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Kirim Laporan Realisasi Kegiatan</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitReport} className="space-y-3">
                        {program.activities.length > 0 && (
                            <div>
                                <Label>Kegiatan Terkait</Label>
                                <Select
                                    value={reportForm.data.program_activity_id || '__none__'}
                                    onValueChange={(v: string | null) => reportForm.setData('program_activity_id', !v || v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Pilih kegiatan" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">— Umum Program —</SelectItem>
                                        {program.activities.map(a => <SelectItem key={a.id} value={a.id}>{a.title}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div>
                            <Label>Judul Laporan *</Label>
                            <Input value={reportForm.data.title} onChange={e => reportForm.setData('title', e.target.value)} required />
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            <div>
                                <Label>Tanggal *</Label>
                                <Input type="date" value={reportForm.data.report_date} onChange={e => reportForm.setData('report_date', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Capaian (%) *</Label>
                                <Input type="number" min={0} max={100} value={reportForm.data.achieved_percent} onChange={e => reportForm.setData('achieved_percent', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Realisasi (Rp)</Label>
                                <Input type="number" value={reportForm.data.realized_budget} onChange={e => reportForm.setData('realized_budget', e.target.value)} />
                            </div>
                        </div>
                        <div>
                            <Label>Ringkasan Pelaksanaan *</Label>
                            <Input value={reportForm.data.summary} onChange={e => reportForm.setData('summary', e.target.value)} required />
                        </div>
                        <div>
                            <Label>Kaitkan ke Arsip Digital (Opsional)</Label>
                            <Select
                                value={reportForm.data.evidence_archive_id || '__none__'}
                                onValueChange={(v: string | null) => reportForm.setData('evidence_archive_id', !v || v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="— Pilih dokumen arsip —" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">— Tidak dikaitkan —</SelectItem>
                                    {archives.map(ar => (
                                        <SelectItem key={ar.id} value={ar.id}>{ar.archive_number} — {ar.title}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Atau Unggah Berkas Bukti Fisik</Label>
                            <Input type="file" onChange={e => reportForm.setData('evidence_file', e.target.files?.[0] ?? null)} />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={reportForm.processing}>Kirim Laporan</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
