import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    GraduationCap, Users, BookOpen, Building2,
    TrendingUp, AlertCircle, CheckCircle2, Clock,
    ArrowUpRight, RefreshCw, UserPlus, FileText
} from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
    PieChart, Pie, Cell, ResponsiveContainer, Tooltip,
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Legend,
} from 'recharts';

interface DashboardProps {
    appName: string;
    madrasahName: string;
    stats: {
        totalSiswa: number;
        siswaL: number;
        siswaP: number;
        totalGtk: number;
        gtkPns: number;
        gtkPppk: number;
        gtkHonorer: number;
        gtkSertifikasi: number;
        totalRombel: number;
        tahunAjaran: string;
        ppdbPending: number;
        ppdbAccepted: number;
        ppdbTotal: number;
    };
    completeness: {
        siswaWithNik: number;
        siswaVerifiedAi: number;
        siswaBerNisn: number;
    };
    charts: {
        genderSiswa: { name: string; value: number; color: string }[];
        komposisiGtk: { name: string; value: number; color: string }[];
        siswaPerRombel: { name: string; total: number }[];
    };
}

// Custom tooltip recharts
const CustomTooltip = ({ active, payload, label }: any) => {
    if (active && payload && payload.length) {
        return (
            <div className="bg-popover border border-border rounded-lg px-3 py-2 shadow-lg text-sm">
                {label && <p className="font-medium mb-1">{label}</p>}
                {payload.map((p: any) => (
                    <p key={p.name} style={{ color: p.color || p.fill }} className="font-semibold">
                        {p.name}: {p.value.toLocaleString('id-ID')}
                    </p>
                ))}
            </div>
        );
    }
    return null;
};

const RADIAN = Math.PI / 180;
const renderCustomLabel = ({ cx, cy, midAngle, innerRadius, outerRadius, percent }: any) => {
    if (percent < 0.05) return null;
    const r = innerRadius + (outerRadius - innerRadius) * 0.5;
    const x = cx + r * Math.cos(-midAngle * RADIAN);
    const y = cy + r * Math.sin(-midAngle * RADIAN);
    return (
        <text x={x} y={y} fill="white" textAnchor="middle" dominantBaseline="central" className="text-xs font-bold">
            {`${(percent * 100).toFixed(0)}%`}
        </text>
    );
};

export default function Dashboard({ appName, madrasahName, stats, completeness, charts }: DashboardProps) {
    const statCards = [
        {
            label: 'Total Siswa Aktif',
            value: stats.totalSiswa.toLocaleString('id-ID'),
            subtitle: `L: ${stats.siswaL} | P: ${stats.siswaP}`,
            icon: GraduationCap,
            badge: `${stats.ppdbPending > 0 ? `${stats.ppdbPending} PPDB pending` : 'Semua terdaftar'}`,
            badgeVariant: stats.ppdbPending > 0 ? 'destructive' : 'secondary' as any,
            href: '/students',
            gradient: 'from-blue-500/20 to-sky-500/10',
            iconColor: 'text-blue-500',
            iconBg: 'bg-blue-500/10',
        },
        {
            label: 'Guru & Tenaga Kependidikan',
            value: stats.totalGtk.toLocaleString('id-ID'),
            subtitle: `PNS: ${stats.gtkPns} | PPPK: ${stats.gtkPppk} | Honorer: ${stats.gtkHonorer}`,
            icon: Users,
            badge: `${stats.gtkSertifikasi} bersertifikat`,
            badgeVariant: 'secondary' as any,
            href: '/gtk',
            gradient: 'from-emerald-500/20 to-green-500/10',
            iconColor: 'text-emerald-500',
            iconBg: 'bg-emerald-500/10',
        },
        {
            label: 'Rombongan Belajar',
            value: stats.totalRombel.toLocaleString('id-ID'),
            subtitle: 'Aktif semester ini',
            icon: BookOpen,
            badge: 'Semua terkonfigurasi',
            badgeVariant: 'secondary' as any,
            href: '/rombel',
            gradient: 'from-violet-500/20 to-purple-500/10',
            iconColor: 'text-violet-500',
            iconBg: 'bg-violet-500/10',
        },
        {
            label: 'Tahun Ajaran',
            value: stats.tahunAjaran,
            subtitle: 'Sedang berjalan',
            icon: Building2,
            badge: null,
            badgeVariant: null,
            href: '/pengaturan',
            gradient: 'from-amber-500/20 to-orange-500/10',
            iconColor: 'text-amber-500',
            iconBg: 'bg-amber-500/10',
        },
    ];

    const quickLinks = [
        { label: 'Master Data Siswa', href: '/students', icon: GraduationCap, desc: 'Kelola biodata & dokumen siswa' },
        { label: 'Master Data GTK', href: '/gtk', icon: Users, desc: 'Kelola data guru & tenaga kependidikan' },
        { label: 'Input PPDB', href: '/ppdb/registrations/create', icon: UserPlus, desc: 'Daftarkan siswa baru' },
        { label: 'Sync EMIS Siswa', href: '/emis/siswa', icon: RefreshCw, desc: 'Sinkronisasi dari portal Kemenag' },
        { label: 'Leger Nilai', href: '/kelas/leger', icon: FileText, desc: 'Rekap nilai siswa per kelas' },
        { label: 'Cetak Format', href: '/cetak-format', icon: TrendingUp, desc: 'Biodata, kartu ujian, denah kelas' },
    ];

    const systemStatus = [
        { label: 'Aplikasi TU', status: 'online' },
        { label: 'Database MySQL', status: 'online' },
        { label: 'Redis Cache', status: 'online' },
        { label: 'Cloudflare Tunnel', status: 'online' },
        { label: 'AI Verifier (Gemini)', status: 'standby' },
        { label: 'EMIS GTK Auto Sync', status: 'standby' },
        { label: 'Google Drive', status: 'setup' },
    ];

    const statusConfig: Record<string, { color: string; label: string; dot: string }> = {
        online:  { color: 'text-emerald-500', label: 'Online',      dot: 'bg-emerald-500' },
        standby: { color: 'text-amber-500',   label: 'Standby',     dot: 'bg-amber-400' },
        setup:   { color: 'text-rose-400',    label: 'Perlu Setup', dot: 'bg-rose-400' },
    };

    return (
        <AppLayout>
            <Head title="Dashboard" />

            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold">Dashboard</h1>
                <p className="text-sm text-muted-foreground mt-0.5">
                    {appName} — <span className="font-medium text-foreground">{madrasahName}</span>
                </p>
            </div>

            {/* ── Stat Cards ───────────────────────────────────────────── */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
                {statCards.map((card) => {
                    const Icon = card.icon;
                    return (
                        <Link key={card.label} href={card.href}>
                            <Card className={`hover:shadow-lg transition-all duration-200 hover:-translate-y-0.5 cursor-pointer bg-gradient-to-br ${card.gradient} border-border/60`}>
                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                    <CardDescription className="text-xs font-medium uppercase tracking-wide text-foreground/60">
                                        {card.label}
                                    </CardDescription>
                                    <div className={`rounded-lg p-2 ${card.iconBg}`}>
                                        <Icon className={`h-4 w-4 ${card.iconColor}`} />
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-3xl font-bold">{card.value}</p>
                                    <p className="text-xs text-muted-foreground mt-1">{card.subtitle}</p>
                                    {card.badge && (
                                        <Badge variant={card.badgeVariant} className="mt-2 text-xs">
                                            {card.badge}
                                        </Badge>
                                    )}
                                </CardContent>
                            </Card>
                        </Link>
                    );
                })}
            </div>

            {/* ── Baris 2: Charts + Status Sistem ─────────────────────── */}
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3 mb-5">

                {/* Chart: Distribusi Gender Siswa */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-semibold">Distribusi Siswa</CardTitle>
                        <CardDescription className="text-xs">Berdasarkan jenis kelamin</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-40">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={charts.genderSiswa}
                                        cx="50%"
                                        cy="50%"
                                        labelLine={false}
                                        label={renderCustomLabel}
                                        outerRadius={65}
                                        dataKey="value"
                                    >
                                        {charts.genderSiswa.map((entry, i) => (
                                            <Cell key={i} fill={entry.color} />
                                        ))}
                                    </Pie>
                                    <Tooltip content={<CustomTooltip />} />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="flex items-center justify-center gap-4 mt-2">
                            {charts.genderSiswa.map(d => (
                                <div key={d.name} className="flex items-center gap-1.5 text-xs">
                                    <span className="h-2.5 w-2.5 rounded-full" style={{ background: d.color }} />
                                    <span className="text-muted-foreground">{d.name}</span>
                                    <span className="font-bold">{d.value.toLocaleString('id-ID')}</span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Chart: Komposisi GTK */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-semibold">Komposisi GTK</CardTitle>
                        <CardDescription className="text-xs">Status kepegawaian aktif</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-40">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={charts.komposisiGtk} barSize={28}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
                                    <XAxis dataKey="name" tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
                                    <YAxis tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
                                    <Tooltip content={<CustomTooltip />} />
                                    <Bar dataKey="value" radius={[4, 4, 0, 0]} label={{ position: 'top', fontSize: 11, fill: 'hsl(var(--foreground))' }}>
                                        {charts.komposisiGtk.map((entry, i) => (
                                            <Cell key={i} fill={entry.color} />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                        <p className="text-xs text-muted-foreground text-center mt-2">
                            {stats.gtkSertifikasi} dari {stats.totalGtk} guru telah bersertifikasi
                        </p>
                    </CardContent>
                </Card>

                {/* Status Sistem */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-semibold">Status Layanan</CardTitle>
                        <CardDescription className="text-xs">Monitoring real-time</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-1">
                        {systemStatus.map((item) => {
                            const cfg = statusConfig[item.status];
                            return (
                                <div key={item.label} className="flex items-center justify-between py-1.5">
                                    <span className="text-sm">{item.label}</span>
                                    <div className="flex items-center gap-1.5">
                                        <span className={`h-1.5 w-1.5 rounded-full ${cfg.dot} ${item.status === 'online' ? 'animate-pulse' : ''}`} />
                                        <span className={`text-xs font-medium ${cfg.color}`}>{cfg.label}</span>
                                    </div>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            </div>

            {/* ── Baris 3: Quick Links + Progress ─────────────────────── */}
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">

                {/* Quick Links */}
                <Card className="lg:col-span-3">
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold">Akses Cepat</CardTitle>
                        <CardDescription className="text-xs">Menu yang sering digunakan</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            {quickLinks.map((item) => {
                                const Icon = item.icon;
                                return (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        className="group flex items-center gap-3 rounded-lg border border-border/60 p-3 hover:border-primary/40 hover:bg-accent transition-all duration-150"
                                    >
                                        <div className="rounded-lg bg-primary/10 p-2 group-hover:bg-primary/15 transition-colors shrink-0">
                                            <Icon className="h-4 w-4 text-primary" />
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium truncate">{item.label}</p>
                                            <p className="text-xs text-muted-foreground truncate">{item.desc}</p>
                                        </div>
                                        <ArrowUpRight className="h-3.5 w-3.5 text-muted-foreground group-hover:text-primary transition-colors shrink-0" />
                                    </Link>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* Progress Kelengkapan Data */}
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold">Kelengkapan Data Siswa</CardTitle>
                        <CardDescription className="text-xs">Tahun ajaran {stats.tahunAjaran}</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        {[
                            {
                                label: 'Siswa Ber-NISN', pct: completeness.siswaBerNisn,
                                desc: `${completeness.siswaBerNisn}% terisi`, color: 'bg-blue-500'
                            },
                            {
                                label: 'Data NIK Terisi', pct: completeness.siswaWithNik,
                                desc: `${completeness.siswaWithNik}% terisi`, color: 'bg-amber-500'
                            },
                            {
                                label: 'Diverifikasi AI', pct: completeness.siswaVerifiedAi,
                                desc: `${completeness.siswaVerifiedAi}% terverifikasi`, color: 'bg-emerald-500'
                            },
                        ].map((item) => (
                            <div key={item.label} className="space-y-1.5">
                                <div className="flex justify-between text-xs">
                                    <span className="text-muted-foreground font-medium">{item.label}</span>
                                    <span className="font-bold text-foreground">{item.pct}%</span>
                                </div>
                                <div className="h-2 rounded-full bg-muted overflow-hidden">
                                    <div
                                        className={`h-full rounded-full ${item.color} transition-all duration-700`}
                                        style={{ width: `${item.pct}%` }}
                                    />
                                </div>
                                <p className="text-xs text-muted-foreground">{item.desc}</p>
                            </div>
                        ))}

                        {/* PPDB Summary */}
                        {stats.ppdbTotal > 0 && (
                            <div className="mt-4 pt-4 border-t border-border">
                                <p className="text-xs font-medium text-muted-foreground mb-2">PPDB Aktif</p>
                                <div className="flex gap-2">
                                    <Badge variant="outline" className="text-amber-500 border-amber-500/30 text-xs">
                                        {stats.ppdbPending} Pending
                                    </Badge>
                                    <Badge variant="outline" className="text-emerald-500 border-emerald-500/30 text-xs">
                                        {stats.ppdbAccepted} Diterima
                                    </Badge>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
