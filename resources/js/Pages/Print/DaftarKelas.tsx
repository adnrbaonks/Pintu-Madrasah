import { Head } from '@inertiajs/react';

interface Rombel {
    name: string; academic_year: string; semester: string; homeroom_teacher: string;
}
interface Student {
    no: number | null; nis: string; nisn: string; name: string; gender: string;
}
interface Settings { [key: string]: string; }

interface Props { rombel: Rombel; students: Student[]; settings: Settings; }

export default function DaftarKelas({ rombel, students, settings }: Props) {
    return (
        <>
            <Head title={`Daftar Kelas – ${rombel.name}`} />
            <style>{`
                * { box-sizing: border-box; }
                body { font-family: 'Times New Roman', Times, serif; font-size: 11px; color: #000; background: white; margin: 0; }
                @media screen { .print-page { padding: 24px; max-width: 900px; margin: 0 auto; } }
                @media print {
                    @page { size: A4 portrait; margin: 12mm 10mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .no-print { display: none !important; }
                    .print-page { padding: 0; }
                }
                table { border-collapse: collapse; width: 100%; }
                .grid-table th, .grid-table td { border: 1px solid #000; padding: 4px 6px; }
                .grid-table thead th { font-weight: bold; font-size: 10px; text-align: center; }
                .info-table td { border: none; padding: 2px 4px; }
            `}</style>

            {/* Toolbar — hilang saat print */}
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
                <table style={{ marginBottom: '12px' }}>
                    <tbody>
                        {settings.madrasah_photo ? (
                            <tr>
                                <td style={{ border: 'none', width: '70px', verticalAlign: 'middle', paddingRight: '12px' }}>
                                    <img src={settings.madrasah_photo} alt="Logo" style={{ height: '60px', objectFit: 'contain' }} />
                                </td>
                                <td style={{ border: 'none', textAlign: 'center', verticalAlign: 'middle' }}>
                                    <div style={{ fontWeight: 'bold', fontSize: '14px', textTransform: 'uppercase' }}>
                                        DAFTAR SISWA {settings.madrasah_name}
                                    </div>
                                    <div style={{ fontSize: '11px', marginTop: '2px' }}>
                                        TAHUN PELAJARAN {rombel.academic_year}
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            <tr>
                                <td style={{ border: 'none', textAlign: 'center' }}>
                                    <div style={{ fontWeight: 'bold', fontSize: '14px', textTransform: 'uppercase' }}>
                                        DAFTAR SISWA {settings.madrasah_name}
                                    </div>
                                    <div style={{ fontSize: '11px', marginTop: '2px' }}>
                                        TAHUN PELAJARAN {rombel.academic_year}
                                    </div>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {/* Info Kelas */}
                <table className="info-table" style={{ marginBottom: '10px' }}>
                    <tbody>
                        <tr>
                            <td style={{ width: '70px' }}>Kelas</td>
                            <td style={{ width: '12px' }}>:</td>
                            <td style={{ fontWeight: 'bold' }}>{rombel.name}</td>
                        </tr>
                        <tr>
                            <td>Wali Kelas</td>
                            <td>:</td>
                            <td style={{ fontWeight: 'bold' }}>{rombel.homeroom_teacher}</td>
                            <td style={{ width: '120px', paddingLeft: '40px' }}>Mata Pelajaran</td>
                            <td>:</td>
                            <td style={{ color: '#999' }}>…..……….……</td>
                        </tr>
                    </tbody>
                </table>

                {/* Tabel Daftar Kelas */}
                <table className="grid-table">
                    <thead>
                        <tr>
                            <th style={{ width: '28px' }}>NO</th>
                            <th style={{ width: '110px' }}>NIS</th>
                            <th style={{ width: '90px' }}>NISN</th>
                            <th style={{ textAlign: 'left' }}>NAMA</th>
                            <th style={{ width: '28px' }}>JK</th>
                            <th style={{ width: '30px' }}>1</th>
                            <th style={{ width: '30px' }}>2</th>
                            <th style={{ width: '30px' }}>3</th>
                            <th style={{ width: '30px' }}>4</th>
                            <th style={{ width: '90px' }}>KET</th>
                        </tr>
                    </thead>
                    <tbody>
                        {students.map((s, i) => (
                            <tr key={i} style={{ height: '22px' }}>
                                <td style={{ textAlign: 'center' }}>{s.no ?? i + 1}</td>
                                <td style={{ fontSize: '10px' }}>{s.nis}</td>
                                <td style={{ fontSize: '10px' }}>{s.nisn}</td>
                                <td style={{ fontWeight: 600 }}>{s.name}</td>
                                <td style={{ textAlign: 'center', fontWeight: 600 }}>{s.gender}</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {/* TTD */}
                <div style={{ marginTop: '24px', display: 'flex', justifyContent: 'space-between' }}>
                    <div style={{ textAlign: 'center', minWidth: '200px' }}>
                        <p style={{ margin: 0 }}>Mengetahui,</p>
                        <p style={{ fontWeight: 'bold', margin: 0 }}>Kepala Madrasah</p>
                        <div style={{ marginTop: '50px', borderTop: '1px solid #000', width: '180px', margin: '50px auto 0' }} />
                        <p style={{ fontWeight: 'bold', marginTop: '4px' }}>(_______________________)</p>
                    </div>
                    <div style={{ textAlign: 'center', minWidth: '200px' }}>
                        <p style={{ margin: 0 }}>{rombel.name},</p>
                        <p style={{ fontWeight: 'bold', margin: 0 }}>Wali Kelas</p>
                        <div style={{ marginTop: '50px', borderTop: '1px solid #000', width: '180px', margin: '50px auto 0' }} />
                        <p style={{ fontWeight: 'bold', marginTop: '4px' }}>({rombel.homeroom_teacher})</p>
                    </div>
                </div>
            </div>
        </>
    );
}
