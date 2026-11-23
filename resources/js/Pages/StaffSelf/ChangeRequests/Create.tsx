import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { OwnershipDictionary } from '@/components/StaffChangeComponents';
import { ArrowLeft, Send, Save } from 'lucide-react';

interface Props {
    staff: Record<string, any> | null;
    ownership: OwnershipDictionary;
}

const PROPOSABLE_FIELDS = [
    'full_name_with_titles',
    'first_title',
    'last_title',
    'phone',
    'email',
    'functional_position',
    'highest_education',
    'university_name',
    'study_program',
    'graduation_year',
    'civil_rank',
    'civil_rank_tmt',
];

export default function StaffSelfChangeRequestsCreate({ staff, ownership }: Props) {
    const initialChanges: Record<string, string> = {};
    PROPOSABLE_FIELDS.forEach(f => {
        initialChanges[f] = staff?.[f] != null ? String(staff[f]) : '';
    });

    const form = useForm<{
        reason: string;
        changes: Record<string, string>;
        attachments: File[];
        submit_now: boolean;
    }>({
        reason: '',
        changes: initialChanges,
        attachments: [],
        submit_now: true,
    });

    const handleSubmit = (submitNow: boolean) => {
        form.transform(data => ({
            ...data,
            submit_now: submitNow,
        }));
        form.post('/profil-gtk/pengajuan', {
            forceFormData: true,
        });
    };

    return (
        <AppLayout title="Ajukan Perubahan Data GTK">
            <Head title="Ajukan Perubahan Data GTK" />

            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Link href="/profil-gtk/pengajuan">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <ArrowLeft className="w-4 h-4" />
                            Kembali
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold text-slate-800">Buat Pengajuan Perubahan Data</h1>
                        <p className="text-sm text-slate-500">
                            Ubah nilai pada kolom yang ingin diperbarui dan lampirkan dokumen bukti (SK/Ijazah/KK).
                        </p>
                    </div>
                </div>

                {!staff ? (
                    <Card>
                        <CardContent className="p-6 text-center text-sm text-slate-500">
                            Akun Anda belum terhubung dengan profil GTK.
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Formulir Usulan Perubahan Data</CardTitle>
                            <CardDescription className="text-xs">
                                Hanya kolom yang nilainya Anda ubah dari data saat ini yang akan dimasukkan ke dalam daftar pengajuan.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Alasan / Keterangan Pengajuan</Label>
                                <Input
                                    value={form.data.reason}
                                    onChange={e => form.setData('reason', e.target.value)}
                                    placeholder="Contoh: Penyesuaian gelar akademik S2 dan nomor telepon baru"
                                    required
                                />
                                {form.errors.reason && (
                                    <p className="text-xs text-rose-600 mt-1">{form.errors.reason}</p>
                                )}
                                {(form.errors as any).changes && (
                                    <p className="text-xs text-rose-600 mt-1">{(form.errors as any).changes}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t">
                                {PROPOSABLE_FIELDS.map(field => {
                                    const meta = ownership[field];
                                    const isEmis = meta?.owner_system === 'emis';
                                    const currentVal = staff[field] != null ? String(staff[field]) : '';
                                    const isModified = form.data.changes[field] !== currentVal;

                                    return (
                                        <div
                                            key={field}
                                            className={`p-3 rounded-lg border ${
                                                isModified ? 'border-teal-400 bg-teal-50/40' : 'bg-slate-50/50'
                                            }`}
                                        >
                                            <div className="flex justify-between items-center mb-1">
                                                <Label className="text-xs font-semibold text-slate-700">
                                                    {meta?.label || field}
                                                </Label>
                                                <Badge
                                                    variant="outline"
                                                    className={`text-[10px] ${
                                                        isEmis
                                                            ? 'bg-amber-50 text-amber-800 border-amber-200'
                                                            : 'bg-teal-50 text-teal-700 border-teal-200'
                                                    }`}
                                                >
                                                    {isEmis ? 'Sinkron EMIS' : 'Lokal PINTU'}
                                                </Badge>
                                            </div>
                                            <Input
                                                value={form.data.changes[field] || ''}
                                                onChange={e =>
                                                    form.setData('changes', {
                                                        ...form.data.changes,
                                                        [field]: e.target.value,
                                                    })
                                                }
                                                className="bg-white h-9 text-sm"
                                            />
                                            <p className="text-[11px] text-slate-500 mt-1">
                                                Nilai saat ini: <span className="font-medium">{currentVal || '—'}</span>
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="pt-3 border-t">
                                <Label>Lampiran Dokumen Bukti (PDF/JPG/PNG, Opsional)</Label>
                                <Input
                                    type="file"
                                    multiple
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    onChange={e =>
                                        form.setData('attachments', e.target.files ? Array.from(e.target.files) : [])
                                    }
                                    className="mt-1"
                                />
                            </div>

                            <div className="flex flex-wrap gap-3 pt-3 border-t">
                                <Button
                                    type="button"
                                    disabled={form.processing}
                                    onClick={() => handleSubmit(true)}
                                    className="bg-teal-700 hover:bg-teal-800 gap-1.5"
                                >
                                    <Send className="w-4 h-4" />
                                    Kirim untuk Verifikasi TU
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={form.processing}
                                    onClick={() => handleSubmit(false)}
                                    className="gap-1.5"
                                >
                                    <Save className="w-4 h-4" />
                                    Simpan sebagai Draf
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
