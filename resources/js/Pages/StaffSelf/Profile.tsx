import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { StatusBadge } from '@/components/StaffChangeComponents';
import { FileText, Plus, Building, Bell, UserCheck, ClipboardList } from 'lucide-react';

interface Props {
    staff: {
        id: string;
        full_name_with_titles: string;
        nip: string | null;
        nik: string | null;
        nuptk: string | null;
        npk: string | null;
        phone: string | null;
        email: string | null;
        employment_status: string;
        functional_position: string | null;
        highest_education: string | null;
        university_name: string | null;
        study_program: string | null;
        position_assignments?: {
            id: string;
            sk_number: string | null;
            valid_from: string;
            valid_until: string | null;
            is_primary: boolean;
            status: string;
            position?: { name: string; code: string } | null;
            unit?: { name: string; code: string } | null;
        }[];
        change_requests?: {
            id: string;
            status: string;
            reason: string | null;
            items_count?: number;
            created_at: string;
        }[];
        documents?: {
            id: string;
            title: string;
            document_type: string;
        }[];
    } | null;
    notifications: {
        id: string;
        data: {
            title?: string;
            message?: string;
            request_id?: string;
        };
        created_at: string;
    }[];
}

export default function StaffSelfProfile({ staff, notifications }: Props) {
    return (
        <AppLayout title="Layanan Mandiri GTK">
            <Head title="Layanan Mandiri GTK" />

            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-800">Layanan Mandiri Kepegawaian (GTK)</h1>
                        <p className="text-sm text-slate-500">
                            Kelola profil kepegawaian, arsip dokumen pribadi, dan ajukan pembaruan data resmi berjenjang.
                        </p>
                    </div>
                    {staff && (
                        <div className="flex flex-wrap gap-2">
                            <Link href="/profil-gtk/dokumen">
                                <Button variant="outline" className="gap-1.5">
                                    <FileText className="w-4 h-4" />
                                    Dokumen Saya ({staff.documents?.length || 0})
                                </Button>
                            </Link>
                            <Link href="/profil-gtk/pengajuan/create">
                                <Button className="gap-1.5 bg-teal-700 hover:bg-teal-800">
                                    <Plus className="w-4 h-4" />
                                    Ajukan Perubahan Data
                                </Button>
                            </Link>
                        </div>
                    )}
                </div>

                {!staff ? (
                    <Card>
                        <CardContent className="p-6 text-center text-sm text-slate-500">
                            Akun pengguna Anda belum ditautkan dengan profil pegawai GTK. Hubungi Administrator atau Operator Madrasah untuk menautkan akun.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div className="lg:col-span-2 space-y-6">
                            <Card>
                                <CardHeader className="pb-3 border-b">
                                    <div className="flex justify-between items-center">
                                        <div>
                                            <CardTitle className="text-lg">{staff.full_name_with_titles}</CardTitle>
                                            <CardDescription className="text-xs">
                                                {staff.functional_position || 'Pendidik / Tenaga Kependidikan'} •{' '}
                                                {staff.employment_status?.toUpperCase()}
                                            </CardDescription>
                                        </div>
                                        <Badge className="bg-emerald-100 text-emerald-800 border-emerald-200">
                                            <UserCheck className="w-3.5 h-3.5 mr-1" /> Terhubung
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <p className="text-xs text-slate-500">NIP</p>
                                        <p className="font-semibold text-slate-800">{staff.nip || '—'}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-slate-500">NUPTK</p>
                                        <p className="font-semibold text-slate-800">{staff.nuptk || '—'}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-slate-500">Nomor Telepon / WhatsApp</p>
                                        <p className="font-medium text-slate-800">{staff.phone || '—'}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-slate-500">Email</p>
                                        <p className="font-medium text-slate-800">{staff.email || '—'}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-slate-500">Pendidikan Terakhir</p>
                                        <p className="font-medium text-slate-800">
                                            {staff.highest_education || '—'} {staff.university_name ? `(${staff.university_name})` : ''}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-slate-500">Program Studi</p>
                                        <p className="font-medium text-slate-800">{staff.study_program || '—'}</p>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Riwayat Pengajuan Perubahan Data */}
                            <Card>
                                <CardHeader className="pb-3 border-b">
                                    <div className="flex justify-between items-center">
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <ClipboardList className="w-4 h-4 text-teal-600" />
                                            Pengajuan Perubahan Data Terakhir
                                        </CardTitle>
                                        <Link href="/profil-gtk/pengajuan" className="text-xs font-medium text-teal-700 hover:underline">
                                            Lihat Semua
                                        </Link>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-4">
                                    {staff.change_requests && staff.change_requests.length > 0 ? (
                                        <ul className="space-y-2.5 text-sm">
                                            {staff.change_requests.map(req => (
                                                <li key={req.id} className="p-3 rounded-lg border bg-slate-50 flex justify-between items-center">
                                                    <div>
                                                        <Link
                                                            href={`/profil-gtk/pengajuan/${req.id}`}
                                                            className="font-semibold text-slate-800 hover:text-teal-700"
                                                        >
                                                            {req.reason || 'Pengajuan Perubahan Data'}
                                                        </Link>
                                                        <p className="text-xs text-slate-500">
                                                            {req.items_count ?? 0} kolom diajukan • {req.created_at?.slice(0, 10)}
                                                        </p>
                                                    </div>
                                                    <StatusBadge status={req.status} />
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-xs text-slate-400 py-3">
                                            Belum ada riwayat pengajuan perubahan data kepegawaian.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        <div className="space-y-6">
                            {/* Penugasan Jabatan */}
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Building className="w-4 h-4 text-teal-600" />
                                        Penugasan Jabatan Saya
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {staff.position_assignments && staff.position_assignments.length > 0 ? (
                                        <ul className="space-y-2 text-sm">
                                            {staff.position_assignments.map(pa => (
                                                <li key={pa.id} className="p-2.5 rounded border bg-slate-50">
                                                    <div className="font-semibold text-slate-800">{pa.position?.name}</div>
                                                    <div className="text-xs text-slate-500">{pa.unit?.name}</div>
                                                    <div className="text-[11px] text-slate-400 mt-1">
                                                        SK: {pa.sk_number || '—'} ({pa.valid_from?.slice(0, 10)})
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-xs text-slate-400">Belum ada penugasan jabatan struktural.</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Notifikasi */}
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Bell className="w-4 h-4 text-teal-600" />
                                        Notifikasi Kepegawaian
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {notifications && notifications.length > 0 ? (
                                        <ul className="space-y-2 text-xs">
                                            {notifications.map(n => (
                                                <li key={n.id} className="p-2.5 rounded border bg-slate-50 space-y-0.5">
                                                    <p className="font-semibold text-slate-800">{n.data?.title}</p>
                                                    <p className="text-slate-600">{n.data?.message}</p>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-xs text-slate-400">Belum ada notifikasi baru.</p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
