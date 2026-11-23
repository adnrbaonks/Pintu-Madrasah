import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
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
import { ArrowLeft, Send, Trash2 } from 'lucide-react';

interface Props {
    changeRequest: {
        id: string;
        status: string;
        reason: string | null;
        current_stage: string | null;
        submitted_at: string | null;
        decided_at: string | null;
        created_at: string;
        staff?: { id: string; full_name_with_titles: string; nip: string | null } | null;
        items: ChangeRequestItemData[];
        attachments: ChangeAttachmentData[];
        approvals: ChangeApprovalData[];
    };
    ownership: OwnershipDictionary;
}

export default function StaffSelfChangeRequestsShow({ changeRequest, ownership }: Props) {
    const canSubmit = ['draft', 'revision_requested'].includes(changeRequest.status);
    const canCancel = ['draft', 'submitted', 'under_tu_review', 'revision_requested'].includes(changeRequest.status);

    return (
        <AppLayout title={`Detail Pengajuan #${changeRequest.id.slice(0, 8)}`}>
            <Head title="Detail Pengajuan Perubahan Data" />

            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div className="flex items-center gap-3">
                        <Link href="/profil-gtk/pengajuan">
                            <Button variant="outline" size="sm" className="gap-1.5">
                                <ArrowLeft className="w-4 h-4" />
                                Kembali
                            </Button>
                        </Link>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-bold text-slate-800">
                                    {changeRequest.reason || 'Pengajuan Perubahan Data GTK'}
                                </h1>
                                <StatusBadge status={changeRequest.status} />
                            </div>
                            <p className="text-xs text-slate-500">
                                Pegawai: {changeRequest.staff?.full_name_with_titles} • Dibuat:{' '}
                                {changeRequest.created_at?.slice(0, 10)}
                            </p>
                        </div>
                    </div>

                    <div className="flex gap-2">
                        {canSubmit && (
                            <Button
                                type="button"
                                className="bg-teal-700 hover:bg-teal-800 gap-1.5"
                                onClick={() => router.post(`/profil-gtk/pengajuan/${changeRequest.id}/submit`)}
                            >
                                <Send className="w-4 h-4" />
                                Kirim ke Tata Usaha
                            </Button>
                        )}
                        {canCancel && (
                            <Button
                                type="button"
                                variant="outline"
                                className="text-rose-600 border-rose-200 hover:bg-rose-50 gap-1.5"
                                onClick={() => {
                                    if (window.confirm('Batalkan pengajuan perubahan data ini?')) {
                                        router.post(`/profil-gtk/pengajuan/${changeRequest.id}/cancel`);
                                    }
                                }}
                            >
                                <Trash2 className="w-4 h-4" />
                                Batalkan
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-base">Rincian Perubahan Field (Nilai Lama → Baru)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <FieldDiff items={changeRequest.items} ownership={ownership} />
                        </CardContent>
                    </Card>

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
                                <CardTitle className="text-base">Jejak Verifikasi & Persetujuan</CardTitle>
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
