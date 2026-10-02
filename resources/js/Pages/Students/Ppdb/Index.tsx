import AppLayout from '@/Layouts/AppLayout';
import { Head, router, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useState } from 'react';
import { Search, UserPlus, CheckCircle, XCircle } from 'lucide-react';
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
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export default function Index({ registrations, filters = {} }: any) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        gender: 'L',
        birth_place: '',
        birth_date: '',
        previous_school: '',
        parent_name: '',
        phone_number: '',
        nisn: '',
        nik: '',
    });

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/siswa/ppdb', { search, status }, { preserveState: true });
    };

    const handleStatusChange = (newStatus: string) => {
        setStatus(newStatus);
        router.get('/siswa/ppdb', { search, status: newStatus }, { preserveState: true });
    };

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        post('/siswa/ppdb', {
            onSuccess: () => {
                setIsCreateModalOpen(false);
                reset();
            }
        });
    };

    const updateStatus = (id: string, newStatus: string) => {
        if (confirm(`Apakah Anda yakin ingin mengubah status menjadi ${newStatus}?`)) {
            router.put(`/siswa/ppdb/${id}/status`, { status: newStatus }, { preserveScroll: true });
        }
    };

    return (
        <AppLayout>
            <Head title="PPDB & Pendaftaran" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">PPDB & Pendaftaran</h1>
                        <p className="text-sm text-slate-500">
                            Kelola penerimaan peserta didik baru (PPDB).
                        </p>
                    </div>
                    <Button onClick={() => setIsCreateModalOpen(true)} className="gap-2 bg-lime-600 hover:bg-lime-700">
                        <UserPlus className="w-4 h-4" />
                        Tambah Pendaftar
                    </Button>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-slate-200">
                    <div className="p-4 border-b border-slate-200 bg-slate-50/50">
                        <div className="flex flex-col sm:flex-row gap-4 justify-between items-center">
                            <div className="flex gap-4 w-full sm:w-auto">
                                <div className="w-40 sm:w-48">
                                    <Select value={status} onValueChange={handleStatusChange}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Semua Status" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Semua Status</SelectItem>
                                            <SelectItem value="pending">Pending</SelectItem>
                                            <SelectItem value="accepted">Diterima</SelectItem>
                                            <SelectItem value="rejected">Ditolak</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <form onSubmit={handleSearch} className="flex gap-2 w-full sm:w-auto">
                                <div className="relative flex-1 sm:w-64">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                    <Input
                                        placeholder="Cari nama atau No. Daftar..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-9"
                                    />
                                </div>
                                <Button type="submit" variant="secondary">Cari</Button>
                            </form>
                        </div>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow className="bg-gray-50/50">
                                <TableHead>No. Daftar</TableHead>
                                <TableHead>Nama Lengkap</TableHead>
                                <TableHead>L/P</TableHead>
                                <TableHead>Asal Sekolah</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {registrations.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center py-8 text-gray-500">
                                        Belum ada pendaftar.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                registrations.data.map((item: any) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium text-lime-700">
                                            {item.registration_number}
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-medium">{item.name}</div>
                                            <div className="text-xs text-gray-500">NISN: {item.nisn || '-'} | NIK: {item.nik || '-'}</div>
                                        </TableCell>
                                        <TableCell>{item.gender}</TableCell>
                                        <TableCell>{item.previous_school || '-'}</TableCell>
                                        <TableCell>
                                            <span className={`px-2 py-1 rounded text-xs font-medium ${
                                                item.status === 'accepted' ? 'bg-green-100 text-green-700' :
                                                item.status === 'rejected' ? 'bg-red-100 text-red-700' :
                                                'bg-yellow-100 text-yellow-700'
                                            }`}>
                                                {item.status === 'accepted' ? 'Diterima' : item.status === 'rejected' ? 'Ditolak' : 'Pending'}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {item.status === 'pending' && (
                                                <div className="flex justify-end gap-2">
                                                    <Button size="sm" variant="outline" className="text-green-600 border-green-200 hover:bg-green-50" onClick={() => updateStatus(item.id, 'accepted')}>
                                                        <CheckCircle className="w-4 h-4 mr-1" /> Terima
                                                    </Button>
                                                    <Button size="sm" variant="outline" className="text-red-600 border-red-200 hover:bg-red-50" onClick={() => updateStatus(item.id, 'rejected')}>
                                                        <XCircle className="w-4 h-4 mr-1" /> Tolak
                                                    </Button>
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Pagination */}
                {registrations.links && registrations.links.length > 3 && (
                    <div className="mt-4 flex flex-col sm:flex-row justify-between items-center text-sm text-slate-600 gap-4 bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                        <div>
                            Menampilkan {registrations.from} s/d {registrations.to} dari {registrations.total} data
                        </div>
                        <div className="flex flex-wrap gap-1">
                            {registrations.links.map((link: any, i: number) => (
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

            {/* Modal Tambah */}
            <Dialog open={isCreateModalOpen} onOpenChange={setIsCreateModalOpen}>
                <DialogContent className="sm:max-w-[600px] max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Tambah Pendaftar Baru</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4 py-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label>Nama Lengkap</Label>
                                <Input value={data.name} onChange={e => setData('name', e.target.value)} required />
                                {errors.name && <div className="text-red-500 text-xs">{errors.name}</div>}
                            </div>
                            <div className="space-y-2">
                                <Label>Jenis Kelamin</Label>
                                <Select value={data.gender} onValueChange={(val) => setData('gender', val)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="L">Laki-laki</SelectItem>
                                        <SelectItem value="P">Perempuan</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label>Tempat Lahir</Label>
                                <Input value={data.birth_place} onChange={e => setData('birth_place', e.target.value)} required />
                            </div>
                            <div className="space-y-2">
                                <Label>Tanggal Lahir</Label>
                                <Input type="date" value={data.birth_date} onChange={e => setData('birth_date', e.target.value)} required />
                            </div>
                            <div className="space-y-2">
                                <Label>NISN</Label>
                                <Input value={data.nisn} onChange={e => setData('nisn', e.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label>NIK</Label>
                                <Input value={data.nik} onChange={e => setData('nik', e.target.value)} />
                            </div>
                            <div className="space-y-2 md:col-span-2">
                                <Label>Asal Sekolah</Label>
                                <Input value={data.previous_school} onChange={e => setData('previous_school', e.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label>Nama Orang Tua</Label>
                                <Input value={data.parent_name} onChange={e => setData('parent_name', e.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label>No. Telepon / WA</Label>
                                <Input value={data.phone_number} onChange={e => setData('phone_number', e.target.value)} />
                            </div>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button type="button" variant="outline" onClick={() => setIsCreateModalOpen(false)}>Batal</Button>
                            <Button type="submit" disabled={processing} className="bg-lime-600 hover:bg-lime-700">Simpan Pendaftar</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
