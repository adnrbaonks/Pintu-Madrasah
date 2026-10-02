import AppLayout from '@/Layouts/AppLayout';
import { Head, router, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ArrowLeft, Users, GraduationCap, User } from 'lucide-react';

interface Student { id: string; name: string; nisn: string; gender: string; birth_date: string; status: string; }
interface RombelDetail { id: string; name: string; grade_level: string; homeroom: string | null; year: string; }
interface Paginated { data: Student[]; links: any[]; from: number; to: number; total: number; }

const GRADE_PILL: Record<string, string> = {
    VII:  'bg-blue-100 text-blue-700',
    VIII: 'bg-emerald-100 text-emerald-700',
    IX:   'bg-violet-100 text-violet-700',
};

export default function Show({ rombel, students, gender_stats }: {
    rombel: RombelDetail;
    students: Paginated;
    gender_stats: { L: number; P: number };
}) {
    return (
        <AppLayout>
            <Head title={`Rombel ${rombel.name}`} />
            <div className="flex flex-col gap-6">

                {/* Back + Header */}
                <div className="flex flex-col gap-2">
                    <Button variant="ghost" size="sm" className="self-start gap-1.5 text-slate-500 hover:text-slate-700 -ml-2"
                        onClick={() => router.visit('/rombel')}>
                        <ArrowLeft className="w-4 h-4" /> Kembali ke Rombel
                    </Button>
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">{rombel.name}</h1>
                        <span className={`px-2.5 py-0.5 rounded-full text-xs font-semibold ${GRADE_PILL[rombel.grade_level] ?? 'bg-slate-100 text-slate-600'}`}>
                            Kelas {rombel.grade_level}
                        </span>
                    </div>
                    <p className="text-sm text-slate-500">Tahun Ajaran {rombel.year}</p>
                </div>

                {/* Info Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    {[
                        { icon: <Users className="w-4 h-4 text-slate-400" />, label: 'Total Siswa', value: students.total, className: 'text-slate-800' },
                        { icon: <User className="w-4 h-4 text-blue-400" />, label: 'Laki-laki', value: gender_stats.L, className: 'text-blue-600' },
                        { icon: <User className="w-4 h-4 text-pink-400" />, label: 'Perempuan', value: gender_stats.P, className: 'text-pink-500' },
                    ].map(card => (
                        <div key={card.label} className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                            <div className="flex items-center gap-2 mb-1">{card.icon}<span className="text-xs text-slate-400 font-medium uppercase tracking-wide">{card.label}</span></div>
                            <p className={`text-2xl font-bold ${card.className}`}>{card.value}</p>
                        </div>
                    ))}
                    <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                        <div className="flex items-center gap-2 mb-1">
                            <GraduationCap className="w-4 h-4 text-slate-400" />
                            <span className="text-xs text-slate-400 font-medium uppercase tracking-wide">Wali Kelas</span>
                        </div>
                        <p className="text-sm font-semibold text-slate-700 leading-tight">
                            {rombel.homeroom ?? <span className="text-slate-300 italic font-normal text-xs">Belum ditentukan</span>}
                        </p>
                    </div>
                </div>

                {/* Tabel Siswa */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200">
                    <div className="px-4 py-3 border-b border-slate-100 bg-slate-50/60 rounded-t-xl flex justify-between items-center">
                        <p className="font-semibold text-sm text-slate-700">Daftar Siswa</p>
                        <span className="text-xs text-slate-400">{students.from}–{students.to} dari {students.total} siswa</span>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50/40 hover:bg-slate-50/40">
                                <TableHead className="w-12 text-center">#</TableHead>
                                <TableHead>Nama Siswa</TableHead>
                                <TableHead>NISN</TableHead>
                                <TableHead className="text-center w-14">L/P</TableHead>
                                <TableHead>Tanggal Lahir</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {students.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center py-12 text-slate-400">
                                        Belum ada siswa di rombel ini.
                                    </TableCell>
                                </TableRow>
                            ) : students.data.map((s, idx) => (
                                <TableRow key={s.id} className="hover:bg-slate-50/60 cursor-pointer transition-colors"
                                    onClick={() => router.visit(`/students/${s.id}`)}>
                                    <TableCell className="text-center text-slate-400 text-xs font-mono">
                                        {(students.from ?? 0) + idx}
                                    </TableCell>
                                    <TableCell>
                                        <div className="font-medium text-slate-800 text-sm">{s.name}</div>
                                    </TableCell>
                                    <TableCell className="text-xs text-slate-500 font-mono">{s.nisn || '—'}</TableCell>
                                    <TableCell className="text-center">
                                        <span className={`text-xs font-bold px-2 py-0.5 rounded-full ${
                                            s.gender === 'L' ? 'bg-blue-50 text-blue-600' : 'bg-pink-50 text-pink-600'
                                        }`}>
                                            {s.gender === 'L' ? 'L' : 'P'}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-sm text-slate-500">{s.birth_date || '—'}</TableCell>
                                    <TableCell>
                                        <span className={`text-xs font-medium px-2 py-0.5 rounded ${
                                            s.status === 'active'   ? 'bg-green-50 text-green-700' :
                                            s.status === 'graduated'? 'bg-blue-50 text-blue-700' :
                                            'bg-slate-100 text-slate-500'
                                        }`}>
                                            {s.status === 'active' ? 'Aktif' : s.status === 'graduated' ? 'Lulus' : s.status}
                                        </span>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>

                    {/* Pagination */}
                    {students.links && students.links.length > 3 && (
                        <div className="flex flex-col sm:flex-row items-center justify-between gap-4 px-4 py-3 border-t border-slate-100 bg-slate-50/40">
                            <p className="text-sm text-slate-500">
                                Menampilkan <span className="font-semibold">{students.from}</span>–<span className="font-semibold">{students.to}</span> dari <span className="font-semibold">{students.total}</span> siswa
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {students.links.map((link: any, i: number) => (
                                    <Link key={i} href={link.url || '#'} preserveScroll
                                        className={`min-w-[36px] h-9 px-3 flex items-center justify-center rounded-lg border text-sm font-medium transition-colors
                                            ${link.active
                                                ? 'bg-slate-800 text-white border-slate-800 cursor-default'
                                                : link.url
                                                    ? 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 hover:border-slate-300'
                                                    : 'bg-white text-slate-300 border-slate-100 cursor-not-allowed pointer-events-none'
                                            }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
