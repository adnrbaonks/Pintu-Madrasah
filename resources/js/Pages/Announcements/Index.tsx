import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Megaphone, Plus, Pin, Calendar, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';

interface AnnouncementItem {
    id: string;
    title: string;
    content: string;
    target_scope: string;
    priority: string;
    is_pinned: boolean;
    publish_date: string;
    expires_at?: string | null;
    author?: { id: string; name: string } | null;
    archive?: { id: string; archive_number: string; title: string } | null;
}

export default function AnnouncementsIndex({
    announcements,
    canManage,
    archives,
}: {
    announcements: AnnouncementItem[];
    canManage: boolean;
    archives: { id: string; archive_number: string; title: string }[];
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openCreate, setOpenCreate] = useState(false);

    const form = useForm({
        title: '',
        content: '',
        target_scope: 'all',
        priority: 'normal',
        is_pinned: false,
        publish_date: new Date().toISOString().slice(0, 10),
        expires_at: '',
        archive_id: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/pengumuman', {
            onSuccess: () => {
                setOpenCreate(false);
                form.reset();
                toast.success('Pengumuman berhasil dipublikasikan.');
            },
        });
    }

    return (
        <AppLayout>
            <Head title="Papan Pengumuman Madrasah" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-3">
                    <div className="rounded-xl p-2.5 bg-sky-500/10">
                        <Megaphone className="w-5 h-5 text-sky-600" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">Papan Pengumuman Madrasah</h1>
                        <p className="text-sm text-muted-foreground">
                            Pemberitahuan resmi internal madrasah, surat edaran, dan informasi penting untuk seluruh GTK dan warga madrasah.
                        </p>
                    </div>
                </div>
                {canManage && (
                    <Button onClick={() => setOpenCreate(true)}>
                        <Plus className="w-4 h-4 mr-1.5" /> Buat Pengumuman
                    </Button>
                )}
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="space-y-4">
                {announcements.map(item => (
                    <Card key={item.id} className={item.is_pinned ? 'border-sky-500 bg-sky-500/5' : ''}>
                        <CardHeader className="py-3 px-4 flex flex-row items-start justify-between gap-2">
                            <div>
                                <div className="flex items-center gap-2">
                                    {item.is_pinned && (
                                        <Badge className="bg-sky-500 gap-1 text-[10px]">
                                            <Pin className="w-3 h-3" /> Pinned
                                        </Badge>
                                    )}
                                    <Badge variant="outline" className="uppercase text-[10px]">{item.priority}</Badge>
                                    <span className="text-xs text-muted-foreground flex items-center gap-1">
                                        <Calendar className="w-3 h-3" /> {item.publish_date}
                                    </span>
                                </div>
                                <CardTitle className="text-base mt-1.5">{item.title}</CardTitle>
                                <CardDescription className="text-xs">
                                    Diterbitkan oleh {item.author?.name ?? 'Admin'}
                                    {item.expires_at ? ` • Berlaku s.d. ${item.expires_at}` : ''}
                                </CardDescription>
                            </div>
                            {canManage && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive h-8"
                                    onClick={() => router.delete(`/pengumuman/${item.id}`, { preserveScroll: true })}
                                >
                                    <Trash2 className="w-3.5 h-3.5" />
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="px-4 pb-4 pt-0 text-sm whitespace-pre-line text-foreground/90">
                            {item.content}
                            {item.archive && (
                                <div className="mt-3 pt-2 border-t text-xs">
                                    <span className="text-muted-foreground">Lampiran Dokumen Resmi: </span>
                                    <a href={`/arsip-digital/${item.archive.id}`} className="font-mono text-indigo-600 underline font-medium">
                                        {item.archive.archive_number} — {item.archive.title}
                                    </a>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}
                {announcements.length === 0 && (
                    <Card>
                        <CardContent className="py-12 text-center text-sm text-muted-foreground">
                            Belum ada pengumuman aktif saat ini.
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Buat Pengumuman Baru</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-3">
                        <div>
                            <Label>Judul Pengumuman *</Label>
                            <Input value={form.data.title} onChange={e => form.setData('title', e.target.value)} required />
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label>Prioritas</Label>
                                <Select value={form.data.priority} onValueChange={(v: string | null) => v && form.setData('priority', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Rendah</SelectItem>
                                        <SelectItem value="normal">Normal</SelectItem>
                                        <SelectItem value="urgent">Mendesak / Penting</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Tanggal Terbit *</Label>
                                <Input type="date" value={form.data.publish_date} onChange={e => form.setData('publish_date', e.target.value)} required />
                            </div>
                        </div>
                        <div>
                            <Label>Isi Pengumuman *</Label>
                            <textarea
                                className="w-full rounded-md border p-2 text-xs h-32 bg-background"
                                value={form.data.content}
                                onChange={e => form.setData('content', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <Label>Lampiran Arsip Digital (Opsional)</Label>
                            <Select
                                value={form.data.archive_id || '__none__'}
                                onValueChange={(v: string | null) => form.setData('archive_id', !v || v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="— Pilih Dokumen Arsip —" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">— Tanpa Lampiran —</SelectItem>
                                    {archives.map(a => <SelectItem key={a.id} value={a.id}>{a.archive_number} — {a.title}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                id="pin"
                                checked={form.data.is_pinned}
                                onChange={e => form.setData('is_pinned', e.target.checked)}
                            />
                            <label htmlFor="pin" className="cursor-pointer">Sematkan di baris teratas (Pinned)</label>
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>Publikasikan</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
