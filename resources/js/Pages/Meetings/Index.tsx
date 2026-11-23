import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Users2, Plus, Eye, Calendar, MapPin, Video } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';

interface MeetingItem {
    id: string;
    meeting_number: string;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    ended_at?: string | null;
    location: string;
    is_online: boolean;
    online_meeting_url?: string | null;
    status: string;
    participants_count: number;
    actions_count: number;
    leader?: { id: string; name: string } | null;
    notetaker?: { id: string; name: string } | null;
    organization_unit?: { id: string; name: string } | null;
}

export default function MeetingsIndex({
    meetings,
    units,
    users,
    stats,
}: {
    meetings: MeetingItem[];
    units: { id: string; code: string; name: string }[];
    users: { id: string; name: string; role: string }[];
    filters: { status: string; type: string };
    stats: { total: number; scheduled: number; completed: number };
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openCreate, setOpenCreate] = useState(false);

    const form = useForm({
        title: '',
        meeting_type: 'dinas',
        scheduled_at: new Date().toISOString().slice(0, 16),
        ended_at: '',
        location: 'Ruang Rapat Madrasah',
        is_online: false,
        online_meeting_url: '',
        organization_unit_id: '',
        leader_user_id: users[0]?.id ?? '',
        notetaker_user_id: users[0]?.id ?? '',
        agenda: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/rapat-dinas', {
            onSuccess: () => {
                setOpenCreate(false);
                form.reset();
                toast.success('Rapat dinas berhasil dijadwalkan.');
            },
        });
    }

    return (
        <AppLayout>
            <Head title="Rapat & Notulen Dinas Madrasah" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl p-2.5 bg-violet-500/10">
                        <Users2 className="w-5 h-5 text-violet-600" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">Rapat & Notulen Dinas Madrasah</h1>
                        <p className="text-sm text-muted-foreground">
                            Penjadwalan agenda rapat, presensi kehadiran, notulen keputusan rapat terintegrasi arsip, dan monitoring tindak lanjut.
                        </p>
                    </div>
                </div>
                <Button onClick={() => setOpenCreate(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Jadwalkan Rapat
                </Button>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Total Rapat</div>
                        <div className="text-2xl font-bold mt-1">{stats.total}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Terjadwal Mendatang</div>
                        <div className="text-2xl font-bold text-violet-600 mt-1">{stats.scheduled}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Selesai & Disahkan</div>
                        <div className="text-2xl font-bold text-emerald-600 mt-1">{stats.completed}</div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Daftar Agenda Rapat Dinas</CardTitle>
                    <CardDescription className="text-xs">
                        Setiap rapat secara otomatis disinkronkan ke Kalender Madrasah.
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Nomor & Judul Rapat</th>
                                    <th className="px-4 py-2.5 font-medium">Waktu & Tempat</th>
                                    <th className="px-4 py-2.5 font-medium">Pimpinan & Notulis</th>
                                    <th className="px-4 py-2.5 font-medium">Peserta & Tindak Lanjut</th>
                                    <th className="px-4 py-2.5 font-medium">Status</th>
                                    <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {meetings.map(m => (
                                    <tr key={m.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs font-semibold text-violet-600">{m.meeting_number}</code>
                                            <div className="font-medium mt-0.5">{m.title}</div>
                                            <Badge variant="outline" className="text-[10px] uppercase mt-1">{m.meeting_type}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="flex items-center gap-1 font-medium">
                                                <Calendar className="w-3.5 h-3.5 text-muted-foreground" />
                                                {new Date(m.scheduled_at).toLocaleString('id-ID')}
                                            </div>
                                            <div className="flex items-center gap-1 text-muted-foreground mt-0.5">
                                                {m.is_online ? <Video className="w-3.5 h-3.5" /> : <MapPin className="w-3.5 h-3.5" />}
                                                {m.location}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div>Pimpinan: <span className="font-medium">{m.leader?.name ?? '—'}</span></div>
                                            <div className="text-muted-foreground">Notulis: {m.notetaker?.name ?? '—'}</div>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div>{m.participants_count} Peserta Terdaftar</div>
                                            <div className="text-muted-foreground">{m.actions_count} Action Items</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant={m.status === 'completed' ? 'secondary' : 'outline'} className="uppercase text-[10px]">
                                                {m.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={`/rapat-dinas/${m.id}`}>
                                                <Button variant="outline" size="sm">
                                                    <Eye className="w-3.5 h-3.5 mr-1" /> Notulen & Absen
                                                </Button>
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {meetings.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-sm text-muted-foreground">
                                            Belum ada agenda rapat dinas.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Jadwalkan Rapat Dinas Baru</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-3.5">
                        <div className="space-y-1">
                            <Label>Judul / Topik Rapat *</Label>
                            <Input
                                value={form.data.title}
                                onChange={e => form.setData('title', e.target.value)}
                                placeholder="Contoh: Rapat Koordinasi Evaluasi Pembelajaran Tengah Semester"
                                required
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Tipe Rapat</Label>
                                <Select
                                    value={form.data.meeting_type}
                                    onValueChange={(v: string | null) => v && form.setData('meeting_type', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="dinas">Rapat Dinas Rutin</SelectItem>
                                        <SelectItem value="wali_murid">Rapat Wali Murid</SelectItem>
                                        <SelectItem value="komite">Rapat Komite Madrasah</SelectItem>
                                        <SelectItem value="mgmp">Pertemuan MGMP / Guru</SelectItem>
                                        <SelectItem value="evaluasi">Rapat Evaluasi Program</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Unit Penyelenggara</Label>
                                <Select
                                    value={form.data.organization_unit_id || '__none__'}
                                    onValueChange={(v: string | null) => form.setData('organization_unit_id', !v || v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Seluruh Madrasah" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">— Seluruh Madrasah —</SelectItem>
                                        {units.map(u => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Waktu Mulai *</Label>
                                <Input type="datetime-local" value={form.data.scheduled_at} onChange={e => form.setData('scheduled_at', e.target.value)} required />
                            </div>
                            <div className="space-y-1">
                                <Label>Tempat / Lokasi *</Label>
                                <Input value={form.data.location} onChange={e => form.setData('location', e.target.value)} required />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Pimpinan Rapat</Label>
                                <Select
                                    value={form.data.leader_user_id}
                                    onValueChange={(v: string | null) => v && form.setData('leader_user_id', v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Pilih Pimpinan" /></SelectTrigger>
                                    <SelectContent>
                                        {users.map(u => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Notulis</Label>
                                <Select
                                    value={form.data.notetaker_user_id}
                                    onValueChange={(v: string | null) => v && form.setData('notetaker_user_id', v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Pilih Notulis" /></SelectTrigger>
                                    <SelectContent>
                                        {users.map(u => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Agenda Pembahasan</Label>
                            <Input value={form.data.agenda} onChange={e => form.setData('agenda', e.target.value)} placeholder="Ringkasan poin agenda rapat..." />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpenCreate(false)}>Batal</Button>
                            <Button type="submit" disabled={form.processing}>Jadwalkan Rapat</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
