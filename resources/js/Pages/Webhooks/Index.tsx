import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Webhook as WebhookIcon, Plus, Power, RefreshCw, Trash2, Send, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface Sub {
    id: string;
    api_client_id: string | null;
    api_client?: { id: string; name: string } | null;
    event: string;
    target_url: string;
    is_active: boolean;
    last_triggered_at: string | null;
    last_status: number | null;
}

interface Delivery {
    id: string;
    event: string;
    payload: unknown;
    status_code: number | null;
    delivered: boolean;
    error: string | null;
    created_at: string;
    subscription?: { id: string; event: string; target_url: string } | null;
}

export default function WebhooksIndex({
    subscriptions, deliveries, events, clients,
}: {
    subscriptions: Sub[];
    deliveries: Delivery[];
    events: Record<string, string>;
    clients: { id: string; name: string }[];
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [open, setOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<Sub | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        api_client_id: '',
        event: 'student.created',
        target_url: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/webhooks', {
            preserveScroll: true,
            onSuccess: () => { setOpen(false); reset(); },
            onError: () => toast.error('Gagal mendaftarkan webhook.'),
        });
    }

    return (
        <AppLayout>
            <Head title="Webhooks" />
            <div className="flex items-center gap-3 mb-6">
                <div className="rounded-xl p-2.5 bg-orange-500/10">
                    <WebhookIcon className="w-5 h-5 text-orange-500" />
                </div>
                <div className="flex-1">
                    <h1 className="text-2xl font-bold">Webhooks</h1>
                    <p className="text-sm text-muted-foreground mt-0.5">
                        Event siswa diteruskan otomatis ke satelit (SIPSMAD, WA Gateway) via antrean <code className="font-mono">webhooks</code>
                    </p>
                </div>
                <Button onClick={() => setOpen(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Daftarkan URL
                </Button>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm font-mono break-all">{flash.success}</CardContent>
                </Card>
            )}

            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Langganan Event ({subscriptions.length})</CardTitle>
                    <CardDescription>Header tiap POST: X-Webhook-Event + X-Webhook-Signature (HMAC-SHA256 body, secret per langganan).</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Event</th>
                                    <th className="px-4 py-2.5 font-medium">Target URL</th>
                                    <th className="px-4 py-2.5 font-medium">Klien</th>
                                    <th className="px-4 py-2.5 font-medium">Terakhir</th>
                                    <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {subscriptions.map(s => (
                                    <tr key={s.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs font-semibold">{s.event}</code>
                                            <Badge className={cn('ml-2', s.is_active
                                                ? 'bg-emerald-500/15 text-emerald-500 border-emerald-500/20'
                                                : 'bg-slate-500/15 text-slate-500 border-slate-500/20')}>
                                                {s.is_active ? 'Aktif' : 'Mati'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs max-w-[280px] truncate" title={s.target_url}>
                                            {s.target_url}
                                        </td>
                                        <td className="px-4 py-3 text-xs">{s.api_client?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">
                                            {s.last_triggered_at
                                                ? `${new Date(s.last_triggered_at).toLocaleString('id-ID')}${s.last_status ? ` (HTTP ${s.last_status})` : ' (gagal konek)'}`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
                                                <Button variant="ghost" size="sm"
                                                    onClick={() => router.post(`/webhooks/${s.id}/test`, {}, { preserveScroll: true })}
                                                    title="Kirim ping uji">
                                                    <Send className="w-4 h-4" />
                                                </Button>
                                                <Button variant="ghost" size="sm"
                                                    onClick={() => router.post(`/webhooks/${s.id}/toggle-active`, {}, { preserveScroll: true })}
                                                    title="Aktif/nonaktif">
                                                    <Power className="w-4 h-4" />
                                                </Button>
                                                <Button variant="ghost" size="sm"
                                                    onClick={() => router.post(`/webhooks/${s.id}/regenerate-secret`, {}, { preserveScroll: true })}
                                                    title="Generate ulang secret">
                                                    <RefreshCw className="w-4 h-4" />
                                                </Button>
                                                <Button variant="ghost" size="sm"
                                                    onClick={() => setConfirmDelete(s)}
                                                    title="Hapus"
                                                    className="text-destructive hover:text-destructive">
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {subscriptions.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-10 text-center text-sm text-muted-foreground">
                                            Belum ada langganan. Daftarkan URL satelit untuk event pertama.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Log Pengiriman (50 terakhir)</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Waktu</th>
                                    <th className="px-4 py-2.5 font-medium">Event</th>
                                    <th className="px-4 py-2.5 font-medium">Target</th>
                                    <th className="px-4 py-2.5 font-medium">Hasil</th>
                                    <th className="px-4 py-2.5 font-medium">Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deliveries.map(d => (
                                    <tr key={d.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-2.5 text-xs whitespace-nowrap">
                                            {new Date(d.created_at).toLocaleString('id-ID')}
                                        </td>
                                        <td className="px-4 py-2.5"><code className="font-mono text-xs">{d.event}</code></td>
                                        <td className="px-4 py-2.5 font-mono text-xs max-w-[240px] truncate" title={d.subscription?.target_url}>
                                            {d.subscription?.target_url ?? '—'}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <Badge className={cn(d.delivered
                                                ? 'bg-emerald-500/15 text-emerald-500 border-emerald-500/20'
                                                : 'bg-rose-500/15 text-rose-500 border-rose-500/20')}>
                                                {d.delivered ? `HTTP ${d.status_code}` : 'Gagal'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-2.5 text-xs text-muted-foreground max-w-[240px] truncate" title={d.error ?? ''}>
                                            {d.error ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                                {deliveries.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-10 text-center text-sm text-muted-foreground">
                                            Belum ada pengiriman. Gunakan tombol kirim (ping uji) atau tunggu event siswa.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Format Payload (untuk tim satelit)</CardTitle>
                </CardHeader>
                <CardContent>
                    <pre className="text-xs font-mono bg-muted p-3 rounded-lg overflow-x-auto">{`{
  "event": "student.created | student.updated | student.mutated | student.promoted",
  "occurred_at": "2026-10-03T10:00:00+07:00",
  "data": { "id": "uuid", "nisn": "...", "name": "...", "status": "active", ... }
}
// Verifikasi: HMAC-SHA256(raw body, secret) == X-Webhook-Signature (format "sha256=...")`}</pre>
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Daftarkan Webhook</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Event *</Label>
                            <Select value={data.event} onValueChange={(v: string) => setData('event', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {Object.entries(events).map(([ev, desc]) => (
                                        <SelectItem key={ev} value={ev}>
                                            <span className="font-mono text-xs">{ev}</span>
                                            <span className="block text-xs text-muted-foreground">{desc}</span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Target URL *</Label>
                            <Input
                                value={data.target_url}
                                onChange={e => setData('target_url', e.target.value)}
                                placeholder="https://sipsmad.madrasah.id/webhooks/pintu"
                                required
                            />
                            {errors.target_url && <p className="text-xs text-destructive">{errors.target_url}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Klien API (opsional)</Label>
                            <Select
                                value={data.api_client_id || 'none'}
                                onValueChange={(v: string) => setData('api_client_id', v === 'none' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="Tanpa klien tertentu" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Tanpa klien tertentu</SelectItem>
                                    {clients.map(c => (
                                        <SelectItem key={c.id} value={c.id}>{c.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                <X className="w-4 h-4 mr-1.5" /> Batal
                            </Button>
                            <Button type="submit" disabled={processing}>
                                <WebhookIcon className="w-4 h-4 mr-1.5" /> Daftarkan
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!confirmDelete} onOpenChange={() => setConfirmDelete(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus webhook?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Langganan {confirmDelete?.event} ke {confirmDelete?.target_url} berhenti menerima event.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => {
                            if (confirmDelete) router.delete(`/webhooks/${confirmDelete.id}`, { preserveScroll: true });
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
