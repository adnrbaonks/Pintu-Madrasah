import { Head } from '@inertiajs/react';

interface Rombel {
    name: string; academic_year: string; semester: string; homeroom_teacher: string;
}
interface Student { no: number | null; name: string; gender: string; }
interface Settings { [key: string]: string; }

interface Props {
    rombel: Rombel; students: Student[]; month: number; monthName: string;
    year: number; daysInMonth: number; settings: Settings;
}

export default function AbsenHarian({ rombel, students, month, monthName, year, daysInMonth, settings }: Props) {
    const days = Array.from({ length: daysInMonth }, (_, i) => i + 1);
    const DAY_NAMES = ['M', 'S', 'S', 'R', 'K', 'J', 'S'];
    const getDay = (day: number) => new Date(year, month - 1, day).getDay();
    const isSunday = (day: number) => getDay(day) === 0;

    return (
        <>
            <Head title={`Absen Harian – ${rombel.name} – ${monthName} ${year}`} />
            <style>{`
                * { box-sizing: border-box; }
                body { font-family: 'Arial', sans-serif; font-size: 10px; color: #0f172a; background: white; margin: 0; }
                @media screen { .print-page { padding: 20px; max-width: 1250px; margin: 0 auto; } }
                @media print {
                    @page { size: A4 landscape; margin: 0.7cm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .no-print { display: none !important; }
                    .print-page { padding: 0; }
                }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #cbd5e1; padding: 4px 5px; text-align: left; vertical-align: middle; }
                thead th { background-color: #0f172a; color: white; font-weight: 600; font-size: 9px; text-align: center; }
                thead th.sunday { background-color: #dc2626; }
                tbody tr:nth-child(even) { background-color: #f8fafc; }
                tbody td.sunday-col { background-color: #fef2f2; }
                .info-table td { border: none; padding: 2px 4px; }
            `}</style>

            {/* Toolbar */}
            <div className="no-print" style={{ position: 'fixed', bottom: '24px', right: '24px', display: 'flex', gap: '8px', zIndex: 50 }}>
                <button onClick={() => window.history.back()}
                    style={{ display: 'flex', alignItems: 'center', gap: '6px', background: 'white', border: '1px solid #e2e8f0', color: '#475569', padding: '10px 18px', borderRadius: '9999px', cursor: 'pointer', fontWeight: 600, fontSize: '13px', boxShadow: '0 1px 4px rgba(0,0,0,.1)' }}>
                    ← Kembali
                </button>
                <button onClick={() => window.print()}
                    style={{ display: 'flex', alignItems: 'center', gap: '6px', background: '#0f172a', color: 'white', padding: '10px 20px', borderRadius: '9999px', cursor: 'pointer', fontWeight: 600, fontSize: '13px', border: 'none', boxShadow: '0 2px 8px rgba(0,0,0,.2)' }}>
                    🖨️ Cetak / Simpan PDF
                </button>
            </div>

            <div className="print-page">
                {/* Kop */}
                <table style={{ marginBottom: '12px', borderCollapse: 'collapse' }}>
                    <tbody>
                        <tr>
                            {settings.madrasah_photo && (
                                <td style={{ border: 'none', width: '65px', verticalAlign: 'middle', paddingRight: '10px' }}>
                                    <img src={settings.madrasah_photo} alt="Logo" style={{ height: '55px', objectFit: 'contain' }} />
                                </td>
                            )}
                            <td style={{ border: 'none', textAlign: 'center', verticalAlign: 'middle' }}>
                                <div style={{ fontWeight: 'bold', fontSize: '13px', textTransform: 'uppercase' }}>{settings.madrasah_name}</div>
                                <div style={{ fontSize: '10px', color: '#475569', marginTop: '2px' }}>{settings.school_address}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style={{ borderTop: '3px solid #0f172a', borderBottom: '1px solid #0f172a', padding: '4px 0', marginBottom: '12px', textAlign: 'center' }}>
                    <strong style={{ fontSize: '11px', letterSpacing: '1px', textTransform: 'uppercase' }}>
                        DAFTAR HADIR HARIAN SISWA &nbsp;|&nbsp; TAHUN PELAJARAN {rombel.academic_year}
                    </strong>
                </div>

                {/* Info */}
                <table className="info-table" style={{ marginBottom: '10px', width: 'auto' }}>
                    <tbody>
                        <tr>
                            <td style={{ width: '75px', color: '#475569' }}>Kelas</td>
                            <td style={{ width: '10px' }}>:</td>
                            <td style={{ fontWeight: '600', paddingRight: '50px' }}>{rombel.name}</td>
                            <td style={{ width: '75px', color: '#475569' }}>Wali Kelas</td>
                            <td style={{ width: '10px' }}>:</td>
                            <td style={{ fontWeight: '600' }}>{rombel.homeroom_teacher}</td>
                        </tr>
                        <tr>
                            <td style={{ color: '#475569' }}>Bulan</td>
                            <td>:</td>
                            <td style={{ fontWeight: '600' }}>{monthName} {year}</td>
                            <td style={{ color: '#475569' }}>Jml. Siswa</td>
                            <td>:</td>
                            <td style={{ fontWeight: '600' }}>{students.length} orang</td>
                        </tr>
                    </tbody>
                </table>

                {/* Tabel Absen */}
                <table>
                    <thead>
                        <tr>
                            <th style={{ width: '28px', textAlign: 'center' }}>No</th>
                            <th style={{ minWidth: '130px', textAlign: 'left' }}>Nama Siswa</th>
                            <th style={{ width: '22px' }}>L/P</th>
                            {days.map(d => (
                                <th key={d} className={isSunday(d) ? 'sunday' : ''} style={{ width: '20px', padding: '2px 1px' }}>
                                    <div>{d}</div>
                                    <div style={{ fontSize: '7px', opacity: 0.75 }}>{DAY_NAMES[getDay(d)]}</div>
                                </th>
                            ))}
                            {['S', 'I', 'A', '∑'].map(h => (
                                <th key={h} style={{ width: '22px', fontSize: '9px' }}>{h}</th>
                            ))}
                            <th style={{ width: '60px', fontSize: '9px' }}>Paraf</th>
                        </tr>
                    </thead>
                    <tbody>
                        {students.map((s, i) => (
                            <tr key={i}>
                                <td style={{ textAlign: 'center', color: '#64748b' }}>{s.no ?? i + 1}</td>
                                <td style={{ fontWeight: '500' }}>{s.name}</td>
                                <td style={{ textAlign: 'center' }}>{s.gender}</td>
                                {days.map(d => (
                                    <td key={d} className={isSunday(d) ? 'sunday-col' : ''} style={{ padding: '3px 1px', height: '18px' }}></td>
                                ))}
                                <td></td><td></td><td></td><td></td>
                                <td></td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr style={{ backgroundColor: '#f1f5f9', fontWeight: '600' }}>
                            <td colSpan={3} style={{ textAlign: 'right', paddingRight: '6px', fontSize: '9px', color: '#475569' }}>Jumlah</td>
                            {days.map(d => <td key={d} className={isSunday(d) ? 'sunday-col' : ''}></td>)}
                            <td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    </tfoot>
                </table>

                {/* Keterangan + TTD */}
                <div style={{ marginTop: '16px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' }}>
                    <div style={{ fontSize: '10px', color: '#475569' }}>
                        <p style={{ fontWeight: '700', color: '#0f172a', marginBottom: '4px' }}>Keterangan:</p>
                        {[['S', 'Sakit'], ['I', 'Izin'], ['A', 'Alpa'], ['∑', 'Jumlah hadir']].map(([k, v]) => (
                            <p key={k}><strong>{k}</strong> = {v}</p>
                        ))}
                        <p style={{ marginTop: '4px' }}><span style={{ display: 'inline-block', width: '10px', height: '10px', backgroundColor: '#fef2f2', border: '1px solid #fca5a5', marginRight: '4px' }}></span> = Hari Minggu / Libur</p>
                    </div>
                    <div style={{ textAlign: 'center', minWidth: '200px' }}>
                        <p>Wali Kelas,</p>
                        <p style={{ marginBottom: '50px' }}></p>
                        <p style={{ fontWeight: 'bold', borderTop: '1px solid #0f172a', paddingTop: '4px' }}>
                            {rombel.homeroom_teacher}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
