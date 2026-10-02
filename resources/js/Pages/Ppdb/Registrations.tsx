import React, { useState, useRef, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Users, Search, Plus, Edit, Trash2, FileCheck2, Clock,
    GraduationCap, ShieldCheck, MoreHorizontal, Phone
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Registration {
    id: string;
    ppdb_period_id?: string;
    registration_number: string;
    name: string;
    nisn?: string;
    nik?: string;
    gender: 'L' | 'P';
    birth_place: string;
    birth_date: string;
    phone_number?: string;
    prev_school_name?: string;
    father_phone?: string;
    mother_phone?: string;
    status: 'registered' | 're_enrolled' | 'verified' | 'placed';
    created_at: string;
    period?: {
        id: string;
        name: string;
    };
    converted_student?: {
        id: string;
        name: string;
    };
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number;
    to: number;
    links: {
        url: string | null;
        label: string;
        active: boolean;
    }[];
}

interface Period {
    id: string;
    name: string;
    is_active: boolean;
}

interface Props {
    registrations: PaginatedData<Registration>;
    counts: {
        all: number;
        registered: number;
        re_enrolled: number;
        verified: number;
        placed: number;
    };
    periods: Period[];
    activePeriodId?: string;
    filters: {
        status?: string;
        search?: string;
        period_id?: string;
    };
}

const STATUS_CONFIG = {
    registered: {
        label: 'Registered',
        badge: 'bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800',
        dot: 'bg-sky-500',
    },
    re_enrolled: {
        label: 'Daftar Ulang',
        badge: 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
        dot: 'bg-amber-500',
    },
    verified: {
        label: 'Terverifikasi',
        badge: 'bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800',
        dot: 'bg-indigo-500',
    },
    placed: {
        label: 'Ditempatkan',
        badge: 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
        dot: 'bg-emerald-500',
    },
};

export default function RegistrationsIndex({ registrations, counts, periods, activePeriodId, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'all');
    const [selectedPeriod, setSelectedPeriod] = useState(activePeriodId || 'all');
    const [deletingId, setDeletingId] = useState<string | null>(null);
    const [openMenuId, setOpenMenuId] = useState<string | null>(null);
    const [menuPos, setMenuPos] = useState<{ top: number; left: number }>({ top: 0, left: 0 });
    const menuRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                setOpenMenuId(null);
            }
        };
        if (openMenuId) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [openMenuId]);

    const toggleMenu = (id: string, e: React.MouseEvent) => {
        if (openMenuId === id) {
            setOpenMenuId(null);
            return;
        }
        const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const menuHeight = 260;
        const spaceBelow = window.innerHeight - rect.bottom;
        const top = spaceBelow < menuHeight ? rect.top - menuHeight : rect.bottom;
        const left = Math.min(rect.left, window.innerWidth - 200);
        setMenuPos({ top, left });
        setOpenMenuId(id);
    };

    const applyFilters = (newParams: Partial<typeof filters>) => {
        router.get(
            '/ppdb/registrations',
            {
                search: newParams.search !== undefined ? newParams.search : search,
                status: newParams.status !== undefined ? newParams.status : selectedStatus,
                period_id: newParams.period_id !== undefined ? newParams.period_id : selectedPeriod,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const handleStatusTab = (status: string) => {
        setSelectedStatus(status);
        applyFilters({ status });
    };

    const handlePeriodChange = (periodId: string | null) => {
        const val = periodId || 'all';
        setSelectedPeriod(val);
        applyFilters({ period_id: val });
    };

    const handleUpdateStatus = (id: string, newStatus: string) => {
        router.put(`/ppdb/registrations/${id}/status`, { status: newStatus }, {
            preserveScroll: true,
        });
    };

    const handleDelete = (id: string) => {
        if (window.confirm('Apakah Anda yakin ingin menghapus pendaftar ini? Tindakan ini tidak dapat dibatalkan.')) {
            router.delete(`/ppdb/registrations/${id}`, {
                preserveScroll: true,
                onFinish: () => setDeletingId(null),
            });
        }
    };

    return (
        <AppLayout>
            <Head title="Daftar Pendaftar PPDB" />

            <div className="space-y-6">
                {/* Header Title & Actions */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight text-foreground">
                                Data Pendaftar PPDB
                            </h1>
                            <Badge variant="outline" className="text-xs">
                                {registrations.total} Calon Siswa
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground mt-1">
                            Kelola calon siswa, status daftar ulang, verifikasi berkas, dan persiapan penempatan kelas.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link href={'/ppdb/placement'}>
                            <Button variant="outline" size="sm" className="gap-1.5 border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300">
                                <GraduationCap className="h-4 w-4" />
                                Penempatan Rombel
                            </Button>
                        </Link>
                        <Link href={'/ppdb/registrations/create'}>
                            <Button size="sm" className="gap-1.5 shadow-sm">
                                <Plus className="h-4 w-4" />
                                Input Pendaftar
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Filter Status Tabs */}
                <div className="flex items-center gap-1.5 overflow-x-auto pb-1 border-b border-border/60">
                    <button
                        onClick={() => handleStatusTab('all')}
                        className={`px-3.5 py-2 rounded-t-lg text-xs font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 border-b-2 -mb-[1px] ${
                            selectedStatus === 'all'
                                ? 'border-primary text-primary bg-primary/5 font-semibold'
                                : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/40'
                        }`}
                    >
                        Semua Status
                        <Badge variant="secondary" className="text-[10px] h-4 px-1.5 ml-0.5">
                            {counts.all}
                        </Badge>
                    </button>

                    <button
                        onClick={() => handleStatusTab('registered')}
                        className={`px-3.5 py-2 rounded-t-lg text-xs font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 border-b-2 -mb-[1px] ${
                            selectedStatus === 'registered'
                                ? 'border-sky-600 text-sky-700 dark:text-sky-300 bg-sky-50 dark:bg-sky-950/30 font-semibold'
                                : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/40'
                        }`}
                    >
                        <Clock className="h-3.5 w-3.5 text-sky-600" />
                        1. Registered
                        <Badge variant="secondary" className="text-[10px] h-4 px-1.5 ml-0.5">
                            {counts.registered}
                        </Badge>
                    </button>

                    <button
                        onClick={() => handleStatusTab('re_enrolled')}
                        className={`px-3.5 py-2 rounded-t-lg text-xs font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 border-b-2 -mb-[1px] ${
                            selectedStatus === 're_enrolled'
                                ? 'border-amber-600 text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/30 font-semibold'
                                : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/40'
                        }`}
                    >
                        <FileCheck2 className="h-3.5 w-3.5 text-amber-600" />
                        2. Daftar Ulang
                        <Badge variant="secondary" className="text-[10px] h-4 px-1.5 ml-0.5">
                            {counts.re_enrolled}
                        </Badge>
                    </button>

                    <button
                        onClick={() => handleStatusTab('verified')}
                        className={`px-3.5 py-2 rounded-t-lg text-xs font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 border-b-2 -mb-[1px] ${
                            selectedStatus === 'verified'
                                ? 'border-indigo-600 text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/30 font-semibold'
                                : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/40'
                        }`}
                    >
                        <ShieldCheck className="h-3.5 w-3.5 text-indigo-600" />
                        3. Terverifikasi
                        <Badge variant="secondary" className="text-[10px] h-4 px-1.5 ml-0.5">
                            {counts.verified}
                        </Badge>
                    </button>
                </div>

                {/* Filter & Search Bar */}
                <div className="flex flex-col sm:flex-row gap-3">
                    <form onSubmit={handleSearchSubmit} className="flex-1 flex gap-2">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Cari nama siswa, no pendaftaran, NISN, NIK, atau asal sekolah..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="pl-9 text-sm"
                            />
                        </div>
                        <Button type="submit" variant="secondary" size="sm" className="px-4">
                            Cari
                        </Button>
                    </form>

                    <div className="w-full sm:w-64 shrink-0">
                        <Select value={selectedPeriod} onValueChange={handlePeriodChange}>
                            <SelectTrigger className="text-xs h-9">
                                <SelectValue placeholder="Pilih Periode PPDB" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua Periode</SelectItem>
                                {periods.map((p) => (
                                    <SelectItem key={p.id} value={p.id}>
                                        {p.name} {p.is_active && '(Aktif)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Tabel Pendaftar */}
                <Card className="shadow-sm overflow-hidden border-border/80">
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50 text-xs font-semibold text-muted-foreground border-b border-border/60">
                                    <tr>
                                        <th className="py-3 px-4">No. Pendaftaran</th>
                                        <th className="py-3 px-4">Nama Calon Siswa</th>
                                        <th className="py-3 px-4">NISN / NIK</th>
                                        <th className="py-3 px-4">L/P</th>
                                        <th className="py-3 px-4">Asal Sekolah</th>
                                        <th className="py-3 px-4">Kontak</th>
                                        <th className="py-3 px-4">Status</th>
                                        <th className="py-3 px-4 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/60">
                                    {registrations.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-12 text-center text-muted-foreground">
                                                <Users className="h-8 w-8 mx-auto mb-2 text-muted-foreground/40" />
                                                <p className="font-medium text-sm">Tidak ada data pendaftar</p>
                                                <p className="text-xs text-muted-foreground/80 mt-1">
                                                    Silakan sesuaikan filter atau tambahkan pendaftar baru.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        registrations.data.map((reg) => {
                                            const statusInfo = STATUS_CONFIG[reg.status] || STATUS_CONFIG.registered;
                                            const contactPhone = reg.phone_number || reg.father_phone || reg.mother_phone || '-';

                                            return (
                                                <tr key={reg.id} className="hover:bg-muted/30 transition-colors">
                                                    <td className="py-3 px-4 whitespace-nowrap">
                                                        <span className="font-mono text-xs font-semibold bg-muted px-2 py-1 rounded text-foreground">
                                                            {reg.registration_number}
                                                        </span>
                                                        <p className="text-[10px] text-muted-foreground mt-0.5">
                                                            {new Date(reg.created_at).toLocaleDateString('id-ID', {
                                                                day: 'numeric',
                                                                month: 'short',
                                                            })}
                                                        </p>
                                                    </td>

                                                    <td className="py-3 px-4">
                                                        <Link
                                                            href={`/ppdb/registrations/${reg.id}`}
                                                            className="font-semibold text-foreground hover:text-primary transition-colors block"
                                                        >
                                                            {reg.name}
                                                        </Link>
                                                        <p className="text-xs text-muted-foreground">
                                                            {reg.birth_place},{' '}
                                                            {reg.birth_date
                                                                ? new Date(reg.birth_date).toLocaleDateString('id-ID', {
                                                                      day: 'numeric',
                                                                      month: 'short',
                                                                      year: 'numeric',
                                                                  })
                                                                : '-'}
                                                        </p>
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap text-xs text-muted-foreground font-mono">
                                                        <div>NISN: {reg.nisn || '-'}</div>
                                                        <div className="text-[11px] text-muted-foreground/70">NIK: {reg.nik || '-'}</div>
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap">
                                                        <Badge
                                                            variant="outline"
                                                            className={`text-[11px] font-bold ${
                                                                reg.gender === 'L'
                                                                    ? 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40'
                                                                    : 'bg-pink-50 text-pink-700 border-pink-200 dark:bg-pink-950/40'
                                                            }`}
                                                        >
                                                            {reg.gender}
                                                        </Badge>
                                                    </td>

                                                    <td className="py-3 px-4 text-xs text-foreground max-w-[180px] truncate">
                                                        {reg.prev_school_name || (
                                                            <span className="text-muted-foreground italic">-</span>
                                                        )}
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap text-xs text-muted-foreground">
                                                        <div className="flex items-center gap-1.5">
                                                            <Phone className="h-3 w-3 text-muted-foreground/60" />
                                                            <span>{contactPhone}</span>
                                                        </div>
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap">
                                                        <Badge
                                                            variant="outline"
                                                            className={`text-xs font-medium px-2.5 py-0.5 border ${statusInfo.badge}`}
                                                        >
                                                            <span className={`w-1.5 h-1.5 rounded-full mr-1.5 ${statusInfo.dot}`} />
                                                            {statusInfo.label}
                                                        </Badge>
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap text-right">
                                                        <div className="flex items-center justify-end gap-1.5">
                                                            <Link href={`/ppdb/registrations/${reg.id}`}>
                                                                <Button variant="outline" size="sm" className="h-8 px-2.5 text-xs">
                                                                    Detail
                                                                </Button>
                                                            </Link>

                                                            <div ref={openMenuId === reg.id ? menuRef : undefined}>
                                                                <Button 
                                                                    variant="ghost" 
                                                                    size="sm" 
                                                                    className="h-8 w-8 p-0"
                                                                    onClick={(e) => toggleMenu(reg.id, e)}
                                                                >
                                                                    <MoreHorizontal className="h-4 w-4" />
                                                                </Button>
                                                                {openMenuId === reg.id && (
                                                                    <div 
                                                                        className="fixed z-[9999] w-48 rounded-lg border bg-popover p-1 text-popover-foreground shadow-md"
                                                                        style={{ top: menuPos.top, left: menuPos.left }}
                                                                    >
                                                                        <p className="px-1.5 py-1 text-xs font-medium text-muted-foreground">Ubah Status</p>
                                                                        <button
                                                                            onClick={() => { handleUpdateStatus(reg.id, 'registered'); setOpenMenuId(null); }}
                                                                            disabled={reg.status === 'registered'}
                                                                            className="w-full flex items-center gap-1.5 rounded-md px-1.5 py-1 text-sm hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50 outline-none"
                                                                        >
                                                                            <Clock className="h-3.5 w-3.5 text-sky-600" />
                                                                            1. Registered
                                                                        </button>
                                                                        <button
                                                                            onClick={() => { handleUpdateStatus(reg.id, 're_enrolled'); setOpenMenuId(null); }}
                                                                            disabled={reg.status === 're_enrolled'}
                                                                            className="w-full flex items-center gap-1.5 rounded-md px-1.5 py-1 text-sm hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50 outline-none"
                                                                        >
                                                                            <FileCheck2 className="h-3.5 w-3.5 text-amber-600" />
                                                                            2. Daftar Ulang
                                                                        </button>
                                                                        <button
                                                                            onClick={() => { handleUpdateStatus(reg.id, 'verified'); setOpenMenuId(null); }}
                                                                            disabled={reg.status === 'verified'}
                                                                            className="w-full flex items-center gap-1.5 rounded-md px-1.5 py-1 text-sm hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50 outline-none"
                                                                        >
                                                                            <ShieldCheck className="h-3.5 w-3.5 text-indigo-600" />
                                                                            3. Terverifikasi
                                                                        </button>
                                                                        <div className="my-1 h-px bg-border" />
                                                                        <button
                                                                            onClick={() => { router.get(`/ppdb/registrations/${reg.id}/edit`); setOpenMenuId(null); }}
                                                                            className="w-full flex items-center gap-1.5 rounded-md px-1.5 py-1 text-sm hover:bg-accent hover:text-accent-foreground outline-none"
                                                                        >
                                                                            <Edit className="h-3.5 w-3.5" />
                                                                            Edit Biodata
                                                                        </button>
                                                                        {reg.status !== 'placed' && (
                                                                            <button
                                                                                onClick={() => { handleDelete(reg.id); setOpenMenuId(null); }}
                                                                                className="w-full flex items-center gap-1.5 rounded-md px-1.5 py-1 text-sm text-destructive hover:bg-destructive/10 outline-none"
                                                                            >
                                                                                <Trash2 className="h-3.5 w-3.5" />
                                                                                Hapus Pendaftar
                                                                            </button>
                                                                        )}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination footer */}
                        {registrations.last_page > 1 && (
                            <div className="flex items-center justify-between px-4 py-3 border-t border-border/60 bg-muted/20">
                                <div className="text-xs text-muted-foreground">
                                    Menampilkan <span className="font-semibold">{registrations.from || 0}</span> -{' '}
                                    <span className="font-semibold">{registrations.to || 0}</span> dari{' '}
                                    <span className="font-semibold">{registrations.total}</span> data
                                </div>
                                <div className="flex items-center gap-1">
                                    {registrations.links.map((link, idx) => (
                                        <button
                                            key={idx}
                                            disabled={!link.url || link.active}
                                            onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                                            className={`px-2.5 py-1 text-xs rounded border transition-colors ${
                                                link.active
                                                    ? 'bg-primary text-primary-foreground border-primary font-semibold'
                                                    : link.url
                                                    ? 'bg-background hover:bg-muted border-border text-foreground'
                                                    : 'bg-muted/40 border-transparent text-muted-foreground/50 cursor-not-allowed'
                                            }`}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
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
