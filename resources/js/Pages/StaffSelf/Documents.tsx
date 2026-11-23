import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Upload, Trash2, FileText, ExternalLink } from 'lucide-react';

interface StaffDoc {
    id: string;
    document_type: string;
    title: string;
    file_path: string | null;
    file_size: number;
    status: string;
    created_at: string;
}

const DOC_LABELS: Record<string, string> = {
    sk_pengangkatan: 'SK Pengangkatan',
    ijazah: 'Ijazah Pendidikan',
    sertifikat_pendidik: 'Sertifikat Pendidik',
    ktp: 'KTP',
    kk: 'Kartu Keluarga',
    lainnya: 'Dokumen Lainnya',
};

export default function StaffSelfDocuments({
    staff,
    documents,
}: {
    staff: { id: string; full_name_with_titles: string } | null;
    documents: StaffDoc[];
}) {
    const form = useForm<{
        document_type: string;
        title: string;
        file: File | null;
    }>({
        document_type: 'sk_pengangkatan',
        title: '',
        file: null,
    });

    const handleUpload = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/profil-gtk/dokumen', {
            forceFormData: true,
            onSuccess: () => form.reset('title', 'file'),
        });
    };

    const getFilename = (filePath: string | null) => {
        if (!filePath) return null;
        const parts = filePath.split('/');
        return parts[parts.length - 1];
    };

    return (
        <AppLayout title="Arsip Dokumen Kepegawaian Saya">
            <Head title="Arsip Dokumen Kepegawaian Saya" />

            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Link href="/profil-gtk">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <ArrowLeft className="w-4 h-4" />
                            Kembali
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold text-slate-800">Arsip Dokumen Kepegawaian Saya</h1>
                        <p className="text-sm text-slate-500">
                            Unggah SK, Ijazah, Sertifikat Pendidik, dan dokumen kepegawaian pribadi.
                        </p>
                    </div>
                </div>

                {!staff ? (
                    <Card>
                        <CardContent className="p-6 text-center text-sm text-slate-500">
                            Akun Anda belum terhubung dengan profil GTK.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Unggah Dokumen Baru</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleUpload} className="space-y-3 text-sm">
                                    <div>
                                        <Label>Jenis Dokumen</Label>
                                        <select
                                            className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                            value={form.data.document_type}
                                            onChange={e => form.setData('document_type', e.target.value)}
                                        >
                                            {Object.entries(DOC_LABELS).map(([k, v]) => (
                                                <option key={k} value={k}>{v}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label>Judul Dokumen</Label>
                                        <Input
                                            value={form.data.title}
                                            onChange={e => form.setData('title', e.target.value)}
                                            placeholder="Contoh: SK Pengangkatan 2026"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Berkas (PDF/JPG/PNG, Maks 5MB)</Label>
                                        <Input
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            onChange={e => form.setData('file', e.target.files?.[0] || null)}
                                            required
                                        />
                                    </div>
                                    <Button type="submit" disabled={form.processing} className="bg-teal-700 hover:bg-teal-800">
                                        <Upload className="w-4 h-4 mr-1.5" />
                                        Unggah Dokumen
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <CardTitle className="text-base">Daftar Dokumen Tersimpan ({documents.length})</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {documents.length === 0 ? (
                                    <p className="text-sm text-slate-400 py-4">Belum ada dokumen kepegawaian yang diunggah.</p>
                                ) : (
                                    <ul className="space-y-2.5 text-sm">
                                        {documents.map(doc => {
                                            const filename = getFilename(doc.file_path);
                                            return (
                                                <li key={doc.id} className="p-3 rounded-lg border bg-slate-50 flex justify-between items-center">
                                                    <div className="flex items-center gap-2.5">
                                                        <FileText className="w-4 h-4 text-teal-600" />
                                                        <div>
                                                            <p className="font-semibold text-slate-800">{doc.title}</p>
                                                            <p className="text-xs text-slate-500">
                                                                {DOC_LABELS[doc.document_type] || doc.document_type} •{' '}
                                                                {Math.round((doc.file_size || 0) / 1024)} KB
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        {filename && (
                                                            <a
                                                                href={`/archives/staff_document/${filename}`}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-xs font-medium text-teal-700 hover:underline inline-flex items-center gap-1"
                                                            >
                                                                Lihat <ExternalLink className="w-3 h-3" />
                                                            </a>
                                                        )}
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            className="text-rose-600"
                                                            onClick={() => {
                                                                if (window.confirm(`Hapus dokumen ${doc.title}?`)) {
                                                                    router.delete(`/profil-gtk/dokumen/${doc.id}`);
                                                                }
                                                            }}
                                                        >
                                                            <Trash2 className="w-3.5 h-3.5" />
                                                        </Button>
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
