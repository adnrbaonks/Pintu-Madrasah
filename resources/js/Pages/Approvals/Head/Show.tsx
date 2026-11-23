import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    StatusBadge,
    FieldDiff,
    AttachmentList,
    ApprovalTimeline,
    ChangeRequestItemData,
    ChangeAttachmentData,
    ChangeApprovalData,
    OwnershipDictionary,
} from '@/components/StaffChangeComponents';
import { ArrowLeft, ShieldCheck, RotateCcw, XCircle } from 'lucide-react';

interface Props {
    changeRequest: {
        id: string;
        requester_user_id: string;
        status: string;
        reason: string | null;
        submitted_at: string | null;
        created_at: string;
        staff?: { id: string; full_name_with_titles: string; nip: string | null } | null;
        items: ChangeRequestItemData[];
        attachments: ChangeAttachmentData[];
        approvals: ChangeApprovalData[];
    };
    ownership: OwnershipDictionary;
}

export default function ApprovalsHeadShow({ changeRequest, ownership }: Props) {
    const { auth } = usePage<{ auth: { user?: { id: string } } }>().props;
    const [notes, setNotes] = useState('');
    const isSelf = auth?.user?.id === changeRequest.requester_user_id;
    const canAct = ['verified', 'pending_head_approval'].includes(changeRequest.status) && !isSelf;

    const handleAction = (endpoint: 'approve' | 'revision' | 'reject') => {
        if ((endpoint === 'revision' || endpoint === 'reject') && !notes.trim()) {
            window.alert('Harap isi catatan alasan revisi atau penolakan.');
            return;
        }
        router.post(`/persetujuan-gtk/${changeRequest.id}/${endpoint}`, { notes });
    };

    return (
        <AppLayout title={`Persetujuan Kamad — ${changeRequest.staff?.full_name_with_titles || ''}`}>
            <Head title="Persetujuan Kepala Madrasah — Perubahan Data GTK" />

            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Link href="/persetujuan-gtk">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <ArrowLeft className="w-4 h-4" />
                            Kembali ke Daftar
                        </Button>
                    </Link>
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-bold text-slate-800">
                                Persetujuan Kamad: {changeRequest.staff?.full_name_with_titles}
                            </h1>
                            <StatusBadge status={changeRequest.status} />
                        </div>
                        <p className="text-xs text-slate-500">
                            Alasan: {changeRequest.reason || '—'}
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Perbandingan Nilai Lama vs Usulan Baru</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <FieldDiff items={changeRequest.items} ownership={ownership} />
                            </CardContent>
                        </Card>

                        {isSelf && (
                            <Card className="border-amber-300 bg-amber-50">
                                <CardContent className="p-4 text-xs text-amber-800">
                                    Anda adalah pemohon dari pengajuan ini. Pemohon tidak dapat menyetujui pengajuannya sendiri.
                                </CardContent>
                            </Card>
                        )}

                        {canAct && (
                            <Card className="border-indigo-200">
                                <CardHeader>
                                    <CardTitle className="text-base">Keputusan Akhir Kepala Madrasah</CardTitle>
                                    <CardDescription className="text-xs">
                                        Menyetujui pengajuan ini akan langsung memperbarui data lokal di master GTK dan mencatat tugas sinkronisasi untuk field EMIS.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label>Catatan Keputusan Kepala Madrasah</Label>
                                        <Input
                                            value={notes}
                                            onChange={e => setNotes(e.target.value)}
                                            placeholder="Contoh: Disetujui sesuai SK dan ijazah terlampir"
                                            className="mt-1"
                                        />
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            onClick={() => handleAction('approve')}
                                            className="bg-teal-700 hover:bg-teal-800 gap-1.5"
                                        >
                                            <ShieldCheck className="w-4 h-4" />
                                            Setujui & Terapkan Perubahan
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => handleAction('revision')}
                                            className="border-amber-300 text-amber-800 hover:bg-amber-50 gap-1.5"
                                        >
                                            <RotateCcw className="w-4 h-4" />
                                            Minta Revisi
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => handleAction('reject')}
                                            className="border-rose-300 text-rose-700 hover:bg-rose-50 gap-1.5"
                                        >
                                            <XCircle className="w-4 h-4" />
                                            Tolak Pengajuan
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Lampiran Dokumen Bukti</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <AttachmentList attachments={changeRequest.attachments} />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Riwayat Verifikasi & Persetujuan</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ApprovalTimeline approvals={changeRequest.approvals} />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
