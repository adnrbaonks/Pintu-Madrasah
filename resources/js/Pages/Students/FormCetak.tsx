import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    FileText, Printer, BookOpen, ClipboardList,
    CreditCard, Users
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface RombelInfo { id: string; name: string; grade_level: string; wali_kelas: string; }
interface StudentData { attendance_number: number; name: string; gender: string; nisn: string; nis_local: string; }
interface RombelData { name: string; grade_level: string; wali_kelas: string; year: string; semester: string; }

interface Props {
    activeYear: { id: string; year: string; semester: string } | null;
    rombels: RombelInfo[];
    madrasahName: string;
    madrasahAddress: string;
}

const FORMS = [
    { id: 'daftar_siswa', name: 'Daftar Siswa', icon: Users, desc: 'Roster siswa per kelas', color: 'bg-sky-500' },
    { id: 'absensi', name: 'Daftar Hadir Siswa', icon: ClipboardList, desc: 'Absensi tatap muka per pertemuan', color: 'bg-blue-500' },
    { id: 'nilai', name: 'Form Nilai', icon: BookOpen, desc: 'Penilaian Harian & Praktek', color: 'bg-emerald-500' },
    { id: 'papan_nama', name: 'Papan Nama', icon: CreditCard, desc: 'Label meja siswa', color: 'bg-amber-500' },
    { id: 'btq', name: 'Daftar Hadir BTQ', icon: FileText, desc: 'Program Baca Tulis Al-Quran', color: 'bg-purple-500' },
];

export default function FormCetakIndex({ activeYear, rombels, madrasahName }: Props) {
    const [formType, setFormType] = useState<string | null>(null);
    const [rombelId, setRombelId] = useState('');
    const [mataPelajaran, setMataPelajaran] = useState('');
    const [rombelData, setRombelData] = useState<RombelData | null>(null);
    const [students, setStudents] = useState<StudentData[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!rombelId) { setRombelData(null); setStudents([]); return; }
        setLoading(true);
        fetch(`/siswa/form-cetak/data?rombel_id=${rombelId}`)
            .then(r => r.json())
            .then(d => { setRombelData(d.rombel); setStudents(d.students); })
            .catch(console.error)
            .finally(() => setLoading(false));
    }, [rombelId]);

    return (
        <AppLayout>
            <Head title="Form Cetak Administrasi" />

            {/* ═══ CONTROLS (hidden saat print) ═══ */}
            <div className="space-y-5 print:hidden">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Form Cetak Administrasi</h1>
                    <p className="text-sm text-muted-foreground mt-0.5">Pilih jenis form, pilih kelas, lalu cetak.</p>
                </div>

                {/* Pilih Jenis */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    {FORMS.map(f => {
                        const Icon = f.icon;
                        return (
                            <Card key={f.id}
                                onClick={() => { setFormType(f.id); setStudents([]); setRombelData(null); setRombelId(''); }}
                                className={`cursor-pointer transition-all overflow-hidden ${formType === f.id ? 'border-primary ring-2 ring-primary/20 shadow-md' : 'hover:shadow-sm hover:border-primary/30'}`}>
                                <div className={`h-1 ${f.color}`} />
                                <CardContent className="p-4 text-center">
                                    <div className={`w-10 h-10 rounded-xl ${f.color}/10 flex items-center justify-center mx-auto mb-2`}>
                                        <Icon className={`h-5 w-5 ${f.color.replace('bg-', 'text-')}`} />
                                    </div>
                                    <p className="text-sm font-semibold">{f.name}</p>
                                    <p className="text-[11px] text-muted-foreground mt-0.5">{f.desc}</p>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Form Input */}
                {formType && (
                    <Card className="shadow-sm">
                        <CardContent className="p-4">
                            <div className="flex flex-wrap items-end gap-4">
                                <div className="space-y-1.5 w-48">
                                    <Label className="text-xs font-semibold">Kelas</Label>
                                    <Select value={rombelId} onValueChange={v => setRombelId(v || '')}>
                                        <SelectTrigger className="h-9 text-sm"><SelectValue placeholder="Pilih..." /></SelectTrigger>
                                        <SelectContent>
                                            {rombels.map(r => <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {formType === 'nilai' && (
                                    <div className="space-y-1.5 w-56">
                                        <Label className="text-xs font-semibold">Mata Pelajaran</Label>
                                        <Input value={mataPelajaran} onChange={e => setMataPelajaran(e.target.value)}
                                            placeholder="Contoh: Al-Qur'an Hadis" className="h-9 text-sm" />
                                    </div>
                                )}
                                {rombelData && (
                                    <div className="ml-auto">
                                        <Button onClick={() => window.print()} className="gap-1.5">
                                            <Printer className="h-4 w-4" /> Cetak
                                        </Button>
                                    </div>
                                )}
                            </div>
                            {rombelData && (
                                <p className="text-xs text-muted-foreground mt-2">
                                    {rombelData.name} · Wali Kelas: {rombelData.wali_kelas} · {students.length} siswa
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>

            {/* ═══ PRINT AREA ═══ */}
            {rombelData && students.length > 0 && formType && (
                <div className="mt-6 print:mt-0">
                    {formType === 'daftar_siswa' && <CetakDaftarSiswa rombel={rombelData} students={students} madrasahName={madrasahName} />}
                    {formType === 'absensi' && <CetakAbsensi rombel={rombelData} students={students} mp={mataPelajaran} />}
                    {formType === 'nilai' && <CetakNilai rombel={rombelData} students={students} mp={mataPelajaran} />}
                    {formType === 'papan_nama' && <CetakPapanNama rombel={rombelData} students={students} />}
                    {formType === 'btq' && <CetakBTQ rombel={rombelData} students={students} />}
                </div>
            )}
        </AppLayout>
    );
}

/* ═══════════════════════════════════════════════════════════════
   PRINT STYLES (A4)
   ═══════════════════════════════════════════════════════════════ */
const A4_STYLE: React.CSSProperties = {
    width: '210mm',
    minHeight: '297mm',
    margin: '0 auto',
    padding: '15mm 15mm 20mm 15mm',
    fontFamily: '"Times New Roman", Times, serif',
    fontSize: '10pt',
    color: '#000',
    background: '#fff',
    boxSizing: 'border-box',
};

const TH: React.CSSProperties = {
    border: '1px solid #000',
    padding: '2px 4px',
    fontSize: '8pt',
    fontWeight: 'bold',
    textAlign: 'center',
    background: '#f0f0f0',
};

const TD: React.CSSProperties = {
    border: '1px solid #000',
    padding: '2px 4px',
    fontSize: '9pt',
};

const TD_CENTER: React.CSSProperties = { ...TD, textAlign: 'center' };

/* ═══════════════════════════════════════════════════════════════
   ABSENSI (sesuai format Excel: DAFTAR HADIR SISWA)
   ═══════════════════════════════════════════════════════════════ */
function CetakAbsensi({ rombel, students, mp }: { rombel: RombelData; students: StudentData[]; mp: string }) {
    const pertemuanCount = 19;
    return (
        <div style={A4_STYLE}>
            <style>{`
                @media print {
                    @page { size: A4 portrait; margin: 15mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .no-print { display: none !important; }
                }
            `}</style>

            {/* Header */}
            <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: '4mm' }}>
                <tbody>
                    <tr>
                        <td style={{ ...TD, fontWeight: 'bold', fontSize: '10pt', textAlign: 'center', borderBottom: 'none', border: 'none' }}>
                            DAFTAR HADIR SISWA TATAP MUKA
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, textAlign: 'center', borderBottom: 'none', border: 'none', fontSize: '9pt' }}>
                            MATA PELAJARAN : {mp || '.............................................'}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, borderBottom: 'none', border: 'none', fontSize: '9pt' }}>
                            KELAS : {rombel.name} &nbsp;&nbsp;&nbsp;&nbsp; SEMESTER / TAPEL : {rombel.semester} / {rombel.year}
                        </td>
                    </tr>
                </tbody>
            </table>

            {/* Tabel Absensi */}
            <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
                <colgroup>
                    <col style={{ width: '6mm' }} />
                    <col style={{ width: '30mm' }} />
                    {Array.from({ length: pertemuanCount }, () => <col key={Math.random()} style={{ width: '5.5mm' }} />)}
                    <col style={{ width: '5mm' }} />
                    <col style={{ width: '5mm' }} />
                    <col style={{ width: '5mm' }} />
                </colgroup>
                <thead>
                    <tr>
                        <th style={TH}>No</th>
                        <th style={{ ...TH, textAlign: 'left' }}>NAMA SISWA</th>
                        {Array.from({ length: pertemuanCount }, (_, i) => (
                            <th key={i} style={TH}>{i + 1}</th>
                        ))}
                        <th style={TH}>S</th>
                        <th style={TH}>I</th>
                        <th style={TH}>A</th>
                    </tr>
                </thead>
                <tbody>
                    {students.map((s, i) => (
                        <tr key={i} style={{ height: '6mm' }}>
                            <td style={TD_CENTER}>{s.attendance_number}</td>
                            <td style={{ ...TD, fontSize: '8pt' }}>{s.name}</td>
                            {Array.from({ length: pertemuanCount + 3 }, (_, j) => (
                                <td key={j} style={TD_CENTER}>&nbsp;</td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>

            {/* Tanda Tangan */}
            <table style={{ width: '100%', marginTop: '10mm', borderCollapse: 'collapse', border: 'none' }}>
                <tbody>
                    <tr>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '9pt', verticalAlign: 'top' }}>
                            <p>Mengetahui,</p>
                            <p style={{ fontWeight: 'bold' }}>Kepala Madrasah</p>
                            <p style={{ marginTop: '15mm', borderTop: '1px solid #000', width: '50mm', margin: '15mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm' }}>(_______________________)</p>
                        </td>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '9pt', verticalAlign: 'top' }}>
                            <p>{rombel.name}</p>
                            <p style={{ fontWeight: 'bold' }}>Wali Kelas</p>
                            <p style={{ marginTop: '15mm', borderTop: '1px solid #000', width: '50mm', margin: '15mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm' }}>({rombel.wali_kelas})</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

/* ═══════════════════════════════════════════════════════════════
   FORM NILAI (sesuai format Excel: FORM NILAI - split 2 sisi)
   ═══════════════════════════════════════════════════════════════ */
function CetakNilai({ rombel, students, mp }: { rombel: RombelData; students: StudentData[]; mp: string }) {
    const phCount = 8;
    return (
        <div style={A4_STYLE}>
            <style>{`
                @media print {
                    @page { size: A4 portrait; margin: 12mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            `}</style>

            {/* Header */}
            <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: '3mm' }}>
                <tbody>
                    <tr>
                        <td style={{ ...TD, fontWeight: 'bold', textAlign: 'center', border: 'none', fontSize: '10pt' }} colSpan={2}>
                            DAFTAR PENILAIAN HARIAN {rombel.name}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, border: 'none', fontSize: '8.5pt' }}>
                            Mata Pelajaran : {mp || '................................'}
                        </td>
                        <td style={{ ...TD, border: 'none', fontSize: '8.5pt' }}>
                            Kelas : {rombel.name}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, border: 'none', fontSize: '8.5pt' }}>
                            Guru Mapel : ________________________
                        </td>
                        <td style={{ ...TD, border: 'none', fontSize: '8.5pt' }}>
                            Wali Kelas : {rombel.wali_kelas}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, border: 'none', fontSize: '8.5pt' }}>
                            Semester/Th. Pel. : {rombel.semester} / {rombel.year}
                        </td>
                        <td style={{ ...TD, border: 'none', fontSize: '8.5pt' }}></td>
                    </tr>
                </tbody>
            </table>

            {/* Tabel Nilai */}
            <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
                <colgroup>
                    <col style={{ width: '5mm' }} />
                    <col style={{ width: '8mm' }} />
                    <col style={{ width: '22mm' }} />
                    <col style={{ width: '5mm' }} />
                    {Array.from({ length: phCount }, () => <col key={Math.random()} style={{ width: '7mm' }} />)}
                    <col style={{ width: '8mm' }} />
                    <col style={{ width: '10mm' }} />
                    <col style={{ width: '10mm' }} />
                </colgroup>
                <thead>
                    <tr>
                        <th style={TH} rowSpan={2}>No</th>
                        <th style={TH} rowSpan={2}>NIS</th>
                        <th style={TH} rowSpan={2}>NAMA</th>
                        <th style={TH} rowSpan={2}>L/P</th>
                        {Array.from({ length: phCount }, (_, i) => (
                            <th key={i} style={TH}>N-{i + 1}</th>
                        ))}
                        <th style={TH}>N.PAS</th>
                        <th style={TH} rowSpan={2}>KET</th>
                    </tr>
                    <tr>
                        {Array.from({ length: phCount + 1 }, (_, i) => (
                            <th key={i} style={{ ...TH, fontSize: '7pt' }}>{i < phCount ? `PH ${i + 1}` : 'Akhir'}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {students.map((s, i) => (
                        <tr key={i} style={{ height: '5.5mm' }}>
                            <td style={TD_CENTER}>{s.attendance_number}</td>
                            <td style={{ ...TD_CENTER, fontSize: '7.5pt' }}>{s.nis_local}</td>
                            <td style={{ ...TD, fontSize: '7.5pt' }}>{s.name}</td>
                            <td style={TD_CENTER}>{s.gender}</td>
                            {Array.from({ length: phCount + 1 }, (_, j) => (
                                <td key={j} style={TD_CENTER}>&nbsp;</td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>

            {/* Tanda Tangan */}
            <table style={{ width: '100%', marginTop: '8mm', border: 'none', borderCollapse: 'collapse' }}>
                <tbody>
                    <tr>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '8.5pt', verticalAlign: 'top' }}>
                            <p>Ditetapkan di Sumedang,</p>
                            <p>Tanggal ___/___/______</p>
                            <p style={{ fontWeight: 'bold', marginTop: '3mm' }}>Kepala Madrasah</p>
                            <div style={{ marginTop: '12mm', borderTop: '1px solid #000', width: '40mm', margin: '12mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm', fontSize: '8pt' }}>(________________________)</p>
                        </td>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '8.5pt', verticalAlign: 'top' }}>
                            <p style={{ fontWeight: 'bold', marginTop: '3mm' }}>Guru Mata Pelajaran</p>
                            <div style={{ marginTop: '12mm', borderTop: '1px solid #000', width: '40mm', margin: '12mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm', fontSize: '8pt' }}>(________________________)</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

/* ═══════════════════════════════════════════════════════════════
   PAPAN NAMA (sesuai format Excel: 3 kolom per baris)
   ═══════════════════════════════════════════════════════════════ */
function CetakPapanNama({ rombel, students }: { rombel: RombelData; students: StudentData[] }) {
    const cols = 3;
    const rows = Math.ceil(students.length / cols);
    const grid: (StudentData | null)[][] = [];
    for (let r = 0; r < rows; r++) {
        const row: (StudentData | null)[] = [];
        for (let c = 0; c < cols; c++) {
            row.push(students[r * cols + c] || null);
        }
        grid.push(row);
    }

    return (
        <div style={{ ...A4_STYLE, padding: '10mm 12mm' }}>
            <style>{`
                @media print {
                    @page { size: A4 portrait; margin: 10mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
                .papan-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3mm; }
                .papan-card { border: 1.5pt solid #000; padding: 3mm; text-align: center; page-break-inside: avoid; }
            `}</style>

            <div style={{ textAlign: 'center', marginBottom: '4mm' }}>
                <p style={{ fontWeight: 'bold', fontSize: '10pt', margin: 0 }}>MTs. NEGERI 5 SUMEDANG</p>
                <p style={{ fontSize: '8pt', margin: 0 }}>Tahun Pelajaran {rombel.year} · {rombel.name}</p>
            </div>

            <div className="papan-grid">
                {grid.map((row, ri) =>
                    row.map((s, ci) => (
                        <div key={`${ri}-${ci}`} className="papan-card" style={{ minHeight: '18mm' }}>
                            {s ? (
                                <>
                                    <p style={{ fontWeight: 'bold', fontSize: '9pt', margin: 0 }}>{s.name}</p>
                                    <p style={{ fontSize: '7.5pt', margin: '1mm 0 0' }}>NIS: {s.nis_local}</p>
                                    <p style={{ fontSize: '7.5pt', margin: '0.5mm 0 0' }}>NISN: {s.nisn}</p>
                                    <div style={{ borderTop: '0.5pt dashed #999', margin: '1.5mm 0' }} />
                                    <p style={{ fontSize: '6.5pt', color: '#666', margin: 0 }}>{rombel.name}</p>
                                </>
                            ) : <div style={{ minHeight: '18mm' }} />}
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

/* ═══════════════════════════════════════════════════════════════
   BTQ (sesuai format Excel: DAFTAR HADIR BTQ)
   ═══════════════════════════════════════════════════════════════ */
function CetakBTQ({ rombel, students }: { rombel: RombelData; students: StudentData[] }) {
    return (
        <div style={A4_STYLE}>
            <style>{`
                @media print {
                    @page { size: A4 portrait; margin: 15mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            `}</style>

            <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: '4mm' }}>
                <tbody>
                    <tr>
                        <td style={{ ...TD, fontWeight: 'bold', textAlign: 'center', border: 'none', fontSize: '10pt' }}>
                            DAFTAR HADIR PROGRAM BACA TULIS AL-QURAN
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, textAlign: 'center', border: 'none', fontSize: '9pt' }}>
                            MADRASAH TSANAWIYAH NEGERI 5 SUMEDANG
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, textAlign: 'center', border: 'none', fontSize: '9pt' }}>
                            TAHUN PELAJARAN {rombel.year}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, textAlign: 'center', border: 'none', fontSize: '9pt' }}>
                            {rombel.semester}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, textAlign: 'center', border: 'none', fontWeight: 'bold', fontSize: '9pt' }}>
                            Kelas : {rombel.name}
                        </td>
                    </tr>
                </tbody>
            </table>

            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                    <tr>
                        <th style={{ ...TH, width: '6mm' }}>No</th>
                        <th style={{ ...TH, textAlign: 'left', width: '35mm' }}>NAMA</th>
                        <th style={{ ...TH, textAlign: 'left', width: '25mm' }}>HARI/TANGGAL</th>
                        <th style={{ ...TH, textAlign: 'left' }}>KET.</th>
                    </tr>
                </thead>
                <tbody>
                    {students.map((s, i) => (
                        <tr key={i} style={{ height: '6mm' }}>
                            <td style={TD_CENTER}>{s.attendance_number}</td>
                            <td style={{ ...TD, fontSize: '8.5pt' }}>{s.name}</td>
                            <td style={TD}>&nbsp;</td>
                            <td style={TD}>&nbsp;</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <table style={{ width: '100%', marginTop: '10mm', border: 'none', borderCollapse: 'collapse' }}>
                <tbody>
                    <tr>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '9pt', verticalAlign: 'top' }}>
                            <p>Mengetahui,</p>
                            <p style={{ fontWeight: 'bold' }}>Kepala Madrasah</p>
                            <div style={{ marginTop: '15mm', borderTop: '1px solid #000', width: '45mm', margin: '15mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm', fontSize: '8pt' }}>(________________________)</p>
                        </td>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '9pt', verticalAlign: 'top' }}>
                            <p style={{ fontWeight: 'bold' }}>Guru BTQ</p>
                            <div style={{ marginTop: '15mm', borderTop: '1px solid #000', width: '45mm', margin: '15mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm', fontSize: '8pt' }}>(________________________)</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

/* ═══════════════════════════════════════════════════════════════
   DAFTAR SISWA (sesuai format Excel: Sheet KELAS 7/8/9)
   Header: Kop sekolah + Tahun Pelajaran + Kelas + Wali Kelas + Mapel
   Kolom: NO | NIS | NISN | NAMA | JK | 1 | 2 | 3 | 4 | KET
   ═══════════════════════════════════════════════════════════════ */
function CetakDaftarSiswa({ rombel, students, madrasahName }: { rombel: RombelData; students: StudentData[]; madrasahName: string }) {
    return (
        <div style={A4_STYLE}>
            <style>{`
                @media print {
                    @page { size: A4 portrait; margin: 12mm 10mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            `}</style>

            {/* Kop */}
            <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: '3mm' }}>
                <tbody>
                    <tr>
                        <td style={{ ...TD, fontWeight: 'bold', textAlign: 'center', border: 'none', fontSize: '11pt' }}>
                            DAFTAR SISWA {madrasahName.toUpperCase()}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, textAlign: 'center', border: 'none', fontSize: '9pt' }}>
                            TAHUN PELAJARAN {rombel.year}
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, border: 'none', fontSize: '9pt', marginTop: '2mm' }}>
                            <span style={{ fontWeight: 'bold' }}>Kelas : {rombel.name}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style={{ ...TD, border: 'none', fontSize: '9pt' }}>
                            <table style={{ width: '100%', border: 'none', borderCollapse: 'collapse' }}>
                                <tbody>
                                    <tr>
                                        <td style={{ border: 'none', fontSize: '9pt', width: '60%' }}>
                                            Wali Kelas : {rombel.wali_kelas}
                                        </td>
                                        <td style={{ border: 'none', fontSize: '9pt', width: '40%' }}>
                                            Mata Pelajaran : ............................
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </tbody>
            </table>

            {/* Tabel Daftar Siswa */}
            <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
                <colgroup>
                    <col style={{ width: '6mm' }} />
                    <col style={{ width: '22mm' }} />
                    <col style={{ width: '20mm' }} />
                    <col />
                    <col style={{ width: '7mm' }} />
                    <col style={{ width: '7mm' }} />
                    <col style={{ width: '7mm' }} />
                    <col style={{ width: '7mm' }} />
                    <col style={{ width: '7mm' }} />
                    <col style={{ width: '14mm' }} />
                </colgroup>
                <thead>
                    <tr>
                        <th style={TH}>NO</th>
                        <th style={TH}>NIS</th>
                        <th style={TH}>NISN</th>
                        <th style={TH}>NAMA</th>
                        <th style={TH}>JK</th>
                        <th style={TH}>1</th>
                        <th style={TH}>2</th>
                        <th style={TH}>3</th>
                        <th style={TH}>4</th>
                        <th style={TH}>KET</th>
                    </tr>
                </thead>
                <tbody>
                    {students.map((s, i) => (
                        <tr key={i} style={{ height: '5.5mm' }}>
                            <td style={TD_CENTER}>{s.attendance_number}</td>
                            <td style={{ ...TD_CENTER, fontSize: '7.5pt' }}>{s.nis_local}</td>
                            <td style={{ ...TD_CENTER, fontSize: '7.5pt' }}>{s.nisn}</td>
                            <td style={{ ...TD, fontSize: '8.5pt' }}>{s.name}</td>
                            <td style={TD_CENTER}>{s.gender}</td>
                            <td style={TD_CENTER}>&nbsp;</td>
                            <td style={TD_CENTER}>&nbsp;</td>
                            <td style={TD_CENTER}>&nbsp;</td>
                            <td style={TD_CENTER}>&nbsp;</td>
                            <td style={TD}>&nbsp;</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            {/* Tanda Tangan */}
            <table style={{ width: '100%', marginTop: '8mm', border: 'none', borderCollapse: 'collapse' }}>
                <tbody>
                    <tr>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '9pt', verticalAlign: 'top' }}>
                            <p>Mengetahui,</p>
                            <p style={{ fontWeight: 'bold' }}>Kepala Madrasah</p>
                            <div style={{ marginTop: '12mm', borderTop: '1px solid #000', width: '45mm', margin: '12mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm', fontSize: '8pt' }}>(________________________)</p>
                        </td>
                        <td style={{ border: 'none', width: '50%', textAlign: 'center', fontSize: '9pt', verticalAlign: 'top' }}>
                            <p>{rombel.name}</p>
                            <p style={{ fontWeight: 'bold' }}>Wali Kelas</p>
                            <div style={{ marginTop: '12mm', borderTop: '1px solid #000', width: '45mm', margin: '12mm auto 0' }} />
                            <p style={{ fontWeight: 'bold', marginTop: '2mm', fontSize: '8pt' }}>({rombel.wali_kelas})</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
