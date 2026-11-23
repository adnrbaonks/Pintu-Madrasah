import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Calendar as CalendarIcon, Plus, Trash2, Clock } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';

interface CalendarEventItem {
    id: string;
    title: string;
    description?: string | null;
    event_type: string;
    start_time: string;
    end_time?: string | null;
    is_all_day: boolean;
    color: string;
    meeting?: { id: string; meeting_number: string; title: string; status: string } | null;
}

export default function CalendarIndex({
    events,
    activeYear,
}: {
    events: CalendarEventItem[];
    activeYear?: { id: string; year: string; semester: string } | null;
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openCreate, setOpenCreate] = useState(false);

    const form = useForm({
        title: '',
        description: '',
        event_type: 'academic',
        start_time: new Date().toISOString().slice(0, 16),
        end_time: '',
        color: '#4f46e5',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/kalender', {
            onSuccess: () => {
                setOpenCreate(false);
                form.reset();
                toast.success('Agenda berhasil ditambahkan.');
            },
        });
    }

    return (
        <AppLayout>
            <Head title="Kalender & Agenda Madrasah" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl p-2.5 bg-blue-500/10">
                        <CalendarIcon className="w-5 h-5 text-blue-600" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">Kalender & Agenda Madrasah</h1>
                        <p className="text-sm text-muted-foreground">
                            Jadwal kegiatan akademik, rapat dinas, ujian madrasah, dan hari libur resmi terpadu
                            {activeYear ? ` (Tahun Ajaran ${activeYear.year} Semester ${activeYear.semester})` : ''}.
                        </p>
                    </div>
                </div>
                <Button onClick={() => setOpenCreate(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Tambah Agenda
                </Button>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="space-y-3">
                {events.map(ev => (
                    <Card key={ev.id} className="hover:bg-muted/30 transition-colors">
                        <CardContent className="p-4 flex items-center justify-between">
                            <div className="flex items-start gap-3">
                                <div
                                    className="w-3 h-12 rounded-full shrink-0"
                                    style={{ backgroundColor: ev.color || '#4f46e5' }}
                                />
                                <div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline" className="uppercase text-[10px]">{ev.event_type}</Badge>
                                        <span className="text-xs text-muted-foreground flex items-center gap-1">
                                            <Clock className="w-3 h-3" />
                                            {new Date(ev.start_time).toLocaleString('id-ID')}
                                        </span>
                                    </div>
                                    <h2 className="font-semibold text-sm mt-1">{ev.title}</h2>
                                    {ev.description && <p className="text-xs text-muted-foreground mt-0.5">{ev.description}</p>}
                                    {ev.meeting && (
                                        <div className="mt-1">
                                            <a href={`/rapat-dinas/${ev.meeting.id}`} className="text-xs font-mono text-violet-600 underline">
                                                Tautan Rapat: {ev.meeting.meeting_number}
                                            </a>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive"
                                onClick={() => router.delete(`/kalender/${ev.id}`, { preserveScroll: true })}
                            >
                                <Trash2 className="w-3.5 h-3.5" />
                            </Button>
                        </CardContent>
                    </Card>
                ))}
                {events.length === 0 && (
                    <Card>
                        <CardContent className="py-12 text-center text-sm text-muted-foreground">
                            Belum ada agenda kegiatan dalam kalender madrasah.
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Tambah Agenda Kalender</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-3">
                        <div>
                            <Label>Nama Agenda *</Label>
                            <Input value={form.data.title} onChange={e => form.setData('title', e.target.value)} required />
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label>Tipe Kegiatan</Label>
                                <Select value={form.data.event_type} onValueChange={(v: string | null) => v && form.setData('event_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="academic">Akademik / Pembelajaran</SelectItem>
                                        <SelectItem value="exam">Asesmen / Ujian</SelectItem>
                                        <SelectItem value="holiday">Libur Madrasah / Nasional</SelectItem>
                                        <SelectItem value="agenda">Kegiatan Umum</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Warna Penanda</Label>
                                <Input type="color" value={form.data.color} onChange={e => form.setData('color', e.target.value)} />
                            </div>
                        </div>
                        <div>
                            <Label>Waktu Mulai *</Label>
                            <Input type="datetime-local" value={form.data.start_time} onChange={e => form.setData('start_time', e.target.value)} required />
                        </div>
                        <div>
                            <Label>Deskripsi (Opsional)</Label>
                            <Input value={form.data.description} onChange={e => form.setData('description', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>Simpan Agenda</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
