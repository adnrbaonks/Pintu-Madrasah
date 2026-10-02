import { Head, useForm, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Mail, Inbox, Send, ArrowLeft, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';

interface LetterItem {
    id?: number;
    type?: string;
    number?: string;
    subject?: string;
    category?: string;
    letter_date?: string;
    received_date?: string;
    from_to?: string;
    description?: string;
    disposisi?: string;
    status?: string;
}

interface Props {
    type: 'masuk' | 'keluar';
    draftNumber?: string;
    categories: string[];
    letter?: LetterItem;
}

const categoryLabel: Record<string, string> = {
    umum: 'Umum', undangan: 'Undangan', sk: 'Surat Keputusan (SK)',
    keterangan: 'Surat Keterangan', rekomendasi: 'Rekomendasi',
    permohonan: 'Permohonan', pemberitahuan: 'Pemberitahuan',
    edaran: 'Edaran', lainnya: 'Lainnya',
};

export default function PersuratanForm({ type, draftNumber, categories, letter }: Props) {
    const isEdit = !!letter?.id;
    const isKeluar = (letter?.type ?? type) === 'keluar';

    const { data, setData, post, put, processing, errors } = useForm({
        type:          letter?.type ?? type,
        number:        letter?.number ?? '',
        subject:       letter?.subject ?? '',
        category:      letter?.category ?? 'umum',
        letter_date:   letter?.letter_date?.split('T')[0] ?? new Date().toISOString().split('T')[0],
        received_date: letter?.received_date?.split('T')[0] ?? '',
        from_to:       letter?.from_to ?? '',
        description:   letter?.description ?? '',
        disposisi:     letter?.disposisi ?? '',
        status:        letter?.status ?? 'active',
        attachment:    null as File | null,
    });

    const [refreshingNum, setRefreshingNum] = useState(false);

    async function refreshNumber() {
        if (!isKeluar || isEdit) return;
        setRefreshingNum(true);
        try {
            const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const res = await fetch(`/persuratan/create?type=keluar&_inertia=1`, { headers: { 'X-Inertia': '1', 'X-CSRF-TOKEN': token } });
        } catch { /* ignore */ } finally {
            setRefreshingNum(false);
        }
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (isEdit) {
            put(`/persuratan/${letter!.id}`, { forceFormData: true });
        } else {
            post('/persuratan', { forceFormData: true });
        }
    }

    return (
        <AppLayout>
            <Head title={isEdit ? 'Edit Surat' : `Tambah Surat ${isKeluar ? 'Keluar' : 'Masuk'}`} />

            <div className="mb-6">
                <Link href="/persuratan" className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-3 w-fit">
                    <ArrowLeft className="w-4 h-4" />
                    Kembali ke Daftar Surat
                </Link>
                <div className="flex items-center gap-3">
                    <div className={`rounded-xl p-2.5 ${isKeluar ? 'bg-emerald-500/10' : 'bg-blue-500/10'}`}>
                        {isKeluar ? <Send className="w-5 h-5 text-emerald-500" /> : <Inbox className="w-5 h-5 text-blue-500" />}
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">
                            {isEdit ? 'Edit Surat' : `Tambah Surat ${isKeluar ? 'Keluar' : 'Masuk'}`}
                        </h1>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            {isKeluar ? 'Nomor surat akan di-generate otomatis jika dikosongkan' : 'Catat surat masuk ke buku agenda'}
                        </p>
                    </div>
                </div>
            </div>

            <form onSubmit={submit}>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 max-w-5xl">

                    {/* Form utama */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-4">
                            <CardTitle className="text-sm">Informasi Surat</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">

                            {/* Nomor Surat */}
                            <div className="space-y-1.5">
                                <Label>
                                    Nomor Surat
                                    {isKeluar && !isEdit && (
                                        <span className="ml-2 text-xs text-muted-foreground">(kosongkan untuk auto-generate)</span>
                                    )}
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        value={data.number}
                                        onChange={e => setData('number', e.target.value)}
                                        placeholder={isKeluar ? draftNumber || 'Contoh: 001/SRT/MTs.NM/X/2026' : 'Nomor surat pengirim'}
                                        className="font-mono text-sm"
                                    />
                                </div>
                                {errors.number && <p className="text-xs text-destructive">{errors.number}</p>}
                                {isKeluar && draftNumber && !data.number && !isEdit && (
                                    <p className="text-xs text-muted-foreground">Preview: <span className="font-mono text-foreground">{draftNumber}</span></p>
                                )}
                            </div>

                            {/* Perihal */}
                            <div className="space-y-1.5">
                                <Label>Perihal / Subjek <span className="text-destructive">*</span></Label>
                                <Input
                                    value={data.subject}
                                    onChange={e => setData('subject', e.target.value)}
                                    placeholder="Perihal atau judul surat"
                                    required
                                />
                                {errors.subject && <p className="text-xs text-destructive">{errors.subject}</p>}
                            </div>

                            {/* Dari / Kepada */}
                            <div className="space-y-1.5">
                                <Label>{isKeluar ? 'Kepada' : 'Dari'} <span className="text-destructive">*</span></Label>
                                <Input
                                    value={data.from_to}
                                    onChange={e => setData('from_to', e.target.value)}
                                    placeholder={isKeluar ? 'Nama / instansi tujuan surat' : 'Nama / instansi pengirim surat'}
                                    required
                                />
                                {errors.from_to && <p className="text-xs text-destructive">{errors.from_to}</p>}
                            </div>

                            {/* Tanggal */}
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Tanggal Surat <span className="text-destructive">*</span></Label>
                                    <Input
                                        type="date"
                                        value={data.letter_date}
                                        onChange={e => setData('letter_date', e.target.value)}
                                        required
                                    />
                                </div>
                                {!isKeluar && (
                                    <div className="space-y-1.5">
                                        <Label>Tanggal Diterima</Label>
                                        <Input
                                            type="date"
                                            value={data.received_date}
                                            onChange={e => setData('received_date', e.target.value)}
                                        />
                                    </div>
                                )}
                            </div>

                            {/* Isi Ringkas */}
                            <div className="space-y-1.5">
                                <Label>Isi Ringkas / Catatan</Label>
                                <Textarea
                                    value={data.description ?? ''}
                                    onChange={e => setData('description', e.target.value)}
                                    placeholder="Ringkasan isi surat atau catatan tambahan..."
                                    rows={3}
                                />
                            </div>

                            {/* Disposisi */}
                            {!isKeluar && (
                                <div className="space-y-1.5">
                                    <Label>Disposisi Pimpinan</Label>
                                    <Textarea
                                        value={data.disposisi ?? ''}
                                        onChange={e => setData('disposisi', e.target.value)}
                                        placeholder="Catatan disposisi dari kepala madrasah..."
                                        rows={2}
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Sidebar: kategori & lampiran */}
                    <div className="space-y-5">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm">Klasifikasi</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label>Kategori <span className="text-destructive">*</span></Label>
                                    <Select value={data.category} onValueChange={v => setData('category', v)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map(cat => (
                                                <SelectItem key={cat} value={cat}>{categoryLabel[cat] ?? cat}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {isEdit && (
                                    <div className="space-y-1.5">
                                        <Label>Status</Label>
                                        <Select value={data.status} onValueChange={v => setData('status', v)}>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="active">Aktif</SelectItem>
                                                <SelectItem value="archived">Diarsipkan</SelectItem>
                                                <SelectItem value="disposisi">Disposisi</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm">Lampiran</CardTitle>
                                <CardDescription className="text-xs">PDF, JPG, PNG, DOC, DOCX (maks. 10MB)</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                    onChange={e => setData('attachment', e.target.files?.[0] ?? null)}
                                    className="text-xs"
                                />
                            </CardContent>
                        </Card>

                        <div className="flex flex-col gap-2">
                            <Button type="submit" disabled={processing} className="w-full">
                                {processing ? 'Menyimpan...' : isEdit ? 'Perbarui Surat' : 'Simpan Surat'}
                            </Button>
                            <Link href="/persuratan">
                                <Button type="button" variant="outline" className="w-full">Batal</Button>
                            </Link>
                        </div>
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}
