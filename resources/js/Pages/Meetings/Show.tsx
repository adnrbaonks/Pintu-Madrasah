import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { ArrowLeft, Users, FileText, CheckCircle2, Clock, Check, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';

interface ParticipantItem {
    id: string;
    role_in_meeting: string;
    attendance_status: string;
    attended_at?: string | null;
    absence_reason?: string | null;
    user?: { id: string; name: string; role: string } | null;
}

interface ActionItem {
    id: string;
    title: string;
    description?: string | null;
    due_date?: string | null;
    status: string;
    resolution_notes?: string | null;
    assignee?: { id: string; name: string } | null;
}

interface MeetingDetail {
    id: string;
    meeting_number: string;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    location: string;
    agenda?: string | null;
    status: string;
    leader?: { id: string; name: string } | null;
    notetaker?: { id: string; name: string } | null;
    participants: ParticipantItem[];
    minutes?: {
        id: string;
        discussion_summary: string;
        decisions: string;
        approved_at?: string | null;
        approver?: { id: string; name: string } | null;
        archive?: { id: string; archive_number: string; title: string } | null;
    } | null;
    actions: ActionItem[];
}

export default function MeetingShow({
    meeting,
    users,
    archives,
}: {
    meeting: MeetingDetail;
    users: { id: string; name: string; role: string }[];
    archives: { id: string; archive_number: string; title: string }[];
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openMinutes, setOpenMinutes] = useState(false);
    const [openAction, setOpenAction] = useState(false);

    const minutesForm = useForm({
        discussion_summary: meeting.minutes?.discussion_summary ?? '',
        decisions: meeting.minutes?.decisions ?? '',
        archive_id: meeting.minutes?.archive?.id ?? '',
    });

    const actionForm = useForm({
        title: '',
        description: '',
        assigned_to_user_id: users[0]?.id ?? '',
        due_date: new Date().toISOString().slice(0, 10),
    });

    function submitMinutes(e: React.FormEvent) {
        e.preventDefault();
        minutesForm.post(`/rapat-dinas/${meeting.id}/minutes`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpenMinutes(false);
                toast.success('Notulen rapat tersimpan.');
            },
        });
    }

    function approveMinutes() {
        if (!confirm('Sahkan notulen ini dan tandai rapat selesai?')) return;
        router.post(`/rapat-dinas/${meeting.id}/minutes/approve`, {}, {
            preserveScroll: true,
            onSuccess: () => toast.success('Notulen disahkan oleh pimpinan rapat.'),
        });
    }

    function submitAction(e: React.FormEvent) {
        e.preventDefault();
        actionForm.post(`/rapat-dinas/${meeting.id}/actions`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpenAction(false);
                actionForm.reset();
                toast.success('Tindak lanjut ditambahkan.');
            },
        });
    }

    function toggleAttendance(userId: string, currentStatus: string) {
        const nextStatus = currentStatus === 'present' ? 'absent' : 'present';
        router.post(`/rapat-dinas/${meeting.id}/attendance`, {
            user_id: userId,
            attendance_status: nextStatus,
        }, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title={`Rapat ${meeting.meeting_number}`} />

            <div className="mb-4">
                <Link href="/rapat-dinas" className="inline-flex items-center text-xs text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="w-3.5 h-3.5 mr-1" /> Kembali ke Daftar Rapat Dinas
                </Link>
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div>
                    <div className="flex items-center gap-2">
                        <code className="font-mono text-xs font-semibold text-violet-600">{meeting.meeting_number}</code>
                        <Badge variant="outline" className="uppercase">{meeting.status}</Badge>
                        <Badge variant="secondary" className="uppercase text-[10px]">{meeting.meeting_type}</Badge>
                    </div>
                    <h1 className="text-xl font-bold mt-1">{meeting.title}</h1>
                    <p className="text-xs text-muted-foreground">
                        {new Date(meeting.scheduled_at).toLocaleString('id-ID')} • {meeting.location}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={() => setOpenMinutes(true)}>
                        <FileText className="w-3.5 h-3.5 mr-1" /> Tulis/Edit Notulen
                    </Button>
                    {meeting.minutes && !meeting.minutes.approved_at && (
                        <Button size="sm" onClick={approveMinutes}>
                            <CheckCircle2 className="w-3.5 h-3.5 mr-1" /> Sahkan Notulen
                        </Button>
                    )}
                    <Button variant="outline" size="sm" onClick={() => setOpenAction(true)}>
                        <Plus className="w-3.5 h-3.5 mr-1" /> Tambah Action Item
                    </Button>
                </div>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    {/* Notulen Rapat */}
                    <Card>
                        <CardHeader className="pb-3 flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="text-sm">Notulen & Keputusan Rapat</CardTitle>
                                {meeting.minutes?.approved_at && (
                                    <CardDescription className="text-xs text-emerald-600 flex items-center gap-1 mt-0.5">
                                        <CheckCircle2 className="w-3 h-3" /> Disahkan oleh {meeting.minutes.approver?.name ?? 'Pimpinan'} ({new Date(meeting.minutes.approved_at).toLocaleString('id-ID')})
                                    </CardDescription>
                                )}
                            </div>
                            {meeting.minutes?.archive && (
                                <Link href={`/arsip-digital/${meeting.minutes.archive.id}`} className="text-xs font-mono underline text-indigo-600">
                                    Arsip: {meeting.minutes.archive.archive_number}
                                </Link>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            {meeting.minutes ? (
                                <>
                                    <div>
                                        <div className="text-xs font-semibold text-muted-foreground uppercase">Ringkasan Pembahasan:</div>
                                        <div className="mt-1 whitespace-pre-line text-xs bg-muted/30 p-3 rounded-md">{meeting.minutes.discussion_summary}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs font-semibold text-muted-foreground uppercase">Keputusan / Kesepakatan:</div>
                                        <div className="mt-1 whitespace-pre-line text-xs bg-emerald-500/5 border border-emerald-500/20 p-3 rounded-md font-medium">{meeting.minutes.decisions}</div>
                                    </div>
                                </>
                            ) : (
                                <div className="text-center py-8 text-xs text-muted-foreground">
                                    Belum ada notulen yang dicatat untuk rapat ini.
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Action Items / Tindak Lanjut */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm">Tindak Lanjut & Penugasan ({meeting.actions.length})</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-y bg-muted/50 text-left text-muted-foreground">
                                        <th className="px-4 py-2 font-medium">Tugas / Action Item</th>
                                        <th className="px-4 py-2 font-medium">Penanggung Jawab</th>
                                        <th className="px-4 py-2 font-medium">Jatuh Tempo</th>
                                        <th className="px-4 py-2 font-medium">Status</th>
                                        <th className="px-4 py-2 font-medium text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {meeting.actions.map(act => (
                                        <tr key={act.id} className="border-b last:border-0">
                                            <td className="px-4 py-2.5">
                                                <div className="font-medium text-foreground">{act.title}</div>
                                                {act.description && <div className="text-muted-foreground mt-0.5">{act.description}</div>}
                                            </td>
                                            <td className="px-4 py-2.5">{act.assignee?.name ?? '—'}</td>
                                            <td className="px-4 py-2.5 whitespace-nowrap">{act.due_date ?? '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <Badge variant={act.status === 'completed' ? 'secondary' : 'outline'} className="uppercase text-[10px]">
                                                    {act.status}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                {act.status !== 'completed' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => router.post(`/rapat-dinas/actions/${act.id}`, { status: 'completed' })}
                                                    >
                                                        <Check className="w-3.5 h-3.5 mr-1" /> Selesai
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {meeting.actions.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">
                                                Belum ada tindak lanjut pasca rapat.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    {/* Presensi Kehadiran Peserta */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm flex items-center gap-1.5">
                                <Users className="w-4 h-4 text-violet-600" />
                                Daftar Hadir Peserta ({meeting.participants.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y text-xs">
                                {meeting.participants.map(p => (
                                    <div key={p.id} className="p-3 flex items-center justify-between">
                                        <div>
                                            <div className="font-medium">{p.user?.name ?? '—'}</div>
                                            <div className="text-muted-foreground capitalize">
                                                {p.role_in_meeting} • {p.user?.role}
                                            </div>
                                        </div>
                                        <Button
                                            variant={p.attendance_status === 'present' ? 'default' : 'outline'}
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => p.user && toggleAttendance(p.user.id, p.attendance_status)}
                                        >
                                            {p.attendance_status === 'present' ? 'Hadir' : 'Absen'}
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Dialog Notulen */}
            <Dialog open={openMinutes} onOpenChange={setOpenMinutes}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Tulis Notulen & Keputusan Rapat</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitMinutes} className="space-y-3">
                        <div>
                            <Label>Ringkasan Pembahasan *</Label>
                            <textarea
                                className="w-full rounded-md border p-2 text-xs h-32 bg-background"
                                value={minutesForm.data.discussion_summary}
                                onChange={e => minutesForm.setData('discussion_summary', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label>Keputusan & Kesepakatan *</Label>
                            <textarea
                                className="w-full rounded-md border p-2 text-xs h-24 bg-background"
                                value={minutesForm.data.decisions}
                                onChange={e => minutesForm.setData('decisions', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label>Kaitkan ke Dokumen Arsip Digital</Label>
                            <Select
                                value={minutesForm.data.archive_id || '__none__'}
                                onValueChange={(v: string | null) => minutesForm.setData('archive_id', !v || v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="— Pilih Arsip Digital —" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">— Tidak dikaitkan —</SelectItem>
                                    {archives.map(a => <SelectItem key={a.id} value={a.id}>{a.archive_number} — {a.title}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={minutesForm.processing}>Simpan Notulen</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog Action Item */}
            <Dialog open={openAction} onOpenChange={setOpenAction}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Tambah Tindak Lanjut Rapat</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitAction} className="space-y-3">
                        <div>
                            <Label>Uraian Tugas / Action Item *</Label>
                            <Input value={actionForm.data.title} onChange={e => actionForm.setData('title', e.target.value)} required />
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label>Penanggung Jawab</Label>
                                <Select
                                    value={actionForm.data.assigned_to_user_id}
                                    onValueChange={(v: string | null) => v && actionForm.setData('assigned_to_user_id', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {users.map(u => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Batas Waktu (Due Date)</Label>
                                <Input type="date" value={actionForm.data.due_date} onChange={e => actionForm.setData('due_date', e.target.value)} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={actionForm.processing}>Simpan Tindak Lanjut</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
