import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
    PieChart, Pie, Cell, Legend,
} from 'recharts';
import { Users, GraduationCap, Heart, Bus, Home, TrendingUp, BookOpen } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const COLORS = ['#0ea5e9', '#f43f5e', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];

const CustomTooltip = ({ active, payload, label }: any) => {
    if (active && payload && payload.length) {
        return (
            <div className="bg-popover border border-border rounded-lg px-3 py-2 shadow-lg text-xs">
                {label && <p className="font-semibold mb-1 text-sm">{label}</p>}
                {payload.map((p: any, i: number) => (
                    <p key={i} style={{ color: p.color || p.fill }}>
                        {p.name}: <span className="font-bold">{p.value?.toLocaleString('id-ID')}</span>
                    </p>
                ))}
            </div>
        );
    }
    return null;
};

interface Props {
    tahunAjaran: string;
    usiaDist: { usia: number; total: number }[];
    bansos: { label: string; key: string; total: number }[];
    livingStatus: { name: string; value: number }[];
    transport: { name: string; value: number }[];
    rombelBreakdown: { rombel: string; laki: number; perempuan: number; total: number }[];
    gtkAgeGroups: { name: string; value: number }[];
    gtkEducation: { name: string; value: number }[];
}

export default function Demografi({
    tahunAjaran, usiaDist, bansos, livingStatus, transport,
    rombelBreakdown, gtkAgeGroups, gtkEducation
}: Props) {
    const totalSiswa = rombelBreakdown.reduce((a, r) => a + Number(r.total), 0);
    const totalLaki  = rombelBreakdown.reduce((a, r) => a + Number(r.laki), 0);
    const totalPerempuan = rombelBreakdown.reduce((a, r) => a + Number(r.perempuan), 0);
    const totalBansos = bansos.reduce((a, b) => a + b.total, 0);

    return (
        <AppLayout>
            <Head title="Statistik Demografi" />

            <div className="mb-6">
                <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                    <TrendingUp className="w-4 h-4" />
                    <span>Statistik</span>
                </div>
                <h1 className="text-2xl font-bold">Statistik Demografi</h1>
                <p className="text-sm text-muted-foreground mt-0.5">Tahun Ajaran {tahunAjaran}</p>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                {[
                    { label: 'Total Siswa Aktif', value: totalSiswa, icon: GraduationCap, color: 'text-blue-500 bg-blue-500/10' },
                    { label: 'Siswa Laki-laki', value: totalLaki, icon: Users, color: 'text-sky-500 bg-sky-500/10' },
                    { label: 'Siswa Perempuan', value: totalPerempuan, icon: Users, color: 'text-pink-500 bg-pink-500/10' },
                    { label: 'Penerima Bansos', value: totalBansos, icon: Heart, color: 'text-rose-500 bg-rose-500/10' },
                ].map(card => {
                    const Icon = card.icon;
                    const [textColor, bgColor] = card.color.split(' ');
                    return (
                        <Card key={card.label}>
                            <CardContent className="pt-4 pb-4">
                                <div className="flex items-center gap-3">
                                    <div className={`rounded-lg p-2 ${bgColor}`}>
                                        <Icon className={`w-4 h-4 ${textColor}`} />
                                    </div>
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

            {/* Row 1: Rombel Breakdown + Usia Siswa */}
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-5 mb-5">

                {/* Rombel Breakdown */}
                <Card className="lg:col-span-3">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Distribusi Siswa per Rombel</CardTitle>
                        <CardDescription className="text-xs">Laki-laki dan Perempuan per kelas</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={rombelBreakdown} barSize={14} barGap={2}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
                                    <XAxis dataKey="rombel" tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} angle={-30} textAnchor="end" height={45} />
                                    <YAxis tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
                                    <Tooltip content={<CustomTooltip />} />
                                    <Legend iconSize={10} wrapperStyle={{ fontSize: 11 }} />
                                    <Bar dataKey="laki" name="Laki-laki" fill="#0ea5e9" radius={[3,3,0,0]} />
                                    <Bar dataKey="perempuan" name="Perempuan" fill="#f43f5e" radius={[3,3,0,0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>

                {/* Usia Siswa */}
                <Card className="lg:col-span-2">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Distribusi Usia Siswa</CardTitle>
                        <CardDescription className="text-xs">Berdasarkan tanggal lahir</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={usiaDist} layout="vertical" barSize={14}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" horizontal={false} />
                                    <XAxis type="number" tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
                                    <YAxis dataKey="usia" type="category" tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} width={30}
                                        tickFormatter={(v) => `${v} th`} />
                                    <Tooltip content={<CustomTooltip />} />
                                    <Bar dataKey="total" name="Siswa" fill="#8b5cf6" radius={[0,3,3,0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Row 2: Bansos + Tempat Tinggal + Transport */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-5">

                {/* Bantuan Sosial */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm flex items-center gap-2">
                            <Heart className="w-4 h-4 text-rose-500" />
                            Bantuan Sosial
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {bansos.map(b => (
                            <div key={b.key}>
                                <div className="flex justify-between text-xs mb-1">
                                    <span className="text-muted-foreground">{b.label}</span>
                                    <span className="font-bold">{b.total}</span>
                                </div>
                                <div className="h-1.5 bg-muted rounded-full overflow-hidden">
                                    <div className="h-full bg-rose-500 rounded-full" style={{ width: totalSiswa > 0 ? `${(b.total / totalSiswa) * 100}%` : '0%' }} />
                                </div>
                            </div>
                        ))}
                        {bansos.every(b => b.total === 0) && (
                            <p className="text-xs text-muted-foreground text-center py-4">Data belum terisi</p>
                        )}
                    </CardContent>
                </Card>

                {/* Tempat Tinggal */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm flex items-center gap-2">
                            <Home className="w-4 h-4 text-emerald-500" />
                            Status Tempat Tinggal
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {livingStatus.length === 0 ? (
                            <p className="text-xs text-muted-foreground text-center py-8">Data belum terisi</p>
                        ) : (
                            <div className="h-44">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie data={livingStatus} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={60} label={({ name, percent }) => `${(percent*100).toFixed(0)}%`} labelLine={false}>
                                            {livingStatus.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                                        </Pie>
                                        <Tooltip content={<CustomTooltip />} />
                                        <Legend iconSize={10} wrapperStyle={{ fontSize: 10 }} />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Transportasi */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm flex items-center gap-2">
                            <Bus className="w-4 h-4 text-amber-500" />
                            Transportasi ke Sekolah
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {transport.length === 0 ? (
                            <p className="text-xs text-muted-foreground text-center py-8">Data belum terisi</p>
                        ) : (
                            <div className="space-y-2.5 mt-1">
                                {transport.slice(0, 6).map((t, i) => (
                                    <div key={t.name}>
                                        <div className="flex justify-between text-xs mb-1">
                                            <span className="text-muted-foreground truncate max-w-[140px]">{t.name}</span>
                                            <span className="font-bold">{t.value}</span>
                                        </div>
                                        <div className="h-1.5 bg-muted rounded-full overflow-hidden">
                                            <div className="h-full rounded-full" style={{ width: `${(t.value / totalSiswa) * 100}%`, background: COLORS[i % COLORS.length] }} />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Row 3: GTK Demographics */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Kelompok Usia GTK</CardTitle>
                        <CardDescription className="text-xs">Guru & Tenaga Kependidikan aktif</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-44">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie data={gtkAgeGroups} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={65}
                                        label={({ name, percent }) => percent > 0.06 ? `${(percent*100).toFixed(0)}%` : ''}
                                        labelLine={false}>
                                        {gtkAgeGroups.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                                    </Pie>
                                    <Tooltip content={<CustomTooltip />} />
                                    <Legend iconSize={10} wrapperStyle={{ fontSize: 10 }} />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Kualifikasi Pendidikan GTK</CardTitle>
                        <CardDescription className="text-xs">Tingkat pendidikan tertinggi</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="h-44">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={gtkEducation} layout="vertical" barSize={16}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" horizontal={false} />
                                    <XAxis type="number" tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
                                    <YAxis dataKey="name" type="category" tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} width={40} />
                                    <Tooltip content={<CustomTooltip />} />
                                    <Bar dataKey="value" name="GTK" fill="#10b981" radius={[0,3,3,0]}
                                        label={{ position: 'right', fontSize: 10, fill: 'hsl(var(--foreground))' }} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
