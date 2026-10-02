import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Search, UserCheck, UserX } from 'lucide-react';

interface Staff {
    id: string;
    nip: string | null;
    nik: string;
    full_name_with_titles: string;
    employment_status: string;
    functional_position: string | null;
    is_active: boolean;
}

interface PaginationProps {
    data: Staff[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    from: number;
    to: number;
    total: number;
}

export default function Index({ staff, filters }: { staff: PaginationProps, filters: any }) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/gtk', { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout title="Master Data GTK">
            <Head title="Master Data GTK" />

            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-800">Master Data GTK</h1>
                    <p className="text-slate-500">Kelola data Guru dan Tenaga Kependidikan</p>
                </div>
            </div>

            <Card className="border-t-4 border-t-lime-500">
                <CardHeader>
                    <CardTitle className="flex justify-between items-center text-lg">
                        <span>Daftar Pegawai</span>
                        <form onSubmit={handleSearch} className="flex gap-2 w-full md:w-auto">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                <Input 
                                    placeholder="Cari nama, NIK, NIP..." 
                                    className="pl-9 w-full md:w-64"
                                    value={search}
                                    onChange={e => setSearch(e.target.value)}
                                />
                            </div>
                            <Button type="submit" variant="secondary">Cari</Button>
                        </form>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nama Lengkap</TableHead>
                                    <TableHead>NIP / NIK</TableHead>
                                    <TableHead>Status Pegawai</TableHead>
                                    <TableHead>Jabatan</TableHead>
                                    <TableHead>Status Aktif</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {staff.data.length > 0 ? (
                                    staff.data.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="font-medium">
                                                {item.full_name_with_titles}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    {item.nip && <span className="text-sm font-semibold">{item.nip} (NIP)</span>}
                                                    <span className="text-xs text-slate-500">{item.nik} (NIK)</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className="uppercase">
                                                    {item.employment_status || '-'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {item.functional_position || '-'}
                                            </TableCell>
                                            <TableCell>
                                                {item.is_active ? (
                                                    <Badge className="bg-green-100 text-green-700 hover:bg-green-200 border-green-200">
                                                        <UserCheck className="w-3 h-3 mr-1" /> Aktif
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary" className="bg-slate-100 text-slate-600">
                                                        <UserX className="w-3 h-3 mr-1" /> Nonaktif
                                                    </Badge>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center py-8 text-slate-500">
                                            Tidak ada data pegawai yang ditemukan.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination Simple */}
                    {staff.links && staff.links.length > 3 && (
                        <div className="mt-4 flex flex-col md:flex-row justify-between items-center text-sm text-slate-600 gap-4">
                            <div>
                                Menampilkan {staff.from} s/d {staff.to} dari {staff.total} data
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {staff.links.map((link, i) => (
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
        </AppLayout>
    );
}
