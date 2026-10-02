import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { Award, Users, CheckCircle2, XCircle, AlertCircle, Search } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow
} from '@/components/ui/table';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface StaffItem {
    id: string;
    full_name_with_titles: string;
    nuptk: string | null;
    nip: string | null;
    npk: string | null;
    employment_status: string | null;
    functional_position: string | null;
    highest_education: string | null;
    tpg_status: boolean;
    civil_rank: string | null;
    gender: string | null;
}

interface Props {
    staff: StaffItem[];
    summary: {
        total: number;
        sudah_tpg: number;
        belum_tpg: number;
        pns: number;
        pppk: number;
        honorer: number;
    };
}

const empColor: Record<string, string> = {
    pns:     'bg-blue-500/15 text-blue-600 border-blue-500/20',
    p3k:     'bg-emerald-500/15 text-emerald-600 border-emerald-500/20',
    pppk:    'bg-emerald-500/15 text-emerald-600 border-emerald-500/20',
    honorer: 'bg-amber-500/15 text-amber-600 border-amber-500/20',
    gtt:     'bg-amber-500/15 text-amber-600 border-amber-500/20',
    ptt:     'bg-violet-500/15 text-violet-600 border-violet-500/20',
};

export default function TpgPage({ staff, summary }: Props) {
    const [localStaff, setLocalStaff] = useState(staff);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<'semua' | 'sudah' | 'belum'>('semua');
    const [saving, setSaving] = useState<string | null>(null);

    const filtered = localStaff.filter(s => {
        const matchSearch = !search ||
            s.full_name_with_titles.toLowerCase().includes(search.toLowerCase()) ||
            (s.nip && s.nip.includes(search)) ||
            (s.nuptk && s.nuptk.includes(search));
        const matchFilter = filter === 'semua' || (filter === 'sudah' ? s.tpg_status : !s.tpg_status);
        return matchSearch && matchFilter;
    });

    async function toggleTpg(staffId: string, current: boolean) {
        setSaving(staffId);
        try {
            const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const res = await fetch(`/gtk/${staffId}/tpg-status`, {
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' },
                body: JSON.stringify({ tpg_status: !current }),
            });
            if (!res.ok) throw new Error('Gagal');
            setLocalStaff(prev => prev.map(s => s.id === staffId ? { ...s, tpg_status: !current } : s));
            toast.success(!current ? 'Status TPG diaktifkan' : 'Status TPG dinonaktifkan');
        } catch {
            toast.error('Gagal menyimpan status TPG.');
        } finally {
            setSaving(null);
        }
    }

    const pctSertifikasi = summary.total > 0 ? Math.round((summary.sudah_tpg / summary.total) * 100) : 0;

    return (
        <AppLayout>
            <Head title="Validasi TPG GTK" />

            <div className="mb-6">
                <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                    <Award className="w-4 h-4" />
                    <span>GTK</span>
                </div>
                <h1 className="text-2xl font-bold">Validasi TPG</h1>
                <p className="text-sm text-muted-foreground mt-0.5">
                    Monitoring status sertifikasi dan Tunjangan Profesi Guru
                </p>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-5">
                {[
                    { label: 'Total GTK', value: summary.total, icon: Users, cls: 'text-blue-500 bg-blue-500/10' },
                    { label: 'Sudah Sertifikasi', value: summary.sudah_tpg, icon: CheckCircle2, cls: 'text-emerald-500 bg-emerald-500/10' },
                    { label: 'Belum Sertifikasi', value: summary.belum_tpg, icon: XCircle, cls: 'text-rose-500 bg-rose-500/10' },
                    { label: 'PNS', value: summary.pns, icon: AlertCircle, cls: 'text-sky-500 bg-sky-500/10' },
                    { label: 'PPPK / P3K', value: summary.pppk, icon: AlertCircle, cls: 'text-amber-500 bg-amber-500/10' },
                ].map(card => {
                    const Icon = card.icon;
                    const [tc, bg] = card.cls.split(' ');
                    return (
                        <Card key={card.label}>
                            <CardContent className="pt-4 pb-4">
                                <div className="flex items-center gap-3">
                                    <div className={`rounded-lg p-2 ${bg}`}><Icon className={`w-4 h-4 ${tc}`} /></div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">{card.label}</p>
                                        <p className="text-xl font-bold">{card.value}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            {/* Progress Bar */}
            <Card className="mb-5">
                <CardContent className="pt-4 pb-4">
                    <div className="flex items-center gap-4">
                        <div className="flex-1">
                            <div className="flex justify-between text-xs mb-1.5">
                                <span className="text-muted-foreground">Persentase Guru Bersertifikasi</span>
                                <span className="font-bold text-sm">{pctSertifikasi}%</span>
                            </div>
                            <div className="h-3 bg-muted rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-gradient-to-r from-emerald-500 to-teal-400 rounded-full transition-all duration-700"
                                    style={{ width: `${pctSertifikasi}%` }}
                                />
                            </div>
                        </div>
                        <div className="text-right shrink-0">
                            <p className="text-2xl font-bold text-emerald-500">{summary.sudah_tpg}<span className="text-sm text-muted-foreground font-normal">/{summary.total}</span></p>
                            <p className="text-xs text-muted-foreground">guru</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Filter & Search */}
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <Button size="sm" variant={filter === 'semua' ? 'default' : 'outline'} onClick={() => setFilter('semua')}>Semua</Button>
                            <Button size="sm" variant={filter === 'sudah' ? 'default' : 'outline'} onClick={() => setFilter('sudah')}>Sudah Sertifikasi</Button>
                            <Button size="sm" variant={filter === 'belum' ? 'default' : 'outline'} onClick={() => setFilter('belum')}>Belum Sertifikasi</Button>
                        </div>
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Cari nama, NIP, NUPTK..."
                                className="pl-8 w-64 h-8 text-sm"
                            />
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="min-w-[220px]">Nama GTK</TableHead>
                                    <TableHead>Status Kepeg.</TableHead>
                                    <TableHead>Jabatan Fungsional</TableHead>
                                    <TableHead>Pend. Tertinggi</TableHead>
                                    <TableHead>NUPTK</TableHead>
                                    <TableHead>Pangkat/Golongan</TableHead>
                                    <TableHead className="text-center">Status Sertifikasi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center py-10 text-muted-foreground">
                                            Tidak ada data ditemukan
                                        </TableCell>
                                    </TableRow>
                                ) : filtered.map(s => (
                                    <TableRow key={s.id}>
                                        <TableCell>
                                            <div className="font-medium text-sm">{s.full_name_with_titles}</div>
                                            {s.nip && <div className="text-xs text-muted-foreground font-mono">NIP: {s.nip}</div>}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className={cn('text-xs', empColor[s.employment_status ?? ''] ?? '')}>
                                                {(s.employment_status ?? '—').toUpperCase()}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-xs">{s.functional_position || '—'}</TableCell>
                                        <TableCell className="text-xs">{s.highest_education || '—'}</TableCell>
                                        <TableCell className="text-xs font-mono">{s.nuptk || '—'}</TableCell>
                                        <TableCell className="text-xs">{s.civil_rank || '—'}</TableCell>
                                        <TableCell className="text-center">
                                            <div className="flex items-center justify-center gap-2">
                                                <Switch
                                                    checked={s.tpg_status}
                                                    disabled={saving === s.id}
                                                    onCheckedChange={() => toggleTpg(s.id, s.tpg_status)}
                                                />
                                                {s.tpg_status
                                                    ? <Badge className="bg-emerald-500/15 text-emerald-600 border-emerald-500/20 text-xs">Sudah</Badge>
                                                    : <Badge variant="outline" className="text-muted-foreground text-xs">Belum</Badge>
                                                }
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
