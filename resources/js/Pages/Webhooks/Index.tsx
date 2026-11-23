import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Webhook as WebhookIcon, Plus, Power, RefreshCw, Trash2, Send, X, Eye, RotateCcw, ShieldAlert } from 'lucide-react';
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
    description?: string | null;
    is_active: boolean;
    include_pii?: boolean;
    max_attempts?: number;
    last_triggered_at: string | null;
    last_status: number | null;
}

interface Delivery {
    id: string;
    event_id?: string | null;
    event: string;
    payload: unknown;
    status_code: number | null;
    response_headers?: Record<string, string> | null;
    delivered: boolean;
    attempt?: number;
    next_retry_at?: string | null;
    delivered_at?: string | null;
    error: string | null;
    created_at: string;
    subscription?: { id: string; event: string; target_url: string } | null;
}

export default function WebhooksIndex({
    subscriptions,
    deliveries,
    events,
    clients,
    outboxStats = { pending: 0, published: 0, failed: 0 },
    filters = { event: '', status: '' },
}: {
    subscriptions: Sub[];
    deliveries: Delivery[];
    events: Record<string, string>;
    clients: { id: string; name: string }[];
    outboxStats?: { pending: number; published: number; failed: number };
    filters?: { event: string; status: string };
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [open, setOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<Sub | null>(null);
    const [selectedDelivery, setSelectedDelivery] = useState<Delivery | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        api_client_id: '',
        event: 'student.created',
        target_url: '',
        description: '',
        include_pii: false,
        max_attempts: 5,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/webhooks', {
            preserveScroll: true,
            onSuccess: () => { setOpen(false); reset(); },
            onError: () => toast.error('Gagal mendaftarkan webhook.'),
        });
    }

    function applyFilter(next: { event?: string; status?: string }) {
        const merged = {
            event: next.event !== undefined ? next.event : filters.event,
            status: next.status !== undefined ? next.status : filters.status,
        };
        router.get('/webhooks', {
            ...(merged.event ? { event: merged.event } : {}),
            ...(merged.status ? { status: merged.status } : {}),
        }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Webhooks & Transactional Outbox" />
            <div className="flex items-center gap-3 mb-6">
                <div className="rounded-xl p-2.5 bg-orange-500/10">
                    <WebhookIcon className="w-5 h-5 text-orange-500" />
                </div>
                <div className="flex-1">
                    <h1 className="text-2xl font-bold">Webhooks & Transactional Outbox</h1>
                    <p className="text-sm text-muted-foreground mt-0.5">
                        Event siswa & GTK dicatat secara atomik ke <code className="font-mono">outbox_events</code> lalu dikirim ke satelit (SIPSMAD, PTSP, E-Surat) dengan retry eksponensial & signature HMAC v2.
                    </p>
                </div>
                <Button onClick={() => setOpen(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Daftarkan URL
                </Button>
            </div>

            <div className="grid grid-cols-3 gap-4 mb-6">
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Outbox Pending</div>
                        <div className="text-2xl font-bold text-amber-600 mt-1">{outboxStats.pending}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Outbox Published</div>
                        <div className="text-2xl font-bold text-emerald-600 mt-1">{outboxStats.published}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <div className="text-xs text-muted-foreground">Outbox Dead-Letter (Failed)</div>
                        <div className="text-2xl font-bold text-rose-600 mt-1">{outboxStats.failed}</div>
                    </CardContent>
                </Card>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm font-mono break-all">{flash.success}</CardContent>
                </Card>
            )}

            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Langganan Event ({subscriptions.length})</CardTitle>
                    <CardDescription>
                        Header tiap POST: <code className="font-mono">X-Webhook-Id</code>, <code className="font-mono">X-Webhook-Event</code>, <code className="font-mono">X-Webhook-Timestamp</code>, <code className="font-mono">X-Webhook-Signature</code> (v1), dan <code className="font-mono">X-Webhook-Signature-V2</code> (HMAC-SHA256 atas <code className="font-mono">timestamp.body</code>).
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Event</th>
                                    <th className="px-4 py-2.5 font-medium">Target URL</th>
                                    <th className="px-4 py-2.5 font-medium">Kebijakan PII & Retry</th>
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
                                            {s.description && (
                                                <p className="text-xs text-muted-foreground mt-0.5">{s.description}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs max-w-[260px] truncate" title={s.target_url}>
                                            {s.target_url}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <div className="flex items-center gap-1.5">
                                                <Badge variant="outline" className="text-[10px]">
                                                    {s.include_pii ? 'PII: Diizinkan' : 'PII: Disensor'}
                                                </Badge>
                                                <Badge variant="secondary" className="text-[10px]">
                                                    Maks {s.max_attempts ?? 5}x
                                                </Badge>
                                            </div>
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
                                        <td colSpan={6} className="px-4 py-10 text-center text-sm text-muted-foreground">
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
                <CardHeader className="pb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <CardTitle className="text-sm">Log Pengiriman (50 terakhir)</CardTitle>
                        <CardDescription className="text-xs">
                            Deduplikasi aktif berdasarkan <code className="font-mono">(subscription_id, event_id)</code>.
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        <select
                            className="h-8 rounded-md border px-2 text-xs bg-background"
                            value={filters.event}
                            onChange={e => applyFilter({ event: e.target.value })}
                        >
                            <option value="">Semua Event</option>
                            {Object.keys(events).map(ev => (
                                <option key={ev} value={ev}>{ev}</option>
                            ))}
                        </select>
                        <select
                            className="h-8 rounded-md border px-2 text-xs bg-background"
                            value={filters.status}
                            onChange={e => applyFilter({ status: e.target.value })}
                        >
                            <option value="">Semua Status</option>
                            <option value="delivered">Terkirim</option>
                            <option value="failed">Gagal</option>
                        </select>
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Waktu</th>
                                    <th className="px-4 py-2.5 font-medium">Event & Event ID</th>
                                    <th className="px-4 py-2.5 font-medium">Target</th>
                                    <th className="px-4 py-2.5 font-medium">Hasil & Attempt</th>
                                    <th className="px-4 py-2.5 font-medium">Error</th>
                                    <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deliveries.map(d => (
                                    <tr key={d.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-2.5 text-xs whitespace-nowrap">
                                            {new Date(d.created_at).toLocaleString('id-ID')}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <code className="font-mono text-xs font-semibold block">{d.event}</code>
                                            {d.event_id && (
                                                <span className="font-mono text-[10px] text-muted-foreground">
                                                    ID: {d.event_id.slice(0, 8)}...
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 font-mono text-xs max-w-[220px] truncate" title={d.subscription?.target_url}>
                                            {d.subscription?.target_url ?? '—'}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <div className="flex items-center gap-1.5">
                                                <Badge className={cn(d.delivered
                                                    ? 'bg-emerald-500/15 text-emerald-500 border-emerald-500/20'
                                                    : 'bg-rose-500/15 text-rose-500 border-rose-500/20')}>
                                                    {d.delivered ? `HTTP ${d.status_code}` : 'Gagal'}
                                                </Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    #{d.attempt ?? 1}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5 text-xs text-muted-foreground max-w-[200px] truncate" title={d.error ?? ''}>
                                            {d.error ?? '—'}
                                        </td>
                                        <td className="px-4 py-2.5 text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setSelectedDelivery(d)}
                                                    title="Lihat Payload"
                                                >
                                                    <Eye className="w-4 h-4" />
                                                </Button>
                                                {!d.delivered && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => router.post(`/webhooks/deliveries/${d.id}/retry`, {}, { preserveScroll: true })}
                                                        title="Replay / Kirim Ulang"
                                                    >
                                                        <RotateCcw className="w-4 h-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {deliveries.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-10 text-center text-sm text-muted-foreground">
                                            Belum ada pengiriman. Gunakan tombol kirim (ping uji) atau tunggu event siswa/GTK.
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
                    <CardTitle className="text-sm">Format Payload & Verifikasi Signature v2 (untuk tim satelit)</CardTitle>
                </CardHeader>
                <CardContent>
                    <pre className="text-xs font-mono bg-muted p-3 rounded-lg overflow-x-auto">{`{
  "event": "student.updated | staff.updated | staff.position.assigned | ...",
  "event_id": "9d7a1c20-...",
  "occurred_at": "2026-11-23T10:00:00Z",
  "version": 1,
  "data": { "id": "uuid", "name": "...", "status": "active" }
}
// Verifikasi V2 (Direkomendasikan):
// "v2=" + HMAC-SHA256(X-Webhook-Timestamp + "." + raw_body, secret) == X-Webhook-Signature-V2
// Deduplikasi konsumen: simpan X-Webhook-Id (event_id) yang sudah diproses.`}</pre>
                </CardContent>
            </Card>

            {/* Dialog tambah langganan */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Daftarkan Webhook</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Event *</Label>
                            <Select value={data.event} onValueChange={(v: string | null) => v && setData('event', v)}>
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
                            {errors.event && <p className="text-xs text-destructive">{errors.event}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Target URL (HTTPS) *</Label>
                            <Input
                                type="url"
                                value={data.target_url}
                                onChange={e => setData('target_url', e.target.value)}
                                placeholder="https://sipsmad.mtsn1pandeglang.sch.id/api/webhooks/pintu"
                                required
                            />
                            {errors.target_url && <p className="text-xs text-destructive">{errors.target_url}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Deskripsi / Keterangan</Label>
                            <Input
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                placeholder="Sinkronisasi kartu RFID SIPSMAD"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Kaitkan ke API Client (opsional)</Label>
                            <Select value={data.api_client_id || '__none__'} onValueChange={(v: string | null) => setData('api_client_id', !v || v === '__none__' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="— Tidak dikaitkan —" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">— Tidak dikaitkan —</SelectItem>
                                    {clients.map(c => <SelectItem key={c.id} value={c.id}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <label className="flex items-start gap-2.5 text-xs rounded-lg border p-3 cursor-pointer hover:bg-muted/40">
                            <input
                                type="checkbox"
                                checked={data.include_pii}
                                onChange={e => setData('include_pii', e.target.checked)}
                                className="mt-0.5"
                            />
                            <span>
                                <span className="font-semibold flex items-center gap-1">
                                    <ShieldAlert className="w-3.5 h-3.5 text-amber-600" />
                                    Sertakan Atribut PII (NIK / KK)
                                </span>
                                <span className="text-muted-foreground block mt-0.5">
                                    Secara default dimatikan demi pelindungan data pribadi. Aktifkan hanya bila aplikasi satelit membutuhkan NIK.
                                </span>
                            </span>
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                <X className="w-4 h-4 mr-1.5" /> Batal
                            </Button>
                            <Button type="submit" disabled={processing}>
                                <Plus className="w-4 h-4 mr-1.5" /> Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog detail payload delivery */}
            <Dialog open={!!selectedDelivery} onOpenChange={() => setSelectedDelivery(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Detail Payload Pengiriman</DialogTitle>
                    </DialogHeader>
                    {selectedDelivery && (
                        <div className="space-y-3 text-xs">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Event ID:</span>
                                <code className="font-mono">{selectedDelivery.event_id ?? '—'}</code>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Percobaan (Attempt):</span>
                                <span className="font-semibold">#{selectedDelivery.attempt ?? 1}</span>
                            </div>
                            <div>
                                <div className="font-medium mb-1">Payload JSON:</div>
                                <pre className="font-mono bg-muted p-3 rounded-lg overflow-x-auto max-h-64">
                                    {JSON.stringify(selectedDelivery.payload, null, 2)}
                                </pre>
                            </div>
                            {selectedDelivery.response_headers && (
                                <div>
                                    <div className="font-medium mb-1">Response Headers (Redacted):</div>
                                    <pre className="font-mono bg-muted p-2.5 rounded-lg overflow-x-auto max-h-36">
                                        {JSON.stringify(selectedDelivery.response_headers, null, 2)}
                                    </pre>
                                </div>
                            )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!confirmDelete} onOpenChange={() => setConfirmDelete(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus Webhook?</AlertDialogTitle>
                        <AlertDialogDescription>
                            URL <code className="font-mono">{confirmDelete?.target_url}</code> tidak akan menerima event <code className="font-mono">{confirmDelete?.event}</code> lagi.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => {
                            if (!confirmDelete) return;
                            router.delete(`/webhooks/${confirmDelete.id}`, {
                                preserveScroll: true,
                                onSuccess: () => toast.success('Webhook dihapus.'),
                            });
                            setConfirmDelete(null);
                        }}>Hapus</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
