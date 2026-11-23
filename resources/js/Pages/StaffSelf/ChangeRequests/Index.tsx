import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { StatusBadge, ChangeRequestItemData } from '@/components/StaffChangeComponents';
import { ArrowLeft, Plus, Eye } from 'lucide-react';

interface RequestRow {
    id: string;
    status: string;
    reason: string | null;
    current_stage: string | null;
    submitted_at: string | null;
    created_at: string;
    items: ChangeRequestItemData[];
}

export default function StaffSelfChangeRequestsIndex({
    changeRequests,
}: {
    changeRequests: RequestRow[];
}) {
    return (
        <AppLayout title="Riwayat Pengajuan Perubahan Data GTK">
            <Head title="Riwayat Pengajuan Perubahan Data GTK" />

            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div className="flex items-center gap-3">
                        <Link href="/profil-gtk">
                            <Button variant="outline" size="sm" className="gap-1.5">
                                <ArrowLeft className="w-4 h-4" />
                                Kembali
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-2xl font-bold text-slate-800">Pengajuan Perubahan Data Saya</h1>
                            <p className="text-sm text-slate-500">
                                Pantau status verifikasi Tata Usaha dan persetujuan Kepala Madrasah atas perubahan data Anda.
                            </p>
                        </div>
                    </div>
                    <Link href="/profil-gtk/pengajuan/create">
                        <Button className="gap-1.5 bg-teal-700 hover:bg-teal-800">
                            <Plus className="w-4 h-4" />
                            Buat Pengajuan Baru
                        </Button>
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Daftar Pengajuan ({changeRequests.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {changeRequests.length === 0 ? (
                            <p className="text-sm text-slate-500 py-6 text-center">
                                Anda belum memiliki pengajuan perubahan data.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs text-slate-500">
                                            <th className="py-2 pr-3">Alasan Pengajuan</th>
                                            <th className="py-2 px-3">Jumlah Field</th>
                                            <th className="py-2 px-3">Tanggal</th>
                                            <th className="py-2 px-3">Status</th>
                                            <th className="py-2 pl-3 text-right">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {changeRequests.map(req => (
                                            <tr key={req.id}>
                                                <td className="py-3 pr-3 font-medium text-slate-800">
                                                    {req.reason || 'Pembaruan Data GTK'}
                                                </td>
                                                <td className="py-3 px-3 text-xs text-slate-600">
                                                    {req.items?.length || 0} kolom
                                                </td>
                                                <td className="py-3 px-3 text-xs text-slate-500">
                                                    {req.created_at?.slice(0, 10)}
                                                </td>
                                                <td className="py-3 px-3">
                                                    <StatusBadge status={req.status} />
                                                </td>
                                                <td className="py-3 pl-3 text-right">
                                                    <Link href={`/profil-gtk/pengajuan/${req.id}`}>
                                                        <Button size="sm" variant="outline" className="gap-1">
                                                            <Eye className="w-3.5 h-3.5" /> Detail
                                                        </Button>
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
