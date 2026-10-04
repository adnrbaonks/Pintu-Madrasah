import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { HeartHandshake, Plus, Printer, Trash2, X, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface BkRecordItem {
    id: string;
    record_date: string;
    type: string;
    category: string;
    title: string;
    description?: string;
    points: number;
    follow_up?: string;
    status: string;
    student: { id: string; name: string; nisn: string; grade_level?: string };
    reporter?: { id: string; name: string } | null;
}

interface Rekap {
    student_id: string;
    name: string;
    nisn: string;
    grade_level: string;
    total_points: number;
    total_records: number;
}

const statusBadge = (s: string) =>
    s === 'closed'
        ? <Badge className="bg-emerald-500/15 text-emerald-500 border-emerald-500/20">Selesai</Badge>
        : s === 'followup'
            ? <Badge className="bg-amber-500/15 text-amber-500 border-amber-500/20">Tindak Lanjut</Badge>
            : <Badge className="bg-sky-500/15 text-sky-500 border-sky-500/20">Terbuka</Badge>;

export default function BkIndex({ records, rekap, filters, types, tahunAjaran, studentOptions }: {
    records: { data: BkRecordItem[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    rekap: Rekap[];
    filters: { type?: string; status?: string; q?: string };
    types: Record<string, string>;
    tahunAjaran: string;
    studentOptions: { id: string; name: string; nisn: string }[];
}) {
    const [open, setOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<BkRecordItem | null>(null);
    const [q, setQ] = useState(filters.q || '');

    const { data, setData, post, processing, errors, reset } = useForm({
        student_id: '',
        record_date: new Date().toISOString().split('T')[0],
        type: 'violation',
        category: '',
        title: '',
        description: '',
        points: 0 as number,
        follow_up: '',
        status: 'open',
    });

    function applyFilter(patch: Record<string, string>) {
        router.get('/bk', { type: filters.type || '', status: filters.status || '', q: q || '', ...patch }, { preserveState: true, replace: true });
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/bk', {
            preserveScroll: true,
            onSuccess: () => { setOpen(false); reset(); toast.success('Catatan BK tersimpan.'); },
            onError: () => toast.error('Gagal menyimpan. Periksa isian.'),
        });
    }

    function cycleStatus(r: BkRecordItem) {
        const next = r.status === 'open' ? 'followup' : r.status === 'followup' ? 'closed' : 'open';
        router.put(`/bk/${r.id}`, { status: next }, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="BK — Pembinaan & Kedisiplinan" />
            <div className="flex items-center gap-3 mb-6">
                <div className="rounded-xl p-2.5 bg-rose-500/10">
                    <HeartHandshake className="w-5 h-5 text-rose-500" />
                </div>
                <div className="flex-1">
                    <h1 className="text-2xl font-bold">BK — Karakter & Kedisiplinan</h1>
                    <p className="text-sm text-muted-foreground mt-0.5">Tahun ajaran {tahunAjaran} • Poin negatif = pelanggaran</p>
                </div>
                <Button onClick={() => setOpen(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Catat
                </Button>
            </div>

            <Tabs defaultValue="records">
                <TabsList>
                    <TabsTrigger value="records">Catatan ({records.total})</TabsTrigger>
                    <TabsTrigger value="rekap">Rekap Poin ({rekap.length})</TabsTrigger>
                </TabsList>

                <TabsContent value="records">
                    <Card>
                        <CardHeader className="pb-3">
                            <div className="flex flex-wrap gap-2">
                                <div className="flex items-center gap-2 flex-1 min-w-[200px]">
                                    <Search className="w-4 h-4 text-muted-foreground" />
                                    <Input
                                        value={q}
                                        onChange={e => setQ(e.target.value)}
                                        onKeyDown={e => e.key === 'Enter' && applyFilter({})}
                                        placeholder="Cari nama / NISN..."
                                        className="max-w-xs"
                                    />
                                </div>
                                <Select value={filters.type || 'all'} onValueChange={(v: string | null) => applyFilter({ type: v === 'all' ? '' : (v || '') })}>
                                    <SelectTrigger className="w-[200px]"><SelectValue placeholder="Semua jenis" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua jenis</SelectItem>
                                        {Object.entries(types).map(([k, v]) => (
                                            <SelectItem key={k} value={k}>{v}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Select value={filters.status || 'all'} onValueChange={(v: string | null) => applyFilter({ status: v === 'all' ? '' : (v || '') })}>
                                    <SelectTrigger className="w-[160px]"><SelectValue placeholder="Semua status" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua status</SelectItem>
                                        <SelectItem value="open">Terbuka</SelectItem>
                                        <SelectItem value="followup">Tindak Lanjut</SelectItem>
                                        <SelectItem value="closed">Selesai</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                            <th className="px-4 py-2.5 font-medium">Tanggal</th>
                                            <th className="px-4 py-2.5 font-medium">Siswa</th>
                                            <th className="px-4 py-2.5 font-medium">Jenis / Kategori</th>
                                            <th className="px-4 py-2.5 font-medium">Judul</th>
                                            <th className="px-4 py-2.5 font-medium">Poin</th>
                                            <th className="px-4 py-2.5 font-medium">Status</th>
                                            <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {records.data.map(r => (
                                            <tr key={r.id} className="border-b last:border-0 hover:bg-muted/30">
                                                <td className="px-4 py-3 text-xs whitespace-nowrap">{r.record_date?.split('T')[0]}</td>
                                                <td className="px-4 py-3">
                                                    <p className="font-semibold">{r.student.name}</p>
                                                    <p className="text-xs text-muted-foreground font-mono">{r.student.nisn}</p>
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    <p className="font-medium">{types[r.type] || r.type}</p>
                                                    <p className="text-muted-foreground">{r.category}</p>
                                                </td>
                                                <td className="px-4 py-3 max-w-[260px]">
                                                    <p className="font-medium truncate" title={r.title}>{r.title}</p>
                                                    {r.follow_up && <p className="text-xs text-muted-foreground truncate" title={r.follow_up}>TL: {r.follow_up}</p>}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={cn('font-mono font-bold', r.points < 0 ? 'text-rose-500' : r.points > 0 ? 'text-emerald-500' : 'text-muted-foreground')}>
                                                        {r.points > 0 ? `+${r.points}` : r.points}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <button onClick={() => cycleStatus(r)} title="Klik untuk ubah status">
                                                        {statusBadge(r.status)}
                                                    </button>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex justify-end gap-1">
                                                        <a href={`/bk/${r.id}/summon`} target="_blank" rel="noreferrer">
                                                            <Button variant="ghost" size="sm" title="Cetak surat pemanggilan">
                                                                <Printer className="w-4 h-4" />
                                                            </Button>
                                                        </a>
                                                        <Button variant="ghost" size="sm" onClick={() => setConfirmDelete(r)} className="text-destructive hover:text-destructive" title="Hapus">
                                                            <Trash2 className="w-4 h-4" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {records.data.length === 0 && (
                                            <tr><td colSpan={7} className="px-4 py-10 text-center text-sm text-muted-foreground">Belum ada catatan. Klik Catat untuk entri pertama.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="rekap">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm">Rekap Poin Kedisiplinan</CardTitle>
                            <CardDescription>Urut poin terendah (perlu pembinaan intensif) ke tertinggi.</CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                            <th className="px-4 py-2.5 font-medium">Siswa</th>
                                            <th className="px-4 py-2.5 font-medium">Kelas</th>
                                            <th className="px-4 py-2.5 font-medium">Total Poin</th>
                                            <th className="px-4 py-2.5 font-medium">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rekap.map(r => (
                                            <tr key={r.student_id} className="border-b last:border-0 hover:bg-muted/30">
                                                <td className="px-4 py-3">
                                                    <p className="font-semibold">{r.name}</p>
                                                    <p className="text-xs text-muted-foreground font-mono">{r.nisn}</p>
                                                </td>
                                                <td className="px-4 py-3">{r.grade_level}</td>
                                                <td className="px-4 py-3">
                                                    <span className={cn('font-mono font-bold text-base', r.total_points < 0 ? 'text-rose-500' : 'text-emerald-500')}>
                                                        {r.total_points}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">{r.total_records} catatan</td>
                                            </tr>
                                        ))}
                                        {rekap.length === 0 && (
                                            <tr><td colSpan={4} className="px-4 py-10 text-center text-sm text-muted-foreground">Belum ada rekap.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>Catatan BK Baru</DialogTitle></DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Siswa *</Label>
                            <Select value={data.student_id || 'none'} onValueChange={(v: string | null) => v && v !== 'none' && setData('student_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Pilih siswa..." /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">— Pilih —</SelectItem>
                                    {studentOptions.map(s => (
                                        <SelectItem key={s.id} value={s.id}>{s.name} ({s.nisn})</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Jenis *</Label>
                                <Select value={data.type} onValueChange={(v: string | null) => v && setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(types).map(([k, v]) => (
                                            <SelectItem key={k} value={k}>{v}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Tanggal *</Label>
                                <Input type="date" value={data.record_date} onChange={e => setData('record_date', e.target.value)} required />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Kategori *</Label>
                                <Input value={data.category} onChange={e => setData('category', e.target.value)} placeholder="Terlambat / P5 Mandiri / ..." required />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Poin (negatif = pelanggaran)</Label>
                                <Input type="number" value={data.points} onChange={e => setData('points', parseInt(e.target.value || '0', 10))} />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Judul *</Label>
                            <Input value={data.title} onChange={e => setData('title', e.target.value)} placeholder="Ringkasan kejadian" required />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Uraian</Label>
                            <Textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tindak Lanjut</Label>
                            <Input value={data.follow_up} onChange={e => setData('follow_up', e.target.value)} placeholder="Teguran / home visit / panggil ortu" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                <X className="w-4 h-4 mr-1.5" /> Batal
                            </Button>
                            <Button type="submit" disabled={processing}>Simpan</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!confirmDelete} onOpenChange={() => setConfirmDelete(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus catatan?</AlertDialogTitle>
                        <AlertDialogDescription>Catatan {confirmDelete?.title} dihapus permanen dan keluar dari rekap poin.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => {
                            if (confirmDelete) router.delete(`/bk/${confirmDelete.id}`, { preserveScroll: true });
                            setConfirmDelete(null);
                        }}>
                            Ya, hapus
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
