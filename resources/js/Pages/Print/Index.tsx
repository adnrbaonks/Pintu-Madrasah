import { useState, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import {
    CheckCircle2, ChevronRight, ClipboardList, CalendarDays,
    Printer, BookOpen, Users
} from 'lucide-react';

interface AcademicYear { id: string; label: string; }
interface Rombel { id: string; name: string; academic_year_id: string; homeroom_teacher: string; }

interface Props {
    academicYears: AcademicYear[];
    rombels: Rombel[];
}

const FORMATS = [
    {
        id: 'daftar-siswa',
        label: 'Daftar Siswa per Kelas',
        description: 'Daftar nama siswa beserta NISM, NISN, jenis kelamin, dan data orang tua.',
        icon: ClipboardList,
        badge: 'Awal Tahun',
        badgeColor: 'bg-blue-100 text-blue-700',
        accent: 'border-blue-300 hover:border-blue-400',
        accentActive: 'border-blue-500 bg-blue-50 ring-2 ring-blue-300 ring-offset-1',
        iconBg: 'bg-blue-100 text-blue-600',
        filterMonth: false,
    },
    {
        id: 'daftar-kelas',
        label: 'Daftar Kelas',
        description: 'Daftar siswa dengan kolom penilaian 1–4 dan keterangan (format KELAS 7/8/9).',
        icon: Users,
        badge: 'Kelas',
        badgeColor: 'bg-violet-100 text-violet-700',
        accent: 'border-violet-300 hover:border-violet-400',
        accentActive: 'border-violet-500 bg-violet-50 ring-2 ring-violet-300 ring-offset-1',
        iconBg: 'bg-violet-100 text-violet-600',
        filterMonth: false,
    },
    {
        id: 'absen-harian',
        label: 'Daftar Hadir Harian',
        description: 'Form absensi harian siswa dengan kolom per tanggal dalam satu bulan.',
        icon: CalendarDays,
        badge: 'Rutin',
        badgeColor: 'bg-emerald-100 text-emerald-700',
        accent: 'border-emerald-300 hover:border-emerald-400',
        accentActive: 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-300 ring-offset-1',
        iconBg: 'bg-emerald-100 text-emerald-600',
        filterMonth: true,
    },
];

const MONTHS = [
    { value: '7', label: 'Juli' }, { value: '8', label: 'Agustus' }, { value: '9', label: 'September' },
    { value: '10', label: 'Oktober' }, { value: '11', label: 'November' }, { value: '12', label: 'Desember' },
    { value: '1', label: 'Januari' }, { value: '2', label: 'Februari' }, { value: '3', label: 'Maret' },
    { value: '4', label: 'April' }, { value: '5', label: 'Mei' }, { value: '6', label: 'Juni' },
];

const currentYear = new Date().getFullYear();
const YEARS = [currentYear - 1, currentYear, currentYear + 1];

export default function PrintIndex({ academicYears, rombels }: Props) {
    const [step, setStep] = useState(1);
    const [selectedFormat, setSelectedFormat] = useState<string | null>(null);
    const [selectedYear, setSelectedYear] = useState<string>('');
    const [selectedRombel, setSelectedRombel] = useState<string>('');
    const [selectedMonth, setSelectedMonth] = useState<string>(String(new Date().getMonth() + 1));
    const [selectedPrintYear, setSelectedPrintYear] = useState<string>(String(currentYear));

    const format = FORMATS.find(f => f.id === selectedFormat);
    const filteredRombels = useMemo(() => {
        if (!selectedYear) return rombels;
        return rombels.filter(r => r.academic_year_id === selectedYear);
    }, [rombels, selectedYear]);

    const selectedRombelData = rombels.find(r => r.id === selectedRombel);
    const canProceedStep2 = selectedFormat !== null;
    const canProceedStep3 = selectedRombel !== '' && (format?.filterMonth ? selectedMonth !== '' : true);

    const handlePrint = () => {
        if (!selectedFormat || !selectedRombel) return;
        const params = new URLSearchParams({ rombel_id: selectedRombel });
        if (format?.filterMonth) {
            params.append('month', selectedMonth);
            params.append('year', selectedPrintYear);
        }
        const url = selectedFormat === 'daftar-siswa'
            ? `/cetak-format/daftar-siswa?${params.toString()}`
            : selectedFormat === 'daftar-kelas'
            ? `/cetak-format/daftar-kelas?${params.toString()}`
            : `/cetak-format/absen-harian?${params.toString()}`;
        window.open(url, '_blank');
    };

    const STEPS = [
        { num: 1, label: 'Pilih Format' },
        { num: 2, label: 'Pilih Kelas' },
        { num: 3, label: 'Cetak' },
    ];

    return (
        <AppLayout>
            <Head title="Format Cetak" />
            <div className="flex flex-col gap-6">

                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Format Cetak</h1>
                        <p className="text-sm text-slate-500">Cetak format administrasi kelas berdasarkan data siswa.</p>
                    </div>
                    <div className="flex items-center gap-1.5 text-sm text-slate-500">
                        <BookOpen className="h-4 w-4" />
                        <span>Administrasi Kelas</span>
                    </div>
                </div>

                {/* Stepper */}
                <div className="flex items-center">
                    {STEPS.map((s, i) => (
                        <div key={s.num} className="flex items-center">
                            <button
                                onClick={() => s.num < step && setStep(s.num)}
                                disabled={s.num >= step}
                                className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium transition-all
                                    ${step === s.num ? 'bg-slate-900 text-white shadow-sm' : ''}
                                    ${step > s.num ? 'text-lime-700 cursor-pointer hover:bg-lime-50' : ''}
                                    ${step < s.num ? 'text-slate-400 cursor-default' : ''}
                                `}
                            >
                                {step > s.num
                                    ? <CheckCircle2 className="h-4 w-4 text-lime-600" />
                                    : <span className={`h-5 w-5 rounded-full flex items-center justify-center text-xs font-bold border-2
                                        ${step === s.num ? 'border-white/50 text-white' : 'border-slate-300 text-slate-400'}`}>{s.num}</span>
                                }
                                {s.label}
                            </button>
                            {i < STEPS.length - 1 && (
                                <ChevronRight className={`h-4 w-4 mx-1 ${step > s.num ? 'text-lime-400' : 'text-slate-300'}`} />
                            )}
                        </div>
                    ))}
                </div>

                {/* ── STEP 1: Pilih Format ── */}
                {step === 1 && (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-slate-600 font-medium">Pilih format yang ingin dicetak:</p>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            {FORMATS.map(f => {
                                const Icon = f.icon;
                                const isSelected = selectedFormat === f.id;
                                return (
                                    <button
                                        key={f.id}
                                        onClick={() => setSelectedFormat(f.id)}
                                        className={`text-left rounded-xl border-2 bg-white p-5 transition-all duration-150 hover:shadow-md
                                            ${isSelected ? f.accentActive : `border-slate-200 ${f.accent}`}
                                        `}
                                    >
                                        <div className="flex items-start justify-between mb-3">
                                            <div className={`p-2.5 rounded-lg ${f.iconBg}`}>
                                                <Icon className="h-5 w-5" />
                                            </div>
                                            <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${f.badgeColor}`}>
                                                {f.badge}
                                            </span>
                                        </div>
                                        <h3 className="font-semibold text-slate-900 text-sm mb-1">{f.label}</h3>
                                        <p className="text-xs text-slate-500 leading-relaxed">{f.description}</p>
                                        {isSelected && (
                                            <div className="mt-3 flex items-center gap-1 text-xs font-semibold text-slate-800">
                                                <CheckCircle2 className="h-3.5 w-3.5 text-lime-600" /> Dipilih
                                            </div>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                        <div className="flex justify-end">
                            <Button
                                onClick={() => setStep(2)}
                                disabled={!canProceedStep2}
                                className="gap-2 bg-slate-900 hover:bg-slate-800 text-white"
                            >
                                Lanjut <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {/* ── STEP 2: Pilih Parameter ── */}
                {step === 2 && format && (
                    <Card>
                        <CardHeader className="pb-4">
                            <div className="flex items-center gap-2">
                                <div className={`p-2 rounded-lg ${format.iconBg}`}>
                                    <format.icon className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-base">{format.label}</CardTitle>
                                    <CardDescription className="text-xs">{format.description}</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-5">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {/* Tahun Ajaran */}
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-sm font-medium text-slate-700">Tahun Ajaran</label>
                                    <Select value={selectedYear} onValueChange={v => { setSelectedYear(v || ''); setSelectedRombel(''); }}>
                                        <SelectTrigger><SelectValue placeholder="Pilih tahun ajaran..." /></SelectTrigger>
                                        <SelectContent>
                                            {academicYears.map(ay => (
                                                <SelectItem key={ay.id} value={ay.id}>{ay.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Kelas/Rombel */}
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-sm font-medium text-slate-700">Kelas / Rombel</label>
                                    <Select
                                        value={selectedRombel}
                                        onValueChange={v => setSelectedRombel(v || '')}
                                        disabled={filteredRombels.length === 0}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder={selectedYear ? 'Pilih rombel...' : 'Pilih tahun ajaran dulu'} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {filteredRombels.map(r => (
                                                <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Bulan + Tahun — hanya Absen Harian */}
                                {format.filterMonth && (
                                    <>
                                        <div className="flex flex-col gap-1.5">
                                            <label className="text-sm font-medium text-slate-700">Bulan</label>
                                            <Select value={selectedMonth} onValueChange={v => setSelectedMonth(v || '')}>
                                                <SelectTrigger><SelectValue placeholder="Pilih bulan..." /></SelectTrigger>
                                                <SelectContent>
                                                    {MONTHS.map(m => (
                                                        <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <label className="text-sm font-medium text-slate-700">Tahun</label>
                                            <Select value={selectedPrintYear} onValueChange={v => setSelectedPrintYear(v || '')}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {YEARS.map(y => (
                                                        <SelectItem key={y} value={String(y)}>{y}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </>
                                )}
                            </div>

                            {/* Ringkasan pilihan */}
                            {selectedRombelData && (
                                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    {[
                                        { icon: BookOpen, label: 'Kelas', value: selectedRombelData.name },
                                        { icon: Users, label: 'Wali Kelas', value: selectedRombelData.homeroom_teacher },
                                        ...(format.filterMonth
                                            ? [{ icon: CalendarDays, label: 'Periode', value: `${MONTHS.find(m => m.value === selectedMonth)?.label} ${selectedPrintYear}` }]
                                            : []),
                                    ].map((item, i) => (
                                        <div key={i} className="flex flex-col gap-0.5 bg-slate-50 rounded-lg border border-slate-200 px-3 py-2.5">
                                            <span className="text-xs text-slate-500">{item.label}</span>
                                            <span className="text-sm font-semibold text-slate-800 truncate">{item.value}</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <div className="flex justify-between pt-1">
                                <Button variant="outline" onClick={() => setStep(1)}>← Kembali</Button>
                                <Button
                                    onClick={() => setStep(3)}
                                    disabled={!canProceedStep3}
                                    className="gap-2 bg-slate-900 hover:bg-slate-800 text-white"
                                >
                                    Lanjut <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ── STEP 3: Konfirmasi & Cetak ── */}
                {step === 3 && format && (
                    <Card>
                        <CardHeader className="pb-4">
                            <CardTitle className="text-base">Konfirmasi & Cetak</CardTitle>
                            <CardDescription>Pastikan semua parameter sudah benar sebelum membuka halaman cetak.</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-5">
                            <div className="rounded-lg border border-slate-200 overflow-hidden divide-y divide-slate-100">
                                {[
                                    { label: 'Format', value: format.label, badge: format.badge, badgeColor: format.badgeColor },
                                    { label: 'Kelas', value: selectedRombelData?.name ?? '-' },
                                    { label: 'Wali Kelas', value: selectedRombelData?.homeroom_teacher ?? '-' },
                                    ...(format.filterMonth
                                        ? [{ label: 'Bulan', value: `${MONTHS.find(m => m.value === selectedMonth)?.label} ${selectedPrintYear}` }]
                                        : []),
                                ].map((item, i) => (
                                    <div key={i} className="flex items-center px-4 py-3 text-sm">
                                        <span className="w-28 text-slate-500 shrink-0">{item.label}</span>
                                        <span className="font-medium text-slate-900 flex items-center gap-2">
                                            {item.value}
                                            {(item as any).badge && (
                                                <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${(item as any).badgeColor}`}>
                                                    {(item as any).badge}
                                                </span>
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </div>

                            <div className="flex items-start gap-2.5 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-800">
                                <span className="shrink-0 mt-0.5">💡</span>
                                <span>
                                    Halaman cetak akan terbuka di tab baru. Gunakan{' '}
                                    <kbd className="px-1.5 py-0.5 rounded border border-amber-300 text-xs font-mono bg-white">Ctrl+P</kbd>{' '}
                                    atau tombol <strong>Cetak</strong> di browser untuk mencetak atau menyimpan sebagai PDF.
                                </span>
                            </div>

                            <div className="flex justify-between">
                                <Button variant="outline" onClick={() => setStep(2)}>← Kembali</Button>
                                <Button
                                    onClick={handlePrint}
                                    className="gap-2 bg-lime-600 hover:bg-lime-700 text-white"
                                >
                                    <Printer className="h-4 w-4" />
                                    Buka Halaman Cetak
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
