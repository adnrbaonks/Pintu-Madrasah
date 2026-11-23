import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Archive as ArchiveIcon, ArrowLeft, Download, Upload, ShieldCheck, Clock, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { toast } from 'sonner';

interface VersionItem {
    id: string;
    version_number: number;
    original_name: string;
    mime_type?: string | null;
    file_size: number;
    checksum_sha256?: string | null;
    change_notes?: string | null;
    created_at: string;
    uploader?: { id: string; name: string } | null;
}

interface AccessLogItem {
    id: string;
    action: string;
    ip_address?: string | null;
    created_at: string;
    user?: { id: string; name: string; role: string } | null;
    metadata?: Record<string, unknown> | null;
}

interface ArchiveDetail {
    id: string;
    archive_number: string;
    title: string;
    description?: string | null;
    classification_code?: string | null;
    visibility: 'private' | 'internal' | 'role_restricted';
    allowed_roles?: string[] | null;
    current_version: number;
    document_date?: string | null;
    retention_until?: string | null;
    status: string;
    created_at: string;
    category?: { id: string; code: string; name: string } | null;
    organization_unit?: { id: string; code: string; name: string } | null;
    academic_year?: { id: string; year: string; semester?: string } | null;
    creator?: { id: string; name: string } | null;
    versions: VersionItem[];
    access_logs: AccessLogItem[];
}

export default function ArchiveShow({
    archive,
    signedUrls,
    canManage,
}: {
    archive: ArchiveDetail;
    signedUrls: Record<number, string>;
    canManage: boolean;
}) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [openNewVersion, setOpenNewVersion] = useState(false);

    const versionForm = useForm<{
        file: File | null;
        change_notes: string;
    }>({
        file: null,
        change_notes: '',
    });

    function submitNewVersion(e: React.FormEvent) {
        e.preventDefault();
        versionForm.post(`/arsip-digital/${archive.id}/versions`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setOpenNewVersion(false);
                versionForm.reset();
                toast.success('Versi baru berhasil diunggah.');
            },
        });
    }

    function formatBytes(bytes?: number) {
        if (!bytes) return '0 B';
        const kb = bytes / 1024;
        if (kb < 1024) return `${kb.toFixed(1)} KB`;
        return `${(kb / 1024).toFixed(2)} MB`;
    }

    return (
        <AppLayout>
            <Head title={`Arsip ${archive.archive_number}`} />

            <div className="mb-4">
                <Link href="/arsip-digital" className="inline-flex items-center text-xs text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="w-3.5 h-3.5 mr-1" /> Kembali ke Katalog Arsip Digital
                </Link>
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-start gap-3">
                    <div className="rounded-xl p-2.5 bg-indigo-500/10">
                        <ArchiveIcon className="w-5 h-5 text-indigo-600" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <code className="font-mono text-xs font-semibold text-indigo-600">{archive.archive_number}</code>
                            <Badge variant="outline">v{archive.current_version}</Badge>
                            <Badge variant="secondary">{archive.visibility}</Badge>
                        </div>
                        <h1 className="text-xl font-bold mt-1">{archive.title}</h1>
                        {archive.description && (
                            <p className="text-sm text-muted-foreground mt-0.5">{archive.description}</p>
                        )}
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    {signedUrls[archive.current_version] && (
                        <a href={signedUrls[archive.current_version]}>
                            <Button variant="default">
                                <Download className="w-4 h-4 mr-1.5" /> Unduh Versi Terbaru (v{archive.current_version})
                            </Button>
                        </a>
                    )}
                    {canManage && (
                        <Button variant="outline" onClick={() => setOpenNewVersion(true)}>
                            <Upload className="w-4 h-4 mr-1.5" /> Unggah Revisi Baru
                        </Button>
                    )}
                </div>
            </div>

            {flash?.success && (
                <Card className="mb-4 border-emerald-500/30 bg-emerald-500/5">
                    <CardContent className="py-3 text-sm text-emerald-700">{flash.success}</CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm flex items-center gap-1.5">
                                <FileText className="w-4 h-4 text-indigo-600" />
                                Riwayat Versi Dokumen ({archive.versions.length})
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Setiap unggahan revisi mempertahankan berkas lama beserta checksum SHA-256 untuk integritas audit.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-y bg-muted/50 text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2 font-medium">Versi</th>
                                        <th className="px-4 py-2 font-medium">Nama Berkas & Catatan</th>
                                        <th className="px-4 py-2 font-medium">Diunggah Oleh</th>
                                        <th className="px-4 py-2 font-medium text-right">Unduh (Signed URL)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {archive.versions.map(v => (
                                        <tr key={v.id} className="border-b last:border-0">
                                            <td className="px-4 py-3">
                                                <Badge className={v.version_number === archive.current_version
                                                    ? 'bg-emerald-500/15 text-emerald-600 border-emerald-500/20'
                                                    : 'bg-slate-500/15 text-slate-600'}>
                                                    v{v.version_number}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-xs">
                                                <div className="font-medium">{v.original_name} ({formatBytes(v.file_size)})</div>
                                                {v.change_notes && <div className="text-muted-foreground mt-0.5">{v.change_notes}</div>}
                                                {v.checksum_sha256 && (
                                                    <div className="font-mono text-[10px] text-muted-foreground mt-0.5">
                                                        SHA-256: {v.checksum_sha256.slice(0, 16)}...
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-xs">
                                                <div>{v.uploader?.name ?? '—'}</div>
                                                <div className="text-muted-foreground">
                                                    {new Date(v.created_at).toLocaleString('id-ID')}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {signedUrls[v.version_number] && (
                                                    <a href={signedUrls[v.version_number]}>
                                                        <Button variant="ghost" size="sm">
                                                            <Download className="w-3.5 h-3.5 mr-1" /> Unduh
                                                        </Button>
                                                    </a>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm flex items-center gap-1.5">
                                <Clock className="w-4 h-4 text-amber-600" />
                                Log Audit Akses & Unduhan (50 Terakhir)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-y bg-muted/50 text-left text-muted-foreground">
                                        <th className="px-4 py-2 font-medium">Waktu</th>
                                        <th className="px-4 py-2 font-medium">Pengguna</th>
                                        <th className="px-4 py-2 font-medium">Aksi</th>
                                        <th className="px-4 py-2 font-medium">Alamat IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {archive.access_logs.map(log => (
                                        <tr key={log.id} className="border-b last:border-0">
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {new Date(log.created_at).toLocaleString('id-ID')}
                                            </td>
                                            <td className="px-4 py-2">
                                                {log.user?.name ?? 'Sistem'}
                                                {log.user?.role && <span className="text-muted-foreground"> ({log.user.role})</span>}
                                            </td>
                                            <td className="px-4 py-2">
                                                <code className="font-mono font-semibold">{log.action}</code>
                                            </td>
                                            <td className="px-4 py-2 font-mono text-muted-foreground">
                                                {log.ip_address ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm flex items-center gap-1.5">
                                <ShieldCheck className="w-4 h-4 text-emerald-600" />
                                Metadata & Retensi
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2.5 text-xs">
                            <div className="flex justify-between py-1 border-b">
                                <span className="text-muted-foreground">Kategori:</span>
                                <span className="font-medium">{archive.category?.name ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b">
                                <span className="text-muted-foreground">Kode Klasifikasi:</span>
                                <code className="font-mono">{archive.classification_code ?? '—'}</code>
                            </div>
                            <div className="flex justify-between py-1 border-b">
                                <span className="text-muted-foreground">Unit Kerja:</span>
                                <span>{archive.organization_unit?.name ?? 'Seluruh Madrasah'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b">
                                <span className="text-muted-foreground">Tahun Ajaran:</span>
                                <span>{archive.academic_year?.year ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b">
                                <span className="text-muted-foreground">Tanggal Dokumen:</span>
                                <span>{archive.document_date ?? '—'}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b">
                                <span className="text-muted-foreground">Batas Retensi:</span>
                                <span>{archive.retention_until ?? 'Permanen'}</span>
                            </div>
                            <div className="flex justify-between py-1">
                                <span className="text-muted-foreground">Dibuat Oleh:</span>
                                <span>{archive.creator?.name ?? '—'}</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog open={openNewVersion} onOpenChange={setOpenNewVersion}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Unggah Versi Baru (v{archive.current_version + 1})</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitNewVersion} className="space-y-4">
                        <div className="space-y-1">
                            <Label>Berkas Revisi *</Label>
                            <Input
                                type="file"
                                onChange={e => versionForm.setData('file', e.target.files?.[0] ?? null)}
                                required
                            />
                            {versionForm.errors.file && (
                                <p className="text-xs text-destructive">{versionForm.errors.file}</p>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label>Catatan Perubahan</Label>
                            <Input
                                value={versionForm.data.change_notes}
                                onChange={e => versionForm.setData('change_notes', e.target.value)}
                                placeholder="Contoh: Perbaikan lampiran jadwal mengajar"
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpenNewVersion(false)}>Batal</Button>
                            <Button type="submit" disabled={versionForm.processing}>Unggah Versi v{archive.current_version + 1}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
