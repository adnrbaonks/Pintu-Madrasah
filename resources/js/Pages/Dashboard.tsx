import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    GraduationCap, Users, BookOpen, Building2,
    TrendingUp, CheckCircle2, AlertCircle, Clock,
    ChevronRight, ArrowUpRight,
} from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Progress } from '@/components/ui/progress';

interface DashboardProps {
    appName: string;
    madrasahName: string;
    stats: {
        totalSiswa: number;
        totalGTK: number;
        totalRombel: number;
        tahunAjaran: string;
    };
}

export default function Dashboard({ appName, madrasahName, stats }: DashboardProps) {
    const statCards = [
        {
            label: 'Total Siswa Aktif',
            value: stats.totalSiswa.toLocaleString('id-ID'),
            subtitle: 'Tahun ajaran berjalan',
            icon: GraduationCap,
            trend: '+12 dari semester lalu',
            trendUp: true,
        },
        {
            label: 'Guru & Tenaga Kependidikan',
            value: stats.totalGTK.toLocaleString('id-ID'),
            subtitle: 'ASN, PPPK & Honorer',
            icon: Users,
            trend: '4 eligibel TPG',
            trendUp: null,
        },
        {
            label: 'Rombongan Belajar',
            value: stats.totalRombel.toLocaleString('id-ID'),
            subtitle: 'Aktif semester ini',
            icon: BookOpen,
            trend: 'Semua terkonfigurasi',
            trendUp: true,
        },
        {
            label: 'Tahun Ajaran',
            value: stats.tahunAjaran,
            subtitle: 'Sedang berjalan',
            icon: Building2,
            trend: null,
            trendUp: null,
        },
    ];

    const quickAccess = [
        { label: 'Master Data Siswa', href: '#', icon: GraduationCap, desc: 'Kelola biodata & dokumen siswa' },
        { label: 'Master Data GTK', href: '#', icon: Users, desc: 'Kelola data guru & tenaga kependidikan' },
        { label: 'Sinkronisasi EMIS', href: '#', icon: TrendingUp, desc: 'Tarik data dari portal Kemenag' },
        { label: 'Cetak Administrasi', href: '#', icon: BookOpen, desc: 'Buku induk, kartu ujian, denah kelas' },
    ];

    const systemStatus = [
        { label: 'Aplikasi TU', status: 'online' },
        { label: 'Database MySQL', status: 'online' },
        { label: 'Redis Cache', status: 'online' },
        { label: 'Cloudflare Tunnel', status: 'online' },
        { label: 'Integrasi EMIS', status: 'standby' },
        { label: 'AI Verifier (Gemini)', status: 'standby' },
        { label: 'Google Drive Sync', status: 'setup' },
    ];

    const adminProgress = [
        { label: 'Kelengkapan Data Siswa', value: 0, total: 100 },
        { label: 'Sinkronisasi EMIS', value: 0, total: 100 },
        { label: 'Dokumen Terverifikasi', value: 0, total: 100 },
    ];

    return (
        <AppLayout>
            <Head title="Dashboard" />

            {/* Page Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-foreground">Dashboard</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    {appName} — <span className="font-medium text-foreground">{madrasahName}</span>
                </p>
            </div>

            {/* Stat Cards — Bento Grid */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
                {statCards.map((card) => {
                    const Icon = card.icon;
                    return (
                        <Card key={card.label} className="hover:shadow-md transition-all duration-200 hover:-translate-y-0.5">
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardDescription className="text-xs font-medium uppercase tracking-wide">
                                    {card.label}
                                </CardDescription>
                                <div className="rounded-lg bg-primary/10 p-2">
                                    <Icon className="h-4 w-4 text-primary" />
                                </div>
                            </CardHeader>
                            <CardContent>
                                <p className="text-3xl font-bold text-foreground">{card.value}</p>
                                <p className="text-xs text-muted-foreground mt-1">{card.subtitle}</p>
                                {card.trend && (
                                    <div className="mt-2">
                                        <Badge
                                            variant={card.trendUp === true ? 'default' : card.trendUp === false ? 'destructive' : 'secondary'}
                                            className="text-xs"
                                        >
                                            {card.trend}
                                        </Badge>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            {/* Main Content Grid */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">

                {/* Quick Access */}
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-base">Akses Cepat</CardTitle>
                        <CardDescription>Menu yang sering digunakan operator TU</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            {quickAccess.map((item) => {
                                const Icon = item.icon;
                                return (
                                    <Button
                                        key={item.label}
                                        variant="outline"
                                        className="h-auto flex items-center gap-4 p-4 justify-start hover:border-primary/40 hover:bg-accent group"
                                        asChild
                                    >
                                        <a href={item.href}>
                                            <div className="rounded-lg bg-primary/10 p-2.5 group-hover:bg-primary/15 transition-colors shrink-0">
                                                <Icon className="h-5 w-5 text-primary" />
                                            </div>
                                            <div className="flex-1 min-w-0 text-left">
                                                <p className="text-sm font-medium leading-tight">{item.label}</p>
                                                <p className="text-xs text-muted-foreground mt-0.5 truncate">{item.desc}</p>
                                            </div>
                                            <ArrowUpRight className="h-4 w-4 text-muted-foreground group-hover:text-primary shrink-0 transition-colors" />
                                        </a>
                                    </Button>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* System Status */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Status Sistem</CardTitle>
                        <CardDescription>Monitoring layanan real-time</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-0">
                        {systemStatus.map((item, idx) => {
                            const config: Record<string, { icon: typeof CheckCircle2; label: string; variant: 'default' | 'secondary' | 'outline' }> = {
                                online:  { icon: CheckCircle2, label: 'Online', variant: 'default' },
                                standby: { icon: Clock, label: 'Standby', variant: 'secondary' },
                                setup:   { icon: AlertCircle, label: 'Perlu Setup', variant: 'outline' },
                            };
                            const { icon: Icon, label, variant } = config[item.status];
                            return (
                                <div key={item.label}>
                                    {idx > 0 && <Separator className="my-1" />}
                                    <div className="flex items-center justify-between py-1.5">
                                        <span className="text-sm text-foreground">{item.label}</span>
                                        <Badge variant={variant} className="gap-1 text-xs">
                                            <Icon className="h-3 w-3" />
                                            {label}
                                        </Badge>
                                    </div>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>

                {/* Progress Kelengkapan Admin */}
                <Card className="lg:col-span-3">
                    <CardHeader>
                        <CardTitle className="text-base">Progress Kelengkapan Administrasi</CardTitle>
                        <CardDescription>Status pengisian data tahun ajaran {stats.tahunAjaran}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
                            {adminProgress.map((item) => (
                                <div key={item.label} className="space-y-2">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground font-medium">{item.label}</span>
                                        <span className="font-semibold text-foreground">{item.value}%</span>
                                    </div>
                                    <Progress value={item.value} className="h-2" />
                                    <p className="text-xs text-muted-foreground">
                                        {item.value === 0 ? 'Belum dimulai' : `${item.value} dari ${item.total} selesai`}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
