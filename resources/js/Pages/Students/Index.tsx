import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger, DialogFooter, DialogClose } from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search, Printer, Edit, Eye, Filter, FileText, FileSpreadsheet, RefreshCw } from 'lucide-react';

interface Student {
    id: string;
    nis_local: string;
    nisn: string;
    nik: string;
    name: string;
    gender: string;
    birth_place: string;
    birth_date: string;
    prev_school_name: string;
    status: string;
    status_label: string;
    profile_photo?: string | null;
    data_completeness?: { percentage: number; missing_fields: string[] };
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

function EmisSyncModal({ children }: { children: React.ReactNode }) {
    const [isOpen, setIsOpen] = useState(false);
    const [clearStaging, setClearStaging] = useState(false);
    const [syncProgress, setSyncProgress] = useState<any>(null);
    const [stagingData, setStagingData] = useState<any[]>([]);
    const [isLoadingStaging, setIsLoadingStaging] = useState(false);
    const [isCommitting, setIsCommitting] = useState(false);
    const [isStartingSync, setIsStartingSync] = useState(false);

    React.useEffect(() => {
        let interval: NodeJS.Timeout;
        if (isOpen) {
            const checkStatus = async () => {
                try {
                    const res = await fetch('/api/emis/status');
                    const data = await res.json();
                    
                    if (data.emis_siswa?.progress) {
                        setSyncProgress(data.emis_siswa.progress);
                        setIsStartingSync(false); // job sudah diambil worker
                    } else if (!isStartingSync) {
                        setSyncProgress(null);
                    }

                    if (data.emis_siswa?.progress?.is_staging_done && stagingData.length === 0 && !isLoadingStaging) {
                        setIsLoadingStaging(true);
                        const stageRes = await fetch('/api/emis/staging');
                        const stageData = await stageRes.json();
                        setStagingData(stageData.data || []);
                        setIsLoadingStaging(false);
                    }
                } catch {
                    // polling status: abaikan, coba lagi 2 detik
                }
            };
            checkStatus();
            interval = setInterval(checkStatus, 2000);
        }
        return () => clearInterval(interval);
    }, [isOpen, stagingData.length, isStartingSync]);

    const handleStartSync = async () => {
        setIsStartingSync(true);
        try {
            const pullRes = await fetch('/api/emis/pull', { 
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ clear_staging: clearStaging })
            });
            const pullData = await pullRes.json();
            if (!pullRes.ok) {
                alert(pullData.message || 'Gagal memulai sinkronisasi.');
                setIsStartingSync(false);
            } else {
                setStagingData([]); // reset staging view
            }
        } catch {
            alert('Terjadi kesalahan saat menghubungi server.');
            setIsStartingSync(false);
        }
    };

    const handleCancelSync = async () => {
        try {
            await fetch('/api/emis/cancel-sync', { method: 'POST' });
            setSyncProgress(null);
        } catch {
            // abaikan: pembatalan bersifat best-effort
        }
    };

    const handleCommit = async () => {
        setIsCommitting(true);
        try {
            const res = await fetch('/api/emis/commit', { method: 'POST' });
            const data = await res.json();
            if (res.ok) {
                alert(data.message);
                setIsOpen(false);
                window.location.reload();
            } else {
                alert(data.message || 'Gagal menyimpan.');
            }
        } catch {
            alert('Gagal menyimpan.');
        }
        setIsCommitting(false);
    };

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger render={children as React.ReactElement} />
            <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Sinkronisasi Data EMIS</DialogTitle>
                    <DialogDescription>
                        Tarik data peserta didik terbaru langsung dari sistem EMIS Kemenag.
                    </DialogDescription>
                </DialogHeader>

                {!syncProgress && !isStartingSync ? (
                    <div className="space-y-4 py-4">
                        <div className="bg-amber-50 border border-amber-200 text-amber-800 p-4 rounded-lg text-sm">
                            <h4 className="font-semibold mb-1 flex items-center gap-2">
                                <span className="bg-amber-200 text-amber-900 rounded-full w-5 h-5 flex items-center justify-center text-xs">!</span>
                                Persyaratan Sinkronisasi
                            </h4>
                            <p className="mb-2">Untuk melakukan sinkronisasi, pastikan Anda telah Login ke web EMIS (emis.kemenag.go.id) dan menjalankan <strong>Bookmarklet Pintu Madrasah</strong> di browser tersebut agar token akses dapat dikirimkan ke sistem ini.</p>
                        </div>
                        
                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox 
                                id="clearStaging" 
                                checked={clearStaging}
                                onCheckedChange={(checked) => setClearStaging(checked === true)}
                            />
                            <Label htmlFor="clearStaging" className="text-sm cursor-pointer">
                                Bersihkan (reset) data staging lama sebelum menarik data baru
                            </Label>
                        </div>

                        <DialogFooter className="flex gap-2 sm:justify-end mt-4">
                            <DialogClose render={<Button type="button" variant="outline">Batal</Button>} />
                            <Button className="gap-2 bg-lime-600 hover:bg-lime-700 text-white" onClick={handleStartSync}>
                                <RefreshCw className="h-4 w-4" /> Mulai Sinkronisasi Sekarang
                            </Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <div className="space-y-4 py-4">
                        {isStartingSync && !syncProgress ? (
                            <div className="text-center py-8">
                                <RefreshCw className="h-8 w-8 animate-spin mx-auto text-lime-600 mb-4" />
                                <p className="text-slate-600">Sedang memulai sinkronisasi di latar belakang...</p>
                            </div>
                        ) : (
                            <>
                                <div className="flex justify-between text-sm mb-1">
                                    <span className="font-medium text-slate-700">{syncProgress?.status || 'Memproses...'}</span>
                                    <span>{syncProgress?.progress || 0}%</span>
                                </div>
                                <Progress value={syncProgress?.progress || 0} className="h-2" />
                                
                                {syncProgress?.stats && !syncProgress.is_staging_done && (
                                    <div className="text-sm text-slate-500 mt-2">
                                        Ditarik: {syncProgress.stats.staged || 0} | Gagal: {syncProgress.stats.failed || 0}
                                    </div>
                                )}

                                {!syncProgress?.is_staging_done && (
                                    <div className="flex justify-end mt-4">
                                        <Button variant="destructive" onClick={handleCancelSync}>Batalkan Sinkronisasi</Button>
                                    </div>
                                )}

                                {syncProgress?.is_staging_done && (
                            <div className="mt-6 border-t pt-4">
                                <h4 className="font-semibold mb-2">Review Data Perubahan</h4>
                                {isLoadingStaging ? (
                                    <div className="text-center py-4 text-sm text-slate-500">Memuat data review...</div>
                                ) : (
                                    <>
                                        <div className="max-h-60 overflow-y-auto border rounded-md mb-4">
                                            <Table>
                                                <TableHeader className="bg-slate-50 sticky top-0">
                                                    <TableRow>
                                                        <TableHead>Nama</TableHead>
                                                        <TableHead>NISN</TableHead>
                                                        <TableHead>Rombel</TableHead>
                                                        <TableHead>Status</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {stagingData.length > 0 ? stagingData.map((d: any) => (
                                                        <TableRow key={d.id}>
                                                            <TableCell className="font-medium">{d.name}</TableCell>
                                                            <TableCell>{d.nisn}</TableCell>
                                                            <TableCell>{d.rombel_name}</TableCell>
                                                            <TableCell>
                                                                <Badge variant={d.match_status === 'new' ? 'default' : 'secondary'} className={d.match_status === 'new' ? 'bg-lime-500 hover:bg-lime-600' : ''}>
                                                                    {d.match_status === 'new' ? 'Siswa Baru' : 'Update Data'}
                                                                </Badge>
                                                            </TableCell>
                                                        </TableRow>
                                                    )) : (
                                                        <TableRow>
                                                            <TableCell colSpan={4} className="text-center py-4">Tidak ada data untuk direview.</TableCell>
                                                        </TableRow>
                                                    )}
                                                </TableBody>
                                            </Table>
                                        </div>
                                        <DialogFooter>
                                            <DialogClose render={<Button type="button" variant="outline">Tutup Dulu</Button>} />
                                            <Button className="bg-lime-600 hover:bg-lime-700" onClick={handleCommit} disabled={isCommitting || stagingData.length === 0}>
                                                {isCommitting ? 'Menyimpan...' : 'Simpan Permanen ke Database'}
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </div>
                        )}
                            </>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default function Index({ students, filters, rombels }: { students: PaginationProps, filters: any, rombels: any[] }) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [rombel, setRombel] = useState(filters.rombel || '');
    const [assistance, setAssistance] = useState(filters.assistance || '');
    const [verification, setVerification] = useState(filters.verification || '');
    
    const [showFilters, setShowFilters] = useState(false);
    
    const [selectedStudent, setSelectedStudent] = useState<Student | null>(null);
    const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/students', { search, status, rombel, assistance, verification }, { preserveState: true, replace: true });
    };

    const applyFilter = (key: string, value: string) => {
        const newFilters: any = { search, status, rombel, assistance, verification };
        newFilters[key] = value === 'all' ? '' : value;
        
        // Update local state first
        if (key === 'status') setStatus(newFilters.status);
        if (key === 'rombel') setRombel(newFilters.rombel);
        if (key === 'assistance') setAssistance(newFilters.assistance);
        if (key === 'verification') setVerification(newFilters.verification);
        
        router.get('/students', newFilters, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Data Siswa" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">Data Siswa</h1>
                    <div className="flex gap-2">
                        <Dialog>
                            <DialogTrigger render={<Button variant="outline" className="gap-2" />}>
                                    <Printer className="h-4 w-4" />
                                    Cetak Laporan
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Cetak Laporan Data Siswa</DialogTitle>
                                    <DialogDescription>
                                        Pilih jenis format laporan yang ingin Anda unduh atau cetak.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-4 py-4">
                                    <a href={`/students/export?${new URLSearchParams(filters as any).toString()}`} target="_blank" rel="noopener noreferrer" className="flex items-center gap-4 p-4 border rounded-lg hover:bg-slate-50 transition-colors">
                                        <div className="bg-green-100 p-2 rounded-full">
                                            <FileSpreadsheet className="h-6 w-6 text-green-600" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-slate-900">Format Excel (CSV)</h4>
                                            <p className="text-sm text-slate-500">Unduh data siswa lengkap dalam format Spreadsheet.</p>
                                        </div>
                                    </a>
                                    
                                    <a href={`/students/export-pdf?type=daftar_siswa&${new URLSearchParams(filters as any).toString()}`} target="_blank" rel="noopener noreferrer" className="flex items-center gap-4 p-4 border rounded-lg hover:bg-slate-50 transition-colors">
                                        <div className="bg-red-100 p-2 rounded-full">
                                            <FileText className="h-6 w-6 text-red-600" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-slate-900">PDF: Daftar Siswa</h4>
                                            <p className="text-sm text-slate-500">Cetak lembar daftar siswa format PDF.</p>
                                        </div>
                                    </a>

                                    <a href={`/students/export-pdf?type=daftar_hadir&${new URLSearchParams(filters as any).toString()}`} target="_blank" rel="noopener noreferrer" className="flex items-center gap-4 p-4 border rounded-lg hover:bg-slate-50 transition-colors">
                                        <div className="bg-red-100 p-2 rounded-full">
                                            <FileText className="h-6 w-6 text-red-600" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-slate-900">PDF: Daftar Hadir</h4>
                                            <p className="text-sm text-slate-500">Cetak lembar absensi/kehadiran harian format PDF.</p>
                                        </div>
                                    </a>

                                    <a href={`/students/export-pdf?type=form_nilai&${new URLSearchParams(filters as any).toString()}`} target="_blank" rel="noopener noreferrer" className="flex items-center gap-4 p-4 border rounded-lg hover:bg-slate-50 transition-colors">
                                        <div className="bg-red-100 p-2 rounded-full">
                                            <FileText className="h-6 w-6 text-red-600" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-slate-900">PDF: Form Nilai</h4>
                                            <p className="text-sm text-slate-500">Cetak lembar format penilaian (Tugas, UTS, UAS).</p>
                                        </div>
                                    </a>
                                </div>
                            </DialogContent>
                        </Dialog>
                        <EmisSyncModal>
                            <Button className="gap-2 bg-lime-600 hover:bg-lime-700 text-white">
                                Sinkron EMIS
                            </Button>
                        </EmisSyncModal>
                    </div>
                </div>

                <Card>
                    <CardHeader className="pb-4">
                        <CardTitle className="text-lg">Daftar Peserta Didik</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col mb-6 gap-4">
                            <div className="flex flex-col sm:flex-row gap-4">
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
                                <Button 
                                    variant={showFilters ? 'default' : 'outline'} 
                                    className="gap-2"
                                    onClick={() => setShowFilters(!showFilters)}
                                >
                                    <Filter className="h-4 w-4" /> Filter Spesifik
                                </Button>
                            </div>

                            {/* Additional Filters Panel */}
                            {showFilters && (
                                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-slate-50 border rounded-lg">
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Rombel/Kelas</Label>
                                        <Select value={rombel || 'all'} onValueChange={(val) => applyFilter('rombel', val)}>
                                            <SelectTrigger className="bg-white"><SelectValue placeholder="Semua Rombel" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Semua Rombel</SelectItem>
                                                {rombels.map((r: any) => (
                                                    <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Status Bantuan</Label>
                                        <Select value={assistance || 'all'} onValueChange={(val) => applyFilter('assistance', val)}>
                                            <SelectTrigger className="bg-white"><SelectValue placeholder="Semua Siswa" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Semua Siswa</SelectItem>
                                                <SelectItem value="pip">Penerima PIP</SelectItem>
                                                <SelectItem value="pkh">Penerima PKH</SelectItem>
                                                <SelectItem value="kks">Penerima KKS</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Status Dokumen</Label>
                                        <Select value={verification || 'all'} onValueChange={(val) => applyFilter('verification', val)}>
                                            <SelectTrigger className="bg-white"><SelectValue placeholder="Semua Status" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Semua Status</SelectItem>
                                                <SelectItem value="verified">Verified AI</SelectItem>
                                                <SelectItem value="unverified">Belum Verified</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Status Keaktifan</Label>
                                        <Select value={status || 'all'} onValueChange={(val) => applyFilter('status', val)}>
                                            <SelectTrigger className="bg-white"><SelectValue placeholder="Semua Status" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Semua Status</SelectItem>
                                                <SelectItem value="active">Aktif</SelectItem>
                                                <SelectItem value="graduated">Lulus</SelectItem>
                                                <SelectItem value="moved">Mutasi</SelectItem>
                                                <SelectItem value="dropped_out">Keluar/DO</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="rounded-md border">
                            <Table>
                                <TableHeader className="bg-slate-50">
                                    <TableRow>
                                        <TableHead className="w-12 text-center">No</TableHead>
                                        <TableHead>Kelas</TableHead>
                                        <TableHead>NISM</TableHead>
                                        <TableHead>NISN</TableHead>
                                        <TableHead>Nama Siswa</TableHead>
                                        <TableHead>L/P</TableHead>
                                        <TableHead>NIK</TableHead>
                                        <TableHead>Tempat, Tgl Lahir</TableHead>
                                        <TableHead>Asal SD</TableHead>
                                        <TableHead>Kelengkapan</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Aksi</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {students.data.length > 0 ? (
                                        students.data.map((student, index) => (
                                            <TableRow key={student.id}>
                                                <TableCell className="text-center">{students.from + index}</TableCell>
                                                <TableCell>
                                                    {student.current_cohort?.rombel?.name || (
                                                        <span className="text-slate-400 italic">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>{student.nis_local || '-'}</TableCell>
                                                <TableCell>{student.nisn || '-'}</TableCell>
                                                <TableCell className="font-medium whitespace-nowrap">
                                                    <div className="flex items-center gap-2">
                                                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white overflow-hidden shrink-0 ${student.gender === 'P' ? 'bg-pink-400' : 'bg-blue-400'}`}>
                                                            {student.profile_photo ? (
                                                                <img src={student.profile_photo.startsWith("archives/") ? `/${student.profile_photo}` : `/storage/${student.profile_photo}`} alt={student.name} className="w-full h-full object-cover" />
                                                            ) : (
                                                                student.name?.charAt(0)?.toUpperCase()
                                                            )}
                                                        </div>
                                                        {student.name}
                                                    </div>
                                                </TableCell>
                                                <TableCell>{student.gender}</TableCell>
                                                <TableCell>{student.nik || '-'}</TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {student.birth_place ? `${student.birth_place}, ` : ''}
                                                    {student.birth_date ? new Date(student.birth_date).toLocaleDateString('id-ID', {day: '2-digit', month: '2-digit', year: 'numeric'}) : '-'}
                                                </TableCell>
                                                <TableCell className="truncate max-w-[150px]" title={student.prev_school_name}>
                                                    {student.prev_school_name || '-'}
                                                </TableCell>
                                                <TableCell className="min-w-[110px]">
                                                    {student.data_completeness ? (
                                                        <div className="flex flex-col gap-1">
                                                            <div className="flex items-center justify-between">
                                                                <span className={`text-xs font-semibold ${
                                                                    student.data_completeness.percentage === 100 ? 'text-green-600' :
                                                                    student.data_completeness.percentage >= 60 ? 'text-amber-600' : 'text-red-500'
                                                                }`}>{student.data_completeness.percentage}%</span>
                                                            </div>
                                                            <div className="w-full bg-slate-200 rounded-full h-1.5">
                                                                <div
                                                                    className={`h-1.5 rounded-full ${
                                                                        student.data_completeness.percentage === 100 ? 'bg-green-500' :
                                                                        student.data_completeness.percentage >= 60 ? 'bg-amber-400' : 'bg-red-400'
                                                                    }`}
                                                                    style={{ width: `${student.data_completeness.percentage}%` }}
                                                                />
                                                            </div>
                                                        </div>
                                                    ) : '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={student.status === 'active' ? 'default' : 'secondary'}
                                                           className={student.status === 'active' ? 'bg-lime-500 hover:bg-lime-600' : ''}>
                                                        {student.status_label || student.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Link href={`/students/${student.id}`}>
                                                            <Button variant="ghost" size="icon" title="Profil Lengkap" className="h-8 w-8">
                                                                <FileText className="h-4 w-4 text-slate-500" />
                                                            </Button>
                                                        </Link>
                                                        <Button 
                                                            variant="ghost" 
                                                            size="icon" 
                                                            title="Lihat Detail (Ringkas)" 
                                                            className="h-8 w-8"
                                                            onClick={() => {
                                                                setSelectedStudent(student);
                                                                setIsDetailModalOpen(true);
                                                            }}
                                                        >
                                                            <Eye className="h-4 w-4 text-slate-500" />
                                                        </Button>
                                                        <a href={`/students/${student.id}/print-biodata`} target="_blank">
                                                            <Button variant="ghost" size="icon" title="Cetak Biodata" className="h-8 w-8">
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

            {/* Modal Detail Siswa */}
            <Dialog open={isDetailModalOpen} onOpenChange={setIsDetailModalOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Detail Profil Siswa</DialogTitle>
                        <DialogDescription>
                            Informasi data ringkas peserta didik. Untuk data lengkap, kunjungi profil lengkap atau cetak biodata.
                        </DialogDescription>
                    </DialogHeader>
                    
                    {selectedStudent && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6 py-4">
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">Nama Lengkap</span>
                                <span className="font-semibold text-slate-900">{selectedStudent.name}</span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">Rombel Saat Ini</span>
                                <span className="font-medium text-slate-900">{selectedStudent.current_cohort?.rombel?.name || '-'}</span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">NISN / NISM</span>
                                <span className="font-medium text-slate-900">{selectedStudent.nisn || '-'} / {selectedStudent.nis_local || '-'}</span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">NIK (Nomor Induk Kependudukan)</span>
                                <span className="font-medium text-slate-900">{selectedStudent.nik || '-'}</span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">Jenis Kelamin</span>
                                <span className="font-medium text-slate-900">{selectedStudent.gender === 'L' ? 'Laki-laki (L)' : 'Perempuan (P)'}</span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">Tempat, Tanggal Lahir</span>
                                <span className="font-medium text-slate-900">
                                    {selectedStudent.birth_place ? `${selectedStudent.birth_place}, ` : ''}
                                    {selectedStudent.birth_date ? new Date(selectedStudent.birth_date).toLocaleDateString('id-ID', {day: 'numeric', month: 'long', year: 'numeric'}) : '-'}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">Asal Sekolah Dasar (SD/MI)</span>
                                <span className="font-medium text-slate-900">{selectedStudent.prev_school_name || '-'}</span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-xs text-slate-500">Status Siswa</span>
                                <div>
                                    <Badge variant={selectedStudent.status === 'active' ? 'default' : 'secondary'} className={selectedStudent.status === 'active' ? 'bg-lime-500' : ''}>
                                        {selectedStudent.status_label || selectedStudent.status}
                                    </Badge>
                                </div>
                            </div>
                        </div>
                    )}
                    
                    <div className="flex justify-end gap-2 pt-4 border-t mt-2">
                        {selectedStudent && (
                            <>
                                <a href={`/students/${selectedStudent.id}/print-biodata`} target="_blank">
                                    <Button variant="outline" className="gap-2">
                                        <Printer className="h-4 w-4" />
                                        Cetak Biodata
                                    </Button>
                                </a>
                                <Link href={`/students/${selectedStudent.id}`}>
                                    <Button className="gap-2 bg-slate-900 text-white hover:bg-slate-800">
                                        <FileText className="h-4 w-4" />
                                        Profil Lengkap
                                    </Button>
                                </Link>
                            </>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
