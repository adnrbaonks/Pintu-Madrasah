import { Head } from '@inertiajs/react';
import { CheckCircle2, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

export default function PublicSuccess({ registrationNumber, name, madrasahName }: {
    registrationNumber: string;
    name: string;
    madrasahName: string;
}) {
    return (
        <>
            <Head title="Pendaftaran Berhasil" />
            <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4">
                <Card className="max-w-md w-full">
                    <CardContent className="py-10 text-center">
                        <CheckCircle2 className="w-14 h-14 text-emerald-500 mx-auto mb-4" />
                        <h1 className="text-xl font-bold">Pendaftaran Berhasil!</h1>
                        <p className="text-sm text-muted-foreground mt-1">{madrasahName}</p>
                        <div className="mt-5 p-4 bg-muted rounded-lg">
                            <p className="text-xs text-muted-foreground">Nomor Pendaftaran</p>
                            <p className="text-2xl font-mono font-bold">{registrationNumber}</p>
                            <p className="text-sm mt-1">{name}</p>
                        </div>
                        <p className="text-xs text-muted-foreground mt-4">
                            Simpan/screenshot nomor ini. Panitia akan menghubungi via WA untuk verifikasi berkas (KK, Akta, Ijazah).
                        </p>
                        <Button onClick={() => window.print()} variant="outline" className="mt-4">
                            <Printer className="w-4 h-4 mr-2" /> Cetak Bukti
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
