import AppLayout from '@/Layouts/AppLayout';
import { Head, router, Link, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useState, useEffect } from 'react';
import { DownloadCloud, Search, ArrowRightLeft, Loader2, Plus, Upload, CheckCircle2, Check, ChevronsUpDown, Edit, Trash2, ExternalLink } from 'lucide-react';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Badge } from '@/components/ui/badge';
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { cn } from "@/lib/utils";

interface MutationData {
    id: number;
    nisn: string;
    name: string;
    type: 'in' | 'out';
    mutation_date: string;
    reason: string;
    origin_school: string;
    destination_school: string;
    status: string;
    document_path?: string;
    document_status?: string;
    gdrive_view_url?: string;
    upload_retries?: number;
    student?: { id: number, name: string, nisn: string };
}

interface IndexProps extends PageProps {
    mutations: {
        data: MutationData[];
        links: any[];
        current_page: number;
        last_page: number;
    };
    students: { id: number; name: string; nisn: string }[];
    filters: {
        type?: string;
        search?: string;
    };
    flash: {
        success?: string;
    };
}

export default function Index({ auth, mutations, students, filters = {}, flash = {} }: IndexProps) {
    const [search, setSearch] = useState(filters.search || '');
    const [type, setType] = useState(filters.type || 'all');
    const [isSyncing, setIsSyncing] = useState(false);
    const [progressStatus, setProgressStatus] = useState('');

    // Modal states
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isUploadModalOpen, setIsUploadModalOpen] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [selectedMutation, setSelectedMutation] = useState<MutationData | null>(null);
    const [studentComboboxOpen, setStudentComboboxOpen] = useState(false);

    // Form for creating mutation
    const createForm = useForm({
        type: 'out' as 'in' | 'out',
        student_id: '',
        name: '',
        nisn: '',
        gender: '',
        origin_school: '',
        destination_school: '',
        mutation_date: new Date().toISOString().split('T')[0],
        reason: '',
    });

    // Form for uploading document
    const uploadForm = useForm({
        document: null as File | null,
    });

    // Form for editing mutation
    const editForm = useForm({
        mutation_date: '',
        reason: '',
        origin_school: '',
        destination_school: '',
    });

    const openEditModal = (mutation: MutationData) => {
        setSelectedMutation(mutation);
        editForm.setData({
            mutation_date: mutation.mutation_date,
            reason: mutation.reason || '',
            origin_school: mutation.origin_school || '',
            destination_school: mutation.destination_school || '',
        });
        setIsEditModalOpen(true);
    };

    const handleCreateSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post('/siswa/mutasi', {
            onSuccess: () => {
                setIsCreateModalOpen(false);
                createForm.reset();
                toast.success('Registrasi mutasi berhasil dibuat!');
            },
            onError: (err) => {
                toast.error(err.error || 'Terjadi kesalahan saat registrasi.');
            }
        });
    };

    const handleEditSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedMutation) return;

        editForm.put(`/siswa/mutasi/${selectedMutation.id}`, {
            onSuccess: () => {
                setIsEditModalOpen(false);
                setSelectedMutation(null);
                toast.success('Data mutasi berhasil diperbarui!');
            },
            onError: (err) => {
                toast.error(err.error || 'Terjadi kesalahan saat memperbarui mutasi.');
            }
        });
    };

    const handleDelete = (id: number) => {
        if (confirm('Apakah Anda yakin ingin menghapus mutasi ini?')) {
            router.delete(`/siswa/mutasi/${id}`, {
                onSuccess: () => toast.success('Data mutasi berhasil dihapus!'),
                onError: (err) => toast.error(err.error || 'Terjadi kesalahan saat menghapus mutasi.')
            });
        }
    };

    const handleUploadSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedMutation) return;
        
        uploadForm.post(`/siswa/mutasi/${selectedMutation.id}/upload`, {
            onSuccess: () => {
                setIsUploadModalOpen(false);
                uploadForm.reset();
                setSelectedMutation(null);
                toast.success('Dokumen mutasi berhasil diunggah!');
            },
            onError: (err) => {
                toast.error(err.error || 'Gagal mengunggah dokumen.');
            }
        });
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/siswa/mutasi', { search, type }, { preserveState: true });
    };

    const handleSync = () => {
        setIsSyncing(true);
        router.post('/siswa/mutasi/sync', {}, {
            preserveScroll: true,
            onSuccess: () => setIsSyncing(true),
            onError: () => setIsSyncing(false)
        });
    };

    useEffect(() => {
        let interval: NodeJS.Timeout;
        if (isSyncing || flash.success?.includes('latar belakang')) {
            setIsSyncing(true);
            interval = setInterval(async () => {
                try {
                    const res = await fetch('/siswa/mutasi/progress');
                    const data = await res.json();
                    if (data && data.progress) {
                        setProgressStatus(data.status);
                        if (data.progress >= 100) {
                            setIsSyncing(false);
                            clearInterval(interval);
                            router.reload({ only: ['mutations'] });
                        }
                    }
                } catch (e) {
                    // Ignore errors
                }
            }, 3000);
        }
        return () => clearInterval(interval);
    }, [isSyncing, flash.success]);

    return (
        <AppLayout
            title="Register Mutasi Siswa"
        >
            <Head title="Mutasi Siswa" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Register Mutasi Siswa</h1>
                        <p className="text-sm text-slate-500">
                            Kelola mutasi masuk dan mutasi keluar siswa secara administratif.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Button onClick={() => setIsCreateModalOpen(true)} className="gap-2 bg-blue-600 hover:bg-blue-700 text-white">
                            <Plus className="w-4 h-4" />
                            Buat Mutasi Baru
                        </Button>
                        <Button onClick={handleSync} disabled={isSyncing} variant="outline" className="gap-2">
                            {isSyncing ? <Loader2 className="w-4 h-4 animate-spin" /> : <DownloadCloud className="w-4 h-4" />}
                            Tarik EMIS
                        </Button>
                    </div>
                </div>

                {isSyncing && (
                    <div className="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded flex items-center gap-3">
                        <Loader2 className="w-5 h-5 animate-spin text-blue-500" />
                        <div>
                            <div className="font-medium">Sinkronisasi Sedang Berjalan</div>
                            <div className="text-sm opacity-80">{progressStatus || 'Mohon tunggu...'}</div>
                        </div>
                    </div>
                )}

                <div className="flex flex-col sm:flex-row gap-4 items-center justify-between">
                    <form onSubmit={handleSearch} className="flex gap-4 w-full sm:w-auto">
                        <div className="relative w-full sm:w-64">
                            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-gray-500" />
                            <Input
                                type="text"
                                placeholder="Cari nama atau NISN..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-9"
                            />
                        </div>
                        <div className="w-40 sm:w-48">
                            <Select value={type} onValueChange={(val) => { setType(val); router.get('/siswa/mutasi', { search, type: val }, { preserveState: true }) }}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Semua Tipe" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua Tipe</SelectItem>
                                    <SelectItem value="in">Mutasi Masuk</SelectItem>
                                    <SelectItem value="out">Mutasi Keluar</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="submit" variant="secondary">Cari</Button>
                    </form>
                </div>

                <div className="rounded-md border bg-white">
                    <Table>
                                    <TableHeader>
                                        <TableRow className="bg-gray-50/50">
                                            <TableHead>Siswa</TableHead>
                                            <TableHead>Tipe</TableHead>
                                            <TableHead>Tanggal</TableHead>
                                            <TableHead>Asal / Tujuan</TableHead>
                                            <TableHead>Alasan</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Aksi</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {mutations.data.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center py-8 text-gray-500">
                                                    Belum ada data mutasi. Silakan klik Tarik Data EMIS.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            mutations.data.map((item) => (
                                                <TableRow key={item.id}>
                                                    <TableCell>
                                                        <div className="font-medium">{item.name}</div>
                                                        <div className="text-xs text-gray-500">{item.nisn}</div>
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.type === 'in' ? (
                                                            <Badge className="bg-emerald-100 text-emerald-800 hover:bg-emerald-100 border-none gap-1">
                                                                <ArrowRightLeft className="w-3 h-3" /> Masuk
                                                            </Badge>
                                                        ) : (
                                                            <Badge className="bg-rose-100 text-rose-800 hover:bg-rose-100 border-none gap-1">
                                                                <ArrowRightLeft className="w-3 h-3" /> Keluar
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.mutation_date 
                                                            ? new Date(item.mutation_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) 
                                                            : '-'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.type === 'in' ? (
                                                            <div className="text-sm">
                                                                <span className="text-gray-500 text-xs block">Asal Sekolah:</span>
                                                                {item.origin_school || '-'}
                                                            </div>
                                                        ) : (
                                                            <div className="text-sm">
                                                                <span className="text-gray-500 text-xs block">Tujuan Sekolah:</span>
                                                                {item.destination_school || '-'}
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="max-w-[200px] truncate" title={item.reason}>
                                                        {item.reason || '-'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.status === 'pending' ? (
                                                            <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200">
                                                                Pending Dokumen
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200">
                                                                Selesai
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            {item.status === 'pending' && (
                                                                <>
                                                                    <Button 
                                                                        variant="outline" 
                                                                        size="icon"
                                                                        className="h-8 w-8 text-blue-600 border-blue-200 hover:bg-blue-50"
                                                                        onClick={() => {
                                                                            setSelectedMutation(item);
                                                                            setIsUploadModalOpen(true);
                                                                        }}
                                                                        title="Upload Bukti"
                                                                    >
                                                                        <Upload className="w-4 h-4" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        className="h-8 w-8 text-amber-600 border-amber-200 hover:bg-amber-50"
                                                                        onClick={() => openEditModal(item)}
                                                                        title="Edit"
                                                                    >
                                                                        <Edit className="w-4 h-4" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        className="h-8 w-8 text-red-600 border-red-200 hover:bg-red-50"
                                                                        onClick={() => handleDelete(item.id)}
                                                                        title="Hapus"
                                                                    >
                                                                        <Trash2 className="w-4 h-4" />
                                                                    </Button>
                                                                </>
                                                            )}
                                                            {item.status === 'completed' && item.document_path && (
                                                                <>
                                                                    <div className="flex items-center gap-2 mr-2">
                                                                        {item.document_status === 'failed' ? (
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="text-xs text-red-500 font-medium">Gagal Upload</span>
                                                                                <a href={`/archives/retry/mutation/${item.id}`} className="text-xs px-2 py-1 bg-red-100 text-red-600 hover:bg-red-200 rounded font-medium">Coba Lagi</a>
                                                                            </div>
                                                                        ) : item.document_status === 'pending' ? (
                                                                            <span className="text-xs text-slate-500 flex items-center gap-1 italic">
                                                                                <Loader2 className="h-3 w-3 animate-spin" /> Uploading...
                                                                            </span>
                                                                        ) : (
                                                                            <div className="flex items-center gap-1">
                                                                                <a href={item.document_path.startsWith('archives/') ? `/${item.document_path}` : `/storage/${item.document_path}`} target="_blank" rel="noreferrer" className="text-sm text-blue-600 hover:underline flex items-center gap-1">
                                                                                    <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                                                                                    Lokal
                                                                                </a>
                                                                                {item.gdrive_view_url && (
                                                                                    <a href={item.gdrive_view_url} target="_blank" rel="noreferrer" className="text-sm text-blue-600 hover:underline flex items-center gap-1" title="Lihat di Google Drive">
                                                                                        <ExternalLink className="w-3.5 h-3.5 text-blue-600" />
                                                                                    </a>
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        className="h-8 w-8 text-amber-600 border-amber-200 hover:bg-amber-50"
                                                                        onClick={() => openEditModal(item)}
                                                                        title="Edit"
                                                                    >
                                                                        <Edit className="w-4 h-4" />
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                    </Table>
                </div>

                {/* Pagination Simple */}
                {mutations.links && mutations.links.length > 3 && (
                    <div className="mt-4 flex flex-col sm:flex-row justify-between items-center text-sm text-slate-600 gap-4 bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                        <div>
                            Menampilkan {mutations.from} s/d {mutations.to} dari {mutations.total} data
                        </div>
                        <div className="flex flex-wrap gap-1">
                            {mutations.links.map((link: any, i: number) => (
                                <Link 
                                    key={i} 
                                    href={link.url || '#'}
                                    className={`px-3 py-1 border rounded ${link.active ? 'bg-lime-600 text-white border-lime-600' : 'bg-white hover:bg-slate-50'} ${!link.url ? 'opacity-50 cursor-not-allowed' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                    preserveState
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Modal Create Mutation */}
            <Dialog open={isCreateModalOpen} onOpenChange={setIsCreateModalOpen}>
                <DialogContent className="sm:max-w-[500px]">
                    <form onSubmit={handleCreateSubmit}>
                        <DialogHeader>
                            <DialogTitle>Register Mutasi Baru</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <div className="grid grid-cols-4 items-center gap-4">
                                <Label className="text-right">Tipe Mutasi</Label>
                                <Select 
                                    value={createForm.data.type} 
                                    onValueChange={(val: 'in'|'out') => createForm.setData('type', val)}
                                >
                                    <SelectTrigger className="col-span-3">
                                        <SelectValue placeholder="Pilih Tipe Mutasi" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="out">Mutasi Keluar</SelectItem>
                                        <SelectItem value="in">Mutasi Masuk</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {createForm.data.type === 'out' && (
                                <>
                                    <div className="grid grid-cols-4 items-start gap-4 pt-2">
                                        <Label className="text-right mt-2.5">Siswa Aktif</Label>
                                        <div className="col-span-3">
                                            <Popover open={studentComboboxOpen} onOpenChange={setStudentComboboxOpen}>
                                                <PopoverTrigger render={
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        role="combobox"
                                                        aria-expanded={studentComboboxOpen}
                                                        className="w-full justify-between"
                                                    />
                                                }>
                                                        {createForm.data.student_id
                                                            ? (() => {
                                                                const s = students.find((st) => st.id.toString() === createForm.data.student_id);
                                                                return s ? `${s.name} (${s.nisn || '-'})` : "Pilih Siswa...";
                                                            })()
                                                            : "Cari nama atau NISN..."}
                                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                </PopoverTrigger>
                                                <PopoverContent className="w-[340px] p-0" align="start">
                                                    <Command>
                                                        <CommandInput placeholder="Ketik nama / NISN..." />
                                                        <CommandList>
                                                            <CommandEmpty>Siswa tidak ditemukan.</CommandEmpty>
                                                            <CommandGroup>
                                                                {students?.map((s) => (
                                                                    <CommandItem
                                                                        key={s.id}
                                                                        value={`${s.name} ${s.nisn}`}
                                                                        onSelect={() => {
                                                                            createForm.setData('student_id', s.id.toString());
                                                                            setStudentComboboxOpen(false);
                                                                        }}
                                                                    >
                                                                        <Check
                                                                            className={cn(
                                                                                "mr-2 h-4 w-4",
                                                                                createForm.data.student_id === s.id.toString() ? "opacity-100" : "opacity-0"
                                                                            )}
                                                                        />
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">{s.name}</span>
                                                                            <span className="text-xs text-gray-500">NISN: {s.nisn || '-'}</span>
                                                                        </div>
                                                                    </CommandItem>
                                                                ))}
                                                            </CommandGroup>
                                                        </CommandList>
                                                    </Command>
                                                </PopoverContent>
                                            </Popover>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-4 items-center gap-4">
                                        <Label className="text-right">Sekolah Tujuan</Label>
                                        <Input 
                                            className="col-span-3"
                                            value={createForm.data.destination_school}
                                            onChange={e => createForm.setData('destination_school', e.target.value)}
                                            placeholder="Contoh: MTsN 1 Jakarta"
                                            required
                                        />
                                    </div>
                                </>
                            )}

                            {createForm.data.type === 'in' && (
                                <>
                                    <div className="grid grid-cols-4 items-center gap-4">
                                        <Label className="text-right">Nama Siswa</Label>
                                        <Input 
                                            className="col-span-3"
                                            value={createForm.data.name}
                                            onChange={e => createForm.setData('name', e.target.value)}
                                            placeholder="Nama lengkap siswa baru"
                                            required
                                        />
                                    </div>
                                    <div className="grid grid-cols-4 items-center gap-4">
                                        <Label className="text-right">NISN</Label>
                                        <Input 
                                            className="col-span-3"
                                            value={createForm.data.nisn}
                                            onChange={e => createForm.setData('nisn', e.target.value)}
                                            placeholder="NISN siswa"
                                        />
                                    </div>
                                    <div className="grid grid-cols-4 items-center gap-4">
                                        <Label className="text-right">Jenis Kelamin</Label>
                                        <Select 
                                            value={createForm.data.gender} 
                                            onValueChange={(val) => createForm.setData('gender', val)}
                                        >
                                            <SelectTrigger className="col-span-3">
                                                <SelectValue placeholder="Pilih Jenis Kelamin" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="L">Laki-laki</SelectItem>
                                                <SelectItem value="P">Perempuan</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid grid-cols-4 items-center gap-4">
                                        <Label className="text-right">Sekolah Asal</Label>
                                        <Input 
                                            className="col-span-3"
                                            value={createForm.data.origin_school}
                                            onChange={e => createForm.setData('origin_school', e.target.value)}
                                            placeholder="Contoh: SMPN 2 Bandung"
                                            required
                                        />
                                    </div>
                                </>
                            )}

                            <div className="grid grid-cols-4 items-center gap-4">
                                <Label className="text-right">Tanggal</Label>
                                <Input 
                                    type="date"
                                    className="col-span-3"
                                    value={createForm.data.mutation_date}
                                    onChange={e => createForm.setData('mutation_date', e.target.value)}
                                    required
                                />
                            </div>
                            
                            <div className="grid grid-cols-4 items-center gap-4">
                                <Label className="text-right">Alasan</Label>
                                <Input 
                                    className="col-span-3"
                                    value={createForm.data.reason}
                                    onChange={e => createForm.setData('reason', e.target.value)}
                                    placeholder="Alasan mutasi (opsional)"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsCreateModalOpen(false)}>Batal</Button>
                            <Button type="submit" disabled={createForm.processing}>
                                {createForm.processing ? 'Menyimpan...' : 'Register Mutasi'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal Upload Document */}
            <Dialog open={isUploadModalOpen} onOpenChange={setIsUploadModalOpen}>
                <DialogContent className="sm:max-w-[425px]">
                    <form onSubmit={handleUploadSubmit}>
                        <DialogHeader>
                            <DialogTitle>Upload Bukti Mutasi</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <div className="text-sm text-gray-500 mb-2">
                                Silakan unggah <b>{selectedMutation?.type === 'out' ? 'Surat Keterangan Diterima' : 'Surat Pindah/Keluar'}</b> untuk menyelesaikan proses mutasi.
                            </div>
                            <div className="grid gap-2">
                                <Label>File Dokumen (PDF, JPG, PNG. Max: 1MB)</Label>
                                <Input 
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    onChange={e => uploadForm.setData('document', e.target.files?.[0] || null)}
                                    required
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsUploadModalOpen(false)}>Batal</Button>
                            <Button type="submit" disabled={uploadForm.processing || !uploadForm.data.document}>
                                {uploadForm.processing ? 'Mengunggah...' : 'Upload & Selesaikan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            {/* Edit Mutation Modal */}
            <Dialog open={isEditModalOpen} onOpenChange={setIsEditModalOpen}>
                <DialogContent>
                    <form onSubmit={handleEditSubmit}>
                        <DialogHeader>
                            <DialogTitle>Edit Data Mutasi</DialogTitle>
                        </DialogHeader>
                        
                        <div className="grid gap-4 py-4">
                            <div className="grid grid-cols-4 items-center gap-4">
                                <Label className="text-right">Tgl Mutasi</Label>
                                <Input 
                                    className="col-span-3"
                                    type="date"
                                    value={editForm.data.mutation_date}
                                    onChange={e => editForm.setData('mutation_date', e.target.value)}
                                    required
                                />
                            </div>
                            {selectedMutation?.type === 'out' ? (
                                <div className="grid grid-cols-4 items-center gap-4">
                                    <Label className="text-right">Sekolah Tujuan</Label>
                                    <Input 
                                        className="col-span-3"
                                        value={editForm.data.destination_school}
                                        onChange={e => editForm.setData('destination_school', e.target.value)}
                                        required
                                    />
                                </div>
                            ) : (
                                <div className="grid grid-cols-4 items-center gap-4">
                                    <Label className="text-right">Sekolah Asal</Label>
                                    <Input 
                                        className="col-span-3"
                                        value={editForm.data.origin_school}
                                        onChange={e => editForm.setData('origin_school', e.target.value)}
                                        required
                                    />
                                </div>
                            )}
                            <div className="grid grid-cols-4 items-center gap-4">
                                <Label className="text-right">Alasan</Label>
                                <Input 
                                    className="col-span-3"
                                    value={editForm.data.reason}
                                    onChange={e => editForm.setData('reason', e.target.value)}
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsEditModalOpen(false)}>Batal</Button>
                            <Button type="submit" disabled={editForm.processing}>
                                {editForm.processing ? 'Menyimpan...' : 'Simpan Perubahan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
