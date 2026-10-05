import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useState } from 'react';
import { KeyRound, RefreshCw, AlertCircle } from 'lucide-react';


interface GenerateNismProps {
    studentsWithoutNismCount: number;
}

export default function GenerateNism({ studentsWithoutNismCount }: GenerateNismProps) {
    const [isGenerating, setIsGenerating] = useState(false);

    const handleGenerate = () => {
        if (confirm('Apakah Anda yakin ingin meng-generate NISM secara otomatis untuk siswa yang belum memiliki? Proses ini mungkin memerlukan waktu beberapa saat.')) {
            setIsGenerating(true);
            router.post('/students/generate-nism', {}, {
                onFinish: () => setIsGenerating(false),
                preserveScroll: true
            });
        }
    };

    return (
        <AppLayout>
            <Head title="Generate NISM" />
            
            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Generate NISM</h1>
                        <p className="text-sm text-slate-500">
                            Menu untuk memberikan Nomor Induk Siswa Madrasah secara otomatis.
                        </p>
                    </div>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <KeyRound className="h-5 w-5 text-teal-700" />
                            Auto-Generate NISM Siswa
                        </CardTitle>
                        <CardDescription>
                            Sistem akan membuatkan NISM secara berurutan bagi siswa yang masih kosong NISM-nya. 
                            Format yang digunakan adalah 18 digit: 12 digit NSM + 2 digit Tahun Masuk + 4 digit Nomor Urut.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className={`p-4 rounded-lg border flex gap-3 ${studentsWithoutNismCount > 0 ? "bg-amber-50 text-amber-900 border-amber-200" : "bg-teal-50 text-teal-900 border-teal-200"}`}>
                            <AlertCircle className={`h-5 w-5 shrink-0 ${studentsWithoutNismCount > 0 ? "text-amber-600" : "text-teal-700"}`} />
                            <div className="flex flex-col gap-1">
                                <h5 className="font-semibold leading-none tracking-tight">Status Data NISM</h5>
                                <p className="text-sm">
                                    {studentsWithoutNismCount > 0 
                                        ? `Saat ini terdapat ${studentsWithoutNismCount} siswa yang belum memiliki NISM.` 
                                        : 'Semua siswa aktif sudah memiliki NISM.'}
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center justify-end">
                            <Button 
                                onClick={handleGenerate} 
                                disabled={isGenerating || studentsWithoutNismCount === 0}
                                className="bg-teal-700 hover:bg-teal-800 text-white gap-2"
                            >
                                {isGenerating ? (
                                    <RefreshCw className="h-4 w-4 animate-spin" />
                                ) : (
                                    <KeyRound className="h-4 w-4" />
                                )}
                                {isGenerating ? 'Sedang Memproses...' : 'Mulai Generate NISM'}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
