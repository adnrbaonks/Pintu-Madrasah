import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { Mail, Plus, Search, ArrowUpDown, FileText, Inbox, Send, Filter, Download, Paperclip } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface LetterItem {
    id: number;
    type: 'masuk' | 'keluar';
    number: string;
    subject: string;
    category: string;
    letter_date: string;
    received_date: string | null;
    from_to: string;
    status: string;
    attachment_path: string | null;
    creator: { id: number; name: string } | null;
}

interface Props {
    letters: {
        data: LetterItem[];
        total: number;
        from: number;
        to: number;
        current_page: number;
        last_page: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { type?: string; category?: string; q?: string };
    stats: { masuk: number; keluar: number; bulan: number };
    flash: { success?: string; error?: string };
}

const categoryLabel: Record<string, string> = {
    umum: 'Umum', undangan: 'Undangan', sk: 'SK', keterangan: 'Ket.',
    rekomendasi: 'Rekomendasi', permohonan: 'Permohonan', pemberitahuan: 'Pemberitahuan',
    edaran: 'Edaran', lainnya: 'Lainnya',
};

const categoryColor: Record<string, string> = {
    sk:             'bg-purple-500/15 text-purple-600 border-purple-500/20',
    undangan:       'bg-blue-500/15 text-blue-600 border-blue-500/20',
    keterangan:     'bg-teal-500/15 text-teal-600 border-teal-500/20',
    pemberitahuan:  'bg-amber-500/15 text-amber-600 border-amber-500/20',
    edaran:         'bg-orange-500/15 text-orange-600 border-orange-500/20',
};

export default function PersuratanIndex({ letters, filters, stats, flash }: Props) {
    const [search, setSearch] = useState(filters.q ?? '');

    function applyFilter(key: string, value: string) {
        router.get('/persuratan', { ...filters, [key]: value || undefined, q: search || undefined }, { preserveState: true });
    }

    function applySearch(e: React.FormEvent) {
        e.preventDefault();
        router.get('/persuratan', { ...filters, q: search || undefined }, { preserveState: true });
    }

    const formatDate = (dateStr: string) => {
        if (!dateStr) return '—';
        return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    };

    return (
        <AppLayout>
            <Head title="Persuratan" />

            <div className="mb-6">
                <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                    <Mail className="w-4 h-4" />
                    <span>Administrasi</span>
                </div>
                <div className="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">Persuratan</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">Buku agenda surat masuk dan surat keluar</p>
                    </div>
                    <div className="flex gap-2">
                        <Link href="/persuratan/create?type=masuk">
                            <Button variant="outline" className="gap-2 h-9">
                                <Inbox className="w-4 h-4" />Surat Masuk
                            </Button>
                        </Link>
                        <Link href="/persuratan/create?type=keluar">
                            <Button className="gap-2 h-9">
                                <Send className="w-4 h-4" />Buat Surat Keluar
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-3 gap-4 mb-5">
                {[
                    { label: 'Surat Masuk', value: stats.masuk, icon: Inbox, color: 'text-blue-500 bg-blue-500/10' },
                    { label: 'Surat Keluar', value: stats.keluar, icon: Send, color: 'text-emerald-500 bg-emerald-500/10' },
                    { label: 'Bulan Ini', value: stats.bulan, icon: FileText, color: 'text-amber-500 bg-amber-500/10' },
                ].map(card => {
                    const Icon = card.icon;
                    const [tc, bg] = card.color.split(' ');
                    return (
                        <Card key={card.label}>
                            <CardContent className="pt-4 pb-4">
                                <div className="flex items-center gap-3">
                                    <div className={`rounded-lg p-2 ${bg}`}><Icon className={`w-4 h-4 ${tc}`} /></div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">{card.label}</p>
                                        <p className="text-2xl font-bold">{card.value.toLocaleString('id-ID')}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            {/* Table */}
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-center gap-3">
                        {/* Filter Type */}
                        <Select value={filters.type ?? ''} onValueChange={v => applyFilter('type', v)}>
                            <SelectTrigger className="w-36 h-8 text-xs">
                                <SelectValue placeholder="Semua Jenis" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">Semua Jenis</SelectItem>
                                <SelectItem value="masuk">Surat Masuk</SelectItem>
                                <SelectItem value="keluar">Surat Keluar</SelectItem>
                            </SelectContent>
                        </Select>

                        {/* Filter Category */}
                        <Select value={filters.category ?? ''} onValueChange={v => applyFilter('category', v)}>
                            <SelectTrigger className="w-40 h-8 text-xs">
                                <SelectValue placeholder="Semua Kategori" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">Semua Kategori</SelectItem>
                                {Object.entries(categoryLabel).map(([k, v]) => (
                                    <SelectItem key={k} value={k}>{v}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {/* Search */}
                        <form onSubmit={applySearch} className="flex-1 flex gap-2">
                            <div className="relative flex-1 max-w-xs">
                                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={e => setSearch(e.target.value)}
                                    placeholder="Cari nomor, perihal, dari/kepada..."
                                    className="pl-8 h-8 text-xs"
                                />
                            </div>
                            <Button type="submit" size="sm" variant="secondary" className="h-8 text-xs">Cari</Button>
                        </form>
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-20">Jenis</TableHead>
                                    <TableHead className="min-w-[160px]">Nomor Surat</TableHead>
                                    <TableHead className="min-w-[280px]">Perihal</TableHead>
                                    <TableHead>Dari / Kepada</TableHead>
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead>Kategori</TableHead>
                                    <TableHead className="w-16 text-center">Lamp.</TableHead>
                                    <TableHead className="w-20">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {letters.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center py-12 text-muted-foreground">
                                            <Mail className="w-8 h-8 mx-auto mb-2 opacity-30" />
                                            Belum ada surat. Klik tombol "Surat Masuk" atau "Buat Surat Keluar" untuk menambahkan.
                                        </TableCell>
                                    </TableRow>
                                ) : letters.data.map(letter => (
                                    <TableRow key={letter.id}>
                                        <TableCell>
                                            {letter.type === 'masuk'
                                                ? <Badge className="bg-blue-500/15 text-blue-600 border-blue-500/20 text-xs gap-1">
                                                    <Inbox className="w-3 h-3" />Masuk
                                                  </Badge>
                                                : <Badge className="bg-emerald-500/15 text-emerald-600 border-emerald-500/20 text-xs gap-1">
                                                    <Send className="w-3 h-3" />Keluar
                                                  </Badge>
                                            }
                                        </TableCell>
                                        <TableCell className="text-xs font-mono">{letter.number}</TableCell>
                                        <TableCell>
                                            <Link href={`/persuratan/${letter.id}`} className="font-medium text-sm hover:text-primary transition-colors">
                                                {letter.subject}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-xs text-muted-foreground">{letter.from_to}</TableCell>
                                        <TableCell className="text-xs whitespace-nowrap">{formatDate(letter.letter_date)}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className={cn('text-xs', categoryColor[letter.category] ?? '')}>
                                                {categoryLabel[letter.category] ?? letter.category}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-center">
                                            {letter.attachment_path
                                                ? <a href={letter.attachment_path} target="_blank" rel="noopener noreferrer">
                                                    <Paperclip className="w-4 h-4 text-blue-500 mx-auto hover:text-blue-700" />
                                                  </a>
                                                : <span className="text-muted-foreground text-xs">—</span>
                                            }
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex gap-1">
                                                <Link href={`/persuratan/${letter.id}`}>
                                                    <Button variant="ghost" size="sm" className="h-7 px-2 text-xs">Lihat</Button>
                                                </Link>
                                                <Link href={`/persuratan/${letter.id}/edit`}>
                                                    <Button variant="ghost" size="sm" className="h-7 px-2 text-xs">Edit</Button>
                                                </Link>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination */}
                    {letters.last_page > 1 && (
                        <div className="flex items-center justify-between px-4 py-3 border-t">
                            <p className="text-xs text-muted-foreground">
                                {letters.total} surat | Halaman {letters.current_page} dari {letters.last_page}
                            </p>
                            <div className="flex gap-1">
                                {letters.links.filter(l => l.url).map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        className="h-7 min-w-7 text-xs px-2"
                                        onClick={() => link.url && router.get(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
