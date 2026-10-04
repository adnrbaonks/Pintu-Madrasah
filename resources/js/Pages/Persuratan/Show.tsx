import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { ArrowLeft, Inbox, Send, Pencil, Paperclip } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Letter {
    id: string;
    type: string;
    number?: string;
    subject: string;
    category: string;
    letter_date: string;
    received_date?: string;
    from_to: string;
    description?: string;
    disposisi?: string;
    status?: string;
    attachment_path?: string;
    creator?: { id: string; name: string };
}

export default function PersuratanShow({ letter, verification }: { letter: Letter; categories: string[]; verification?: { token: string; url: string } | null }) {
    const isKeluar = letter.type === 'keluar';
    const rows: [string, string | undefined][] = [
        ['Nomor Surat', letter.number || '-'],
        ['Perihal', letter.subject],
        [isKeluar ? 'Kepada' : 'Dari', letter.from_to],
        ['Kategori', letter.category],
        ['Tanggal Surat', letter.letter_date?.split('T')[0]],
        ...(!isKeluar ? [['Tanggal Diterima', letter.received_date?.split('T')[0] || '-'] as [string, string]] : []),
        ['Status', letter.status || '-'],
        ['Dibuat oleh', letter.creator?.name || '-'],
    ];

    return (
        <AppLayout>
            <Head title={`Detail Surat — ${letter.number || letter.subject}`} />
            <Link href="/persuratan" className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-3 w-fit">
                <ArrowLeft className="w-4 h-4" /> Kembali ke Daftar Surat
            </Link>
            <div className="flex items-center gap-3 mb-6">
                <div className={`rounded-xl p-2.5 ${isKeluar ? 'bg-emerald-500/10' : 'bg-blue-500/10'}`}>
                    {isKeluar ? <Send className="w-5 h-5 text-emerald-500" /> : <Inbox className="w-5 h-5 text-blue-500" />}
                </div>
                <div className="flex-1">
                    <h1 className="text-2xl font-bold">{letter.subject}</h1>
                    <p className="text-sm text-muted-foreground mt-0.5 font-mono">{letter.number || '(tanpa nomor)'}</p>
                </div>
                <Badge variant={isKeluar ? 'default' : 'secondary'}>{isKeluar ? 'Keluar' : 'Masuk'}</Badge>
                <Link href={`/persuratan/${letter.id}/edit`}>
                    <Button variant="outline" size="sm"><Pencil className="w-4 h-4 mr-1.5" /> Edit</Button>
                </Link>
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 max-w-5xl">
                <Card className="lg:col-span-2">
                    <CardHeader className="pb-4"><CardTitle className="text-sm">Informasi Surat</CardTitle></CardHeader>
                    <CardContent>
                        <dl className="divide-y divide-border">
                            {rows.map(([label, value]) => (
                                <div key={label} className="grid grid-cols-3 gap-3 py-2.5 text-sm">
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="col-span-2 font-medium">{value || '-'}</dd>
                                </div>
                            ))}
                        </dl>
                        {letter.description && (
                            <div className="mt-4 text-sm">
                                <p className="text-muted-foreground mb-1">Isi / Keterangan</p>
                                <p className="whitespace-pre-wrap">{letter.description}</p>
                            </div>
                        )}
                        {letter.disposisi && (
                            <div className="mt-4 text-sm">
                                <p className="text-muted-foreground mb-1">Disposisi</p>
                                <p className="whitespace-pre-wrap">{letter.disposisi}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
                <Card className="h-fit">
                    <CardHeader className="pb-4"><CardTitle className="text-sm">Lampiran</CardTitle></CardHeader>
                    <CardContent>
                        {letter.attachment_path ? (
                            <a href={letter.attachment_path} target="_blank" rel="noreferrer" className="flex items-center gap-2 text-sm text-primary hover:underline">
                                <Paperclip className="w-4 h-4" /> Lihat lampiran
                            </a>
                        ) : (
                            <p className="text-sm text-muted-foreground">Tidak ada lampiran.</p>
                        )}
                        {verification && (
                            <div className="mt-4 pt-4 border-t">
                                <p className="text-sm font-medium mb-2">QR Verifikasi</p>
                                <img src={`/v/${verification.token}/qr`} alt="QR verifikasi surat" className="w-32 h-32 border rounded" />
                                <p className="text-[11px] text-muted-foreground mt-2 break-all">{verification.url}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
