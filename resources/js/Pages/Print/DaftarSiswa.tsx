import { Head } from '@inertiajs/react';

interface Rombel {
    name: string; academic_year: string; semester: string; homeroom_teacher: string;
}
interface Student {
    no: number | null; name: string; nisn: string; nism: string;
    gender: string; birth_place: string; birth_date: string;
    address: string; father_name: string; mother_name: string;
}
interface Settings { [key: string]: string; }

interface Props { rombel: Rombel; students: Student[]; settings: Settings; }

export default function DaftarSiswa({ rombel, students, settings }: Props) {
    return (
        <>
            <Head title={`Daftar Siswa – ${rombel.name}`} />
            <style>{`
                * { box-sizing: border-box; }
                body { font-family: 'Arial', sans-serif; font-size: 11px; color: #0f172a; background: white; margin: 0; }
                @media screen { .print-page { padding: 24px; max-width: 1100px; margin: 0 auto; } }
                @media print {
                    @page { size: A4 landscape; margin: 1cm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .no-print { display: none !important; }
                    .print-page { padding: 0; }
                }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; vertical-align: middle; }
                thead th { background-color: #0f172a; color: white; font-weight: 600; font-size: 10px; }
                tbody tr:nth-child(even) { background-color: #f8fafc; }
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
                <table style={{ marginBottom: '14px', borderCollapse: 'collapse' }}>
                    <tbody>
                        <tr>
                            {settings.madrasah_photo && (
                                <td style={{ border: 'none', width: '70px', verticalAlign: 'middle', paddingRight: '12px' }}>
                                    <img src={settings.madrasah_photo} alt="Logo" style={{ height: '60px', objectFit: 'contain' }} />
                                </td>
                            )}
                            <td style={{ border: 'none', textAlign: 'center', verticalAlign: 'middle' }}>
                                <div style={{ fontWeight: 'bold', fontSize: '14px', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                                    {settings.madrasah_name}
                                </div>
                                <div style={{ fontSize: '11px', color: '#475569', marginTop: '2px' }}>{settings.school_address}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style={{ borderTop: '3px solid #0f172a', borderBottom: '1px solid #0f172a', padding: '5px 0', marginBottom: '14px', textAlign: 'center' }}>
                    <strong style={{ fontSize: '12px', letterSpacing: '1px', textTransform: 'uppercase' }}>
                        DAFTAR SISWA
                    </strong>
                </div>

                {/* Info Kelas */}
                <table className="info-table" style={{ marginBottom: '12px', width: 'auto', minWidth: '500px' }}>
                    <tbody>
                        <tr>
                            <td style={{ width: '90px', color: '#475569' }}>Kelas</td>
                            <td style={{ width: '12px' }}>:</td>
                            <td style={{ fontWeight: '600', paddingRight: '60px' }}>{rombel.name}</td>
                            <td style={{ width: '110px', color: '#475569' }}>Tahun Pelajaran</td>
                            <td style={{ width: '12px' }}>:</td>
                            <td style={{ fontWeight: '600' }}>{rombel.academic_year}</td>
                        </tr>
                        <tr>
                            <td style={{ color: '#475569' }}>Wali Kelas</td>
                            <td>:</td>
                            <td style={{ fontWeight: '600' }}>{rombel.homeroom_teacher}</td>
                            <td style={{ color: '#475569' }}>Semester</td>
                            <td>:</td>
                            <td style={{ fontWeight: '600' }}>{rombel.semester}</td>
                        </tr>
                        <tr>
                            <td style={{ color: '#475569' }}>Jumlah Siswa</td>
                            <td>:</td>
                            <td style={{ fontWeight: '600' }}>{students.length} orang</td>
                        </tr>
                    </tbody>
                </table>

                {/* Tabel Siswa */}
                <table>
                    <thead>
                        <tr>
                            <th style={{ width: '30px', textAlign: 'center' }}>No</th>
                            <th style={{ minWidth: '150px' }}>Nama Siswa</th>
                            <th style={{ width: '150px' }}>NISM</th>
                            <th style={{ width: '100px' }}>NISN</th>
                            <th style={{ width: '30px', textAlign: 'center' }}>L/P</th>
                            <th style={{ width: '120px' }}>Tempat Lahir</th>
                            <th style={{ width: '80px' }}>Tanggal Lahir</th>
                            <th style={{ minWidth: '130px' }}>Alamat</th>
                            <th style={{ minWidth: '110px' }}>Nama Ayah</th>
                            <th style={{ minWidth: '110px' }}>Nama Ibu</th>
                        </tr>
                    </thead>
                    <tbody>
                        {students.map((s, i) => (
                            <tr key={i}>
                                <td style={{ textAlign: 'center', color: '#64748b' }}>{s.no ?? i + 1}</td>
                                <td style={{ fontWeight: '600' }}>{s.name}</td>
                                <td style={{ fontFamily: 'monospace', fontSize: '10px', color: '#1e40af', letterSpacing: '0.5px' }}>{s.nism}</td>
                                <td style={{ fontFamily: 'monospace', fontSize: '10px', color: '#475569' }}>{s.nisn}</td>
                                <td style={{ textAlign: 'center', fontWeight: '600' }}>{s.gender}</td>
                                <td>{s.birth_place}</td>
                                <td>{s.birth_date}</td>
                                <td style={{ fontSize: '10px', color: '#475569' }}>{s.address}</td>
                                <td>{s.father_name}</td>
                                <td>{s.mother_name}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {/* TTD */}
                <div style={{ marginTop: '28px', display: 'flex', justifyContent: 'flex-end' }}>
                    <div style={{ textAlign: 'center', minWidth: '200px' }}>
                        <p style={{ marginBottom: '50px' }}>Wali Kelas,</p>
                        <p style={{ fontWeight: 'bold', borderTop: '1px solid #0f172a', paddingTop: '4px', marginTop: '4px' }}>
                            {rombel.homeroom_teacher}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
