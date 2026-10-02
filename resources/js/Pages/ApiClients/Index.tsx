import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { KeyRound, Plus, Power, RefreshCw, Trash2, Eye, EyeOff, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface ApiClient {
    id: string;
    name: string;
    api_key: string;
    masked_key: string;
    scopes: string[];
    is_active: boolean;
    last_used_at: string | null;
    notes: string | null;
    created_at: string;
}

export default function ApiClientsIndex({
    clients, availableScopes,
}: {
    clients: ApiClient[];
    availableScopes: Record<string, string>;
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [open, setOpen] = useState(false);
    const [reveal, setReveal] = useState<Record<string, boolean>>({});
    const [confirm, setConfirm] = useState<{ type: 'delete' | 'regenerate'; client: ApiClient } | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        scopes: [] as string[],
        notes: '',
    });

    function toggleScope(scope: string) {
        setData('scopes', data.scopes.includes(scope)
            ? data.scopes.filter(s => s !== scope)
            : [...data.scopes, scope]);
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/api-clients', {
            preserveScroll: true,
            onSuccess: () => {
                const msg = (usePage().props as unknown as { flash: { success?: string } }).flash?.success;
                if (msg) toast.success(msg);
                setOpen(false);
                reset();
            },
            onError: () => toast.error('Gagal membuat API key. Periksa isian.'),
        });
    }

    function doAction() {
        if (!confirm) return;
        const { type, client } = confirm;
        if (type === 'delete') {
            router.delete(`/api-clients/${client.id}`, {
                preserveScroll: true,
                onSuccess: () => toast.success(`API Key ${client.name} dihapus.`),
            });
        } else {
            router.post(`/api-clients/${client.id}/regenerate`, {}, {
                preserveScroll: true,
                onSuccess: () => toast.success('Key baru dibuat — key lama mati.'),
            });
        }
        setConfirm(null);
    }

    return (
        <AppLayout>
            <Head title="API Clients" />
            <div className="flex items-center gap-3 mb-6">
                <div className="rounded-xl p-2.5 bg-violet-500/10">
                    <KeyRound className="w-5 h-5 text-violet-500" />
                </div>
                <div className="flex-1">
                    <h1 className="text-2xl font-bold">API Clients</h1>
                    <p className="text-sm text-muted-foreground mt-0.5">
                        Kelola kunci akses aplikasi satelit (SIPSMAD, PTSP, E-Surat) + dokumentasi di <code className="font-mono">/api/documentation</code>
                    </p>
                </div>
                <Button onClick={() => setOpen(true)}>
                    <Plus className="w-4 h-4 mr-1.5" /> Buat Key
                </Button>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm font-mono break-all">{flash.success}</CardContent>
                </Card>
            )}

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Daftar Kunci Aktif ({clients.length})</CardTitle>
                    <CardDescription>Throttle 120 req/menit per IP. Scope dicek per endpoint.</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2.5 font-medium">Klien</th>
                                    <th className="px-4 py-2.5 font-medium">API Key</th>
                                    <th className="px-4 py-2.5 font-medium">Scopes</th>
                                    <th className="px-4 py-2.5 font-medium">Status</th>
                                    <th className="px-4 py-2.5 font-medium">Terakhir dipakai</th>
                                    <th className="px-4 py-2.5 font-medium text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {clients.map(c => (
                                    <tr key={c.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <p className="font-semibold">{c.name}</p>
                                            {c.notes && <p className="text-xs text-muted-foreground">{c.notes}</p>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <code className="font-mono text-xs bg-muted px-2 py-1 rounded">
                                                    {reveal[c.id] ? c.api_key : c.masked_key}
                                                </code>
                                                <button
                                                    onClick={() => setReveal(r => ({ ...r, [c.id]: !r[c.id] }))}
                                                    className="text-muted-foreground hover:text-foreground"
                                                    title={reveal[c.id] ? 'Sembunyikan' : 'Tampilkan'}
                                                >
                                                    {reveal[c.id] ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                                </button>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                {c.scopes.map(s => (
                                                    <Badge key={s} variant="secondary" className="font-mono text-[11px]">{s}</Badge>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge className={cn(c.is_active
                                                ? 'bg-emerald-500/15 text-emerald-500 border-emerald-500/20'
                                                : 'bg-slate-500/15 text-slate-500 border-slate-500/20')}>
                                                {c.is_active ? 'Aktif' : 'Nonaktif'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">
                                            {c.last_used_at ? new Date(c.last_used_at).toLocaleString('id-ID') : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost" size="sm"
                                                    onClick={() => router.post(`/api-clients/${c.id}/toggle-active`, {}, { preserveScroll: true })}
                                                    title={c.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                                >
                                                    <Power className="w-4 h-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost" size="sm"
                                                    onClick={() => setConfirm({ type: 'regenerate', client: c })}
                                                    title="Generate ulang (key lama mati)"
                                                >
                                                    <RefreshCw className="w-4 h-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost" size="sm"
                                                    onClick={() => setConfirm({ type: 'delete', client: c })}
                                                    title="Hapus permanen"
                                                    className="text-destructive hover:text-destructive"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {clients.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-10 text-center text-sm text-muted-foreground">
                                            Belum ada API key. Buat key pertama untuk SIPSMAD / PTSP.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            {/* Dialog buat key */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Buat API Key Baru</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Nama Klien *</Label>
                            <Input
                                value={data.name}
                                onChange={e => setData('name', e.target.value)}
                                placeholder="SIPSMAD / PTSP / E-Surat"
                                required
                            />
                            {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Scopes * (minimal 1)</Label>
                            {Object.entries(availableScopes).map(([scope, desc]) => (
                                <label key={scope} className="flex items-start gap-2.5 text-sm cursor-pointer rounded-lg border p-2.5 hover:bg-muted/50">
                                    <input
                                        type="checkbox"
                                        checked={data.scopes.includes(scope)}
                                        onChange={() => toggleScope(scope)}
                                        className="mt-0.5"
                                    />
                                    <span>
                                        <code className="font-mono text-xs font-semibold">{scope}</code>
                                        <span className="block text-xs text-muted-foreground">{desc}</span>
                                    </span>
                                </label>
                            ))}
                            {errors.scopes && <p className="text-xs text-destructive">{errors.scopes}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Catatan</Label>
                            <Input
                                value={data.notes}
                                onChange={e => setData('notes', e.target.value)}
                                placeholder="Kontak PIC / keterangan server"
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                <X className="w-4 h-4 mr-1.5" /> Batal
                            </Button>
                            <Button type="submit" disabled={processing || data.scopes.length === 0}>
                                <KeyRound className="w-4 h-4 mr-1.5" /> Generate Key
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Konfirmasi regenerate / delete */}
            <AlertDialog open={!!confirm} onOpenChange={() => setConfirm(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {confirm?.type === 'delete' ? 'Hapus API Key?' : 'Generate ulang key?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirm?.type === 'delete'
                                ? `Key ${confirm?.client.name} dihapus permanen. Aplikasi satelit langsung putus koneksi.`
                                : `Key lama ${confirm?.client.name} langsung mati. Update key di aplikasi satelit segera.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={doAction}>Ya, lanjutkan</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
