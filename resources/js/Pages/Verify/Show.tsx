import { Head } from '@inertiajs/react';
import { ShieldCheck, ShieldX, School } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

const FIELD_LABELS: Record<string, string> = {
    madrasah: 'Madrasah',
    nama: 'Nama',
    nisn: 'NISN',
    nik: 'NIK',
    rombel: 'Rombel',
    status: 'Status',
    tanggal_cetak: 'Tanggal Cetak',
    nomor: 'Nomor Surat',
    perihal: 'Perihal',
    tanggal: 'Tanggal Surat',
    tujuan: 'Tujuan',
};

const TYPE_LABELS: Record<string, string> = {
    biodata: 'Biodata Siswa',
    surat_keluar: 'Surat Keluar',
};

export default function VerifyShow({ valid, documentType, snapshot, issuedAt, madrasahName }: {
    valid: boolean;
    documentType?: string;
    snapshot?: Record<string, string | null>;
    issuedAt?: string;
    madrasahName?: string | null;
}) {
    return (
        <>
            <Head title={valid ? 'Dokumen Terverifikasi' : 'Dokumen Tidak Dikenal'} />
            <div className="min-h-screen bg-slate-100 flex items-center justify-center px-4 py-10">
                <Card className="max-w-md w-full">
                    <CardContent className="py-8 text-center">
                        {valid ? (
                            <>
                                <ShieldCheck className="w-14 h-14 text-emerald-500 mx-auto mb-3" />
                                <h1 className="text-xl font-bold text-emerald-600">DOKUMEN ASLI</h1>
                                <p className="text-sm text-muted-foreground mt-1">
                                    {documentType ? (TYPE_LABELS[documentType] || documentType) : 'Dokumen'} • {madrasahName || ''}
                                </p>
                                <dl className="mt-5 text-left text-sm divide-y divide-border border-y">
                                    {snapshot && Object.entries(snapshot)
                                        .filter(([k, v]) => k !== 'madrasah' && v != null && v !== '')
                                        .map(([k, v]) => (
                                            <div key={k} className="grid grid-cols-3 gap-2 py-2">
                                                <dt className="text-muted-foreground">{FIELD_LABELS[k] || k}</dt>
                                                <dd className="col-span-2 font-medium">{v}</dd>
                                            </div>
                                        ))}
                                </dl>
                                {issuedAt && (
                                    <p className="text-xs text-muted-foreground mt-4">
                                        Diterbitkan {new Date(issuedAt).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}
                                    </p>
                                )}
                            </>
                        ) : (
                            <>
                                <ShieldX className="w-14 h-14 text-rose-500 mx-auto mb-3" />
                                <h1 className="text-xl font-bold text-rose-600">TIDAK DIKENAL</h1>
                                <p className="text-sm text-muted-foreground mt-2">
                                    Kode verifikasi tidak terdaftar. Dokumen ini tidak dapat dipastikan keasliannya — hubungi TU madrasah.
                                </p>
                            </>
                        )}
                        <div className="mt-6 flex items-center justify-center gap-2 text-xs text-muted-foreground">
                            <School className="w-4 h-4" />
                            Sistem Verifikasi Dokumen — Pintu Madrasah
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
