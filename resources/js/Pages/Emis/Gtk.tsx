import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    RefreshCw, CheckCircle2, AlertCircle, Loader2,
    Users, Award, Clock, Key
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow
} from '@/components/ui/table';
import { toast } from 'sonner';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { IntegrationTokenCard, IntegrationTokenItem } from '@/components/IntegrationTokenCard';

interface GtkStaging {
    id: number;
    name: string;
    full_name_with_titles: string | null;
    nik: string | null;
    nip: string | null;
    npk: string | null;
    nuptk: string | null;
    nrg: string | null;
    peg_id: string | null;
    function_role: string | null;
    employment_status: string | null;
    highest_education: string | null;
    is_sertifikasi: boolean | null;
    k_aktif: boolean | null;
}

interface SyncStatus {
    running: boolean;
    started_at: string | null;
    progress: string;
    result: { success: boolean; message: string; imported?: number } | null;
}

interface Props {
    stagings: { data: GtkStaging[]; total: number; from: number; to: number };
    summary: { total: number; pending: number; sertifikasi: number; aktif: number; last_sync: string | null; has_sso: boolean };
    integrationTokens?: IntegrationTokenItem[];
    flash: { success?: string; error?: string };
}

export default function EmisGtkPage({ stagings, summary, integrationTokens = [], flash }: Props) {
    const [syncStatus, setSyncStatus] = useState<SyncStatus>({ running: false, started_at: null, progress: '', result: null });
    const [ssoToken, setSsoToken] = useState('');
    const [savingToken, setSavingToken] = useState(false);

    if (flash?.success) toast.success(flash.success);
    if (flash?.error)   toast.error(flash.error);

    const pollStatus = useCallback(async () => {
        try {
            const res = await fetch('/emis/gtk/sync-status');
            const data: SyncStatus = await res.json();
            setSyncStatus(data);
            if (!data.running && data.result) {
                if (data.result.success) {
                    toast.success(data.result.message);
                    router.reload({ only: ['stagings', 'summary'] });
                } else {
                    toast.error(data.result.message);
                }
            }
        } catch { /* ignore */ }
    }, []);

    useEffect(() => {
        if (syncStatus.running) {
            const id = setInterval(pollStatus, 3000);
            return () => clearInterval(id);
        }
    }, [syncStatus.running, pollStatus]);

    async function handleSync() {
        setSyncStatus(prev => ({ ...prev, running: true, result: null, progress: 'Mengirim permintaan...' }));
        try {
            const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const res = await fetch('/emis/gtk/sync', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' },
            });
            const data = await res.json();
            if (!data.success) {
                toast.error(data.message);
                setSyncStatus(prev => ({ ...prev, running: false }));
            } else {
                toast.info('Sinkronisasi dimulai di background...');
                pollStatus();
            }
        } catch {
            toast.error('Gagal terhubung ke server.');
            setSyncStatus(prev => ({ ...prev, running: false }));
        }
    }

    async function handleSaveToken() {
        if (!ssoToken.trim()) return;
        setSavingToken(true);
        try {
            const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            await fetch('/pengaturan/emis-sso-token', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' },
                body: JSON.stringify({ emis_sso_token: ssoToken }),
            });
            toast.success('Token emisSSO berhasil disimpan.');
            setSsoToken('');
            router.reload({ only: ['summary'] });
        } catch {
            toast.error('Gagal menyimpan token.');
        } finally {
            setSavingToken(false);
        }
    }

    return (
        <AppLayout>
            <Head title="Integrasi EMIS GTK" />

            <div className="mb-6">
                <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                    <RefreshCw className="w-4 h-4" />
                    <span>Integrasi EMIS</span>
                </div>
                <div className="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">Sinkronisasi GTK</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            Data PTK dari <span className="font-mono text-xs">emisgtk.kemenag.go.id</span>
                        </p>
                    </div>
                    <Button onClick={handleSync} disabled={syncStatus.running} className="gap-2">
                        {syncStatus.running
                            ? <><Loader2 className="w-4 h-4 animate-spin" />Sedang Sync...</>
                            : <><RefreshCw className="w-4 h-4" />Sinkronkan Sekarang</>
                        }
                    </Button>
                </div>
            </div>

            {/* Summary Cards */}
            <div className="mb-5">
                <IntegrationTokenCard
                    purpose="emis_gtk"
                    title="Token Kredensial Bookmarklet EMIS GTK"
                    tokens={integrationTokens}
                />
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-5">
                {[
                    { label: 'Total PTK', value: summary.total, icon: Users, bg: 'bg-blue-500/10', color: 'text-blue-500' },
                    { label: 'Aktif', value: summary.aktif, icon: CheckCircle2, bg: 'bg-emerald-500/10', color: 'text-emerald-500' },
                    { label: 'Sertifikasi', value: summary.sertifikasi, icon: Award, bg: 'bg-amber-500/10', color: 'text-amber-500' },
                    {
                        label: 'Terakhir Sync',
                        value: summary.last_sync ? new Date(summary.last_sync).toLocaleDateString('id-ID') : '—',
                        icon: Clock, bg: 'bg-muted', color: 'text-muted-foreground'
                    },
                ].map(card => {
                    const Icon = card.icon;
                    return (
                        <Card key={card.label}>
                            <CardContent className="pt-4 pb-4">
                                <div className="flex items-center gap-3">
                                    <div className={`rounded-lg p-2 ${card.bg}`}><Icon className={`w-4 h-4 ${card.color}`} /></div>
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

            {/* Sync Status Banner */}
            {(syncStatus.running || syncStatus.result) && (
                <div className={cn(
                    'mb-5 flex items-center gap-3 rounded-lg border px-4 py-3 text-sm',
                    syncStatus.running && 'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-300',
                    !syncStatus.running && syncStatus.result?.success && 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
                    !syncStatus.running && syncStatus.result && !syncStatus.result.success && 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-300',
                )}>
                    {syncStatus.running ? <Loader2 className="w-4 h-4 animate-spin shrink-0" />
                        : syncStatus.result?.success ? <CheckCircle2 className="w-4 h-4 shrink-0" />
                        : <AlertCircle className="w-4 h-4 shrink-0" />}
                    <span>{syncStatus.running ? syncStatus.progress : syncStatus.result?.message}</span>
                </div>
            )}

            {/* SSO Token Config */}
            {!summary.has_sso && (
                <Card className="mb-5 border-amber-500/30 bg-amber-500/5">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm flex items-center gap-2 text-amber-600 dark:text-amber-400">
                            <Key className="w-4 h-4" />Konfigurasi Token SSO
                        </CardTitle>
                        <CardDescription>
                            Token belum ada. Ambil dari cookie <code className="font-mono text-xs bg-muted px-1 rounded">emisSSO</code> pada domain <code className="font-mono text-xs bg-muted px-1 rounded">.kemenag.go.id</code> setelah login EMIS.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex gap-2 max-w-lg">
                            <Input value={ssoToken} onChange={e => setSsoToken(e.target.value)} placeholder="Paste token emisSSO..." className="font-mono text-xs" />
                            <Button onClick={handleSaveToken} disabled={savingToken || !ssoToken.trim()} size="sm">Simpan</Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Data Table */}
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Data PTK (Staging)</CardTitle>
                    <CardDescription className="text-xs">
                        {stagings.total > 0
                            ? `Menampilkan ${stagings.from}–${stagings.to} dari ${stagings.total} PTK`
                            : 'Belum ada data. Jalankan sinkronisasi terlebih dahulu.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nama PTK</TableHead>
                                    <TableHead>NIK / NIP</TableHead>
                                    <TableHead>Fungsi</TableHead>
                                    <TableHead>Status Kepeg.</TableHead>
                                    <TableHead>Kualifikasi</TableHead>
                                    <TableHead>Sertifikasi</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {stagings.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center py-12 text-muted-foreground">
                                            Belum ada data PTK. Klik "Sinkronkan Sekarang" untuk memulai.
                                        </TableCell>
                                    </TableRow>
                                ) : stagings.data.map(ptk => (
                                    <TableRow key={ptk.id}>
                                        <TableCell>
                                            <div className="font-medium text-sm">{ptk.full_name_with_titles ?? ptk.name}</div>
                                            {ptk.peg_id && <div className="text-xs text-muted-foreground font-mono">ID: {ptk.peg_id}</div>}
                                        </TableCell>
                                        <TableCell className="text-xs font-mono">
                                            {ptk.nik && <div>NIK: {ptk.nik}</div>}
                                            {ptk.nip && <div>NIP: {ptk.nip}</div>}
                                        </TableCell>
                                        <TableCell className="text-xs">{ptk.function_role ?? '—'}</TableCell>
                                        <TableCell><Badge variant="outline" className="text-xs">{ptk.employment_status ?? '—'}</Badge></TableCell>
                                        <TableCell className="text-xs">{ptk.highest_education ?? '—'}</TableCell>
                                        <TableCell>
                                            {ptk.is_sertifikasi === null
                                                ? <span className="text-xs text-muted-foreground">—</span>
                                                : ptk.is_sertifikasi
                                                    ? <Badge className="bg-emerald-500/15 text-emerald-500 border-emerald-500/20 text-xs">Sudah</Badge>
                                                    : <Badge variant="outline" className="text-muted-foreground text-xs">Belum</Badge>}
                                        </TableCell>
                                        <TableCell>
                                            {ptk.k_aktif === null
                                                ? <span className="text-xs text-muted-foreground">—</span>
                                                : ptk.k_aktif
                                                    ? <Badge className="bg-blue-500/15 text-blue-500 border-blue-500/20 text-xs">Aktif</Badge>
                                                    : <Badge variant="outline" className="text-muted-foreground text-xs">Nonaktif</Badge>}
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
