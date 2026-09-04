import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Search, Printer, Edit, Eye, Filter } from 'lucide-react';

interface Student {
    id: string;
    nisn: string;
    nik: string;
    name: string;
    gender: string;
    status: string;
    status_label: string;
    current_cohort?: {
        rombel?: {
            name: string;
        }
    }
}

interface PaginationProps {
    data: Student[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    from: number;
    to: number;
    total: number;
}

export default function Index({ students, filters }: { students: PaginationProps, filters: any }) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('students.index'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Data Siswa" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">Data Siswa</h1>
                    <div className="flex gap-2">
                        <Button variant="outline" className="gap-2">
                            <Printer className="h-4 w-4" />
                            Cetak Laporan
                        </Button>
                        <Button className="gap-2 bg-lime-600 hover:bg-lime-700 text-white">
                            Sinkron EMIS
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader className="pb-4">
                        <CardTitle className="text-lg">Daftar Peserta Didik</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col sm:flex-row gap-4 mb-6">
                            <form onSubmit={handleSearch} className="flex flex-1 gap-2">
                                <div className="relative flex-1 max-w-sm">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-500" />
                                    <Input
                                        type="search"
                                        placeholder="Cari NISN, NIK, atau Nama..."
                                        className="pl-9"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                    />
                                </div>
                                <Button type="submit" variant="secondary">Cari</Button>
                            </form>
                            <Button variant="outline" className="gap-2">
                                <Filter className="h-4 w-4" /> Filter
                            </Button>
                        </div>

                        <div className="rounded-md border">
                            <Table>
                                <TableHeader className="bg-slate-50">
                                    <TableRow>
                                        <TableHead>Nama Siswa</TableHead>
                                        <TableHead>NISN / NIK</TableHead>
                                        <TableHead>L/P</TableHead>
                                        <TableHead>Rombel</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Aksi</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {students.data.length > 0 ? (
                                        students.data.map((student) => (
                                            <TableRow key={student.id}>
                                                <TableCell className="font-medium">{student.name}</TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        <span>{student.nisn || '-'}</span>
                                                        <span className="text-xs text-slate-500">{student.nik || '-'}</span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>{student.gender}</TableCell>
                                                <TableCell>
                                                    {student.current_cohort?.rombel?.name || (
                                                        <span className="text-slate-400 italic">Belum masuk kelas</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={student.status === 'active' ? 'default' : 'secondary'}
                                                           className={student.status === 'active' ? 'bg-lime-500 hover:bg-lime-600' : ''}>
                                                        {student.status_label || student.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        <Link href={route('students.show', student.id)}>
                                                            <Button variant="ghost" size="icon" title="Lihat Detail">
                                                                <Eye className="h-4 w-4 text-slate-500" />
                                                            </Button>
                                                        </Link>
                                                        <a href={route('students.print-biodata', student.id)} target="_blank">
                                                            <Button variant="ghost" size="icon" title="Cetak Biodata">
                                                                <Printer className="h-4 w-4 text-slate-500" />
                                                            </Button>
                                                        </a>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-center py-8 text-slate-500">
                                                Tidak ada data siswa ditemukan.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Pagination Simple */}
                        {students.links && students.links.length > 3 && (
                            <div className="mt-4 flex justify-between items-center text-sm text-slate-600">
                                <div>
                                    Menampilkan {students.from} s/d {students.to} dari {students.total} data
                                </div>
                                <div className="flex gap-1">
                                    {students.links.map((link, i) => (
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
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
