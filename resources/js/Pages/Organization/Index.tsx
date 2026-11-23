import React, { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Building2, Briefcase, UserCheck, Calendar, Plus, Pencil, Trash2,
    ShieldCheck, GitBranch, Award, CheckCircle2
} from 'lucide-react';

interface OrgUnit {
    id: string;
    parent_id: string | null;
    code: string;
    name: string;
    type: string;
    valid_from: string | null;
    valid_until: string | null;
    is_active: boolean;
    positions_count?: number;
    assignments_count?: number;
    parent?: { id: string; code: string; name: string } | null;
}

interface PositionItem {
    id: string;
    org_unit_id: string;
    code: string;
    name: string;
    level: number;
    is_structural: boolean;
    valid_from: string | null;
    valid_until: string | null;
    assignments_count?: number;
    unit?: { id: string; code: string; name: string; type: string } | null;
}

interface AssignmentItem {
    id: string;
    staff_id: string;
    position_id: string;
    org_unit_id: string;
    sk_number: string | null;
    valid_from: string;
    valid_until: string | null;
    is_primary: boolean;
    status: 'active' | 'ended' | 'revoked';
    staff?: {
        id: string;
        full_name_with_titles: string;
        nip: string | null;
        nuptk: string | null;
        employment_status: string;
    } | null;
    position?: {
        id: string;
        org_unit_id: string;
        code: string;
        name: string;
        level: number;
        is_structural: boolean;
    } | null;
    unit?: {
        id: string;
        code: string;
        name: string;
        type: string;
    } | null;
}

interface StaffOption {
    id: string;
    full_name_with_titles: string;
    nip: string | null;
    nuptk: string | null;
    employment_status: string;
}

interface ApproverSummary {
    id: string;
    full_name_with_titles: string;
    nip: string | null;
}

interface Props {
    effectiveDate: string;
    units: OrgUnit[];
    positions: PositionItem[];
    assignments: AssignmentItem[];
    effectiveAssignments: AssignmentItem[];
    approvers: {
        head_approval: ApproverSummary | null;
        tu_review: ApproverSummary | null;
    };
    staffList: StaffOption[];
}

const UNIT_TYPES: Record<string, string> = {
    pimpinan: 'Pimpinan Madrasah',
    tata_usaha: 'Tata Usaha & Administrasi',
    kurikulum: 'Bidang Kurikulum',
    akademik: 'Akademik & Rombel',
    kesiswaan: 'Bidang Kesiswaan',
    sarana: 'Sarana & Prasarana',
    unit: 'Unit Pendukung',
};

const LEVEL_LABELS: Record<number, string> = {
    1: 'Level 1 — Pimpinan Utama (Kamad)',
    2: 'Level 2 — Kaur TU / Wakil Kepala',
    3: 'Level 3 — Koordinator / Guru / Wali',
    4: 'Level 4 — Pelaksana / Staf',
};

export default function OrganizationIndex({
    effectiveDate,
    units,
    positions,
    assignments,
    effectiveAssignments,
    approvers,
    staffList,
}: Props) {
    const { auth } = usePage<{ auth: { user?: { role: string } } }>().props;
    const canManage = ['admin', 'kaur_tu', 'staf', 'operator'].includes(auth?.user?.role || '');

    const [activeTab, setActiveTab] = useState<'assignments' | 'units' | 'positions'>('assignments');
    const [dateFilter, setDateFilter] = useState(effectiveDate);

    // ── Form Unit Kerja ──────────────────────────────────────────────
    const [editingUnit, setEditingUnit] = useState<OrgUnit | null>(null);
    const unitForm = useForm({
        parent_id: '',
        code: '',
        name: '',
        type: 'unit',
        valid_from: '',
        valid_until: '',
        is_active: true,
    });

    const submitUnit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditingUnit(null);
                unitForm.reset();
            },
        };
        if (editingUnit) {
            unitForm.put(`/organisasi/unit/${editingUnit.id}`, options);
        } else {
            unitForm.post('/organisasi/unit', options);
        }
    };

    const startEditUnit = (unit: OrgUnit) => {
        setEditingUnit(unit);
        unitForm.setData({
            parent_id: unit.parent_id || '',
            code: unit.code,
            name: unit.name,
            type: unit.type,
            valid_from: unit.valid_from ? unit.valid_from.slice(0, 10) : '',
            valid_until: unit.valid_until ? unit.valid_until.slice(0, 10) : '',
            is_active: unit.is_active,
        });
    };

    // ── Form Jabatan ─────────────────────────────────────────────────
    const [editingPosition, setEditingPosition] = useState<PositionItem | null>(null);
    const positionForm = useForm({
        org_unit_id: units[0]?.id || '',
        code: '',
        name: '',
        level: 3,
        is_structural: false,
        valid_from: '',
        valid_until: '',
    });

    const submitPosition = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditingPosition(null);
                positionForm.reset();
            },
        };
        if (editingPosition) {
            positionForm.put(`/organisasi/jabatan/${editingPosition.id}`, options);
        } else {
            positionForm.post('/organisasi/jabatan', options);
        }
    };

    const startEditPosition = (pos: PositionItem) => {
        setEditingPosition(pos);
        positionForm.setData({
            org_unit_id: pos.org_unit_id,
            code: pos.code,
            name: pos.name,
            level: pos.level,
            is_structural: pos.is_structural,
            valid_from: pos.valid_from ? pos.valid_from.slice(0, 10) : '',
            valid_until: pos.valid_until ? pos.valid_until.slice(0, 10) : '',
        });
    };

    // ── Form Penugasan ───────────────────────────────────────────────
    const [editingAssignment, setEditingAssignment] = useState<AssignmentItem | null>(null);
    const assignmentForm = useForm({
        staff_id: staffList[0]?.id || '',
        position_id: positions[0]?.id || '',
        org_unit_id: positions[0]?.org_unit_id || '',
        sk_number: '',
        valid_from: effectiveDate,
        valid_until: '',
        is_primary: true,
        status: 'active' as 'active' | 'ended' | 'revoked',
    });

    const handlePositionSelect = (positionId: string) => {
        const found = positions.find(p => p.id === positionId);
        assignmentForm.setData(prev => ({
            ...prev,
            position_id: positionId,
            org_unit_id: found ? found.org_unit_id : prev.org_unit_id,
        }));
    };

    const submitAssignment = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditingAssignment(null);
                assignmentForm.reset('sk_number', 'valid_until');
            },
        };
        if (editingAssignment) {
            assignmentForm.put(`/organisasi/penugasan/${editingAssignment.id}`, options);
        } else {
            assignmentForm.post('/organisasi/penugasan', options);
        }
    };

    const startEditAssignment = (item: AssignmentItem) => {
        setEditingAssignment(item);
        assignmentForm.setData({
            staff_id: item.staff_id,
            position_id: item.position_id,
            org_unit_id: item.org_unit_id,
            sk_number: item.sk_number || '',
            valid_from: item.valid_from ? item.valid_from.slice(0, 10) : effectiveDate,
            valid_until: item.valid_until ? item.valid_until.slice(0, 10) : '',
            is_primary: item.is_primary,
            status: item.status,
        });
    };

    const applyDateFilter = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/organisasi', { date: dateFilter }, { preserveState: true, preserveScroll: true });
    };

    const effectiveIds = new Set(effectiveAssignments.map(a => a.id));

    return (
        <AppLayout title="Struktur Organisasi & Penugasan GTK">
            <Head title="Struktur Organisasi & Penugasan GTK" />

            <div className="space-y-6">
                {/* Header & Date Lookup */}
                <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-800">Struktur Organisasi, Jabatan & Penugasan</h1>
                        <p className="text-sm text-slate-500">
                            Kelola hierarki unit kerja, master jabatan struktural/fungsional, dan masa berlaku SK penugasan pejabat.
                        </p>
                    </div>

                    <form onSubmit={applyDateFilter} className="flex items-center gap-2 bg-white p-2 rounded-lg border shadow-xs">
                        <Calendar className="w-4 h-4 text-teal-600 ml-1" />
                        <span className="text-xs font-medium text-slate-600">Tinjau per Tanggal:</span>
                        <Input
                            type="date"
                            value={dateFilter}
                            onChange={e => setDateFilter(e.target.value)}
                            className="h-8 w-40 text-xs"
                        />
                        <Button type="submit" size="sm" variant="outline" className="h-8 text-xs">
                            Tampilkan
                        </Button>
                    </form>
                </div>

                {/* Ringkasan Pejabat Approver Aktif pada Tanggal Terpilih */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card className="border-teal-200 bg-teal-50/40">
                        <CardContent className="p-4 flex items-start gap-3">
                            <div className="p-2.5 rounded-lg bg-teal-600 text-white">
                                <ShieldCheck className="w-5 h-5" />
                            </div>
                            <div>
                                <p className="text-xs font-medium text-teal-800 uppercase tracking-wider">
                                    Approver Level 1 (Kepala Madrasah)
                                </p>
                                {approvers.head_approval ? (
                                    <>
                                        <p className="text-sm font-bold text-slate-900 mt-0.5">
                                            {approvers.head_approval.full_name_with_titles}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            NIP: {approvers.head_approval.nip || '—'}
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-xs text-amber-700 mt-1">
                                        Belum ada penugasan aktif untuk jabatan struktural Level 1 pada {effectiveDate}.
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-indigo-200 bg-indigo-50/40">
                        <CardContent className="p-4 flex items-start gap-3">
                            <div className="p-2.5 rounded-lg bg-indigo-600 text-white">
                                <UserCheck className="w-5 h-5" />
                            </div>
                            <div>
                                <p className="text-xs font-medium text-indigo-800 uppercase tracking-wider">
                                    Approver Level 2 (Kaur TU / Waka)
                                </p>
                                {approvers.tu_review ? (
                                    <>
                                        <p className="text-sm font-bold text-slate-900 mt-0.5">
                                            {approvers.tu_review.full_name_with_titles}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            NIP: {approvers.tu_review.nip || '—'}
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-xs text-amber-700 mt-1">
                                        Belum ada penugasan aktif untuk jabatan struktural Level 2 pada {effectiveDate}.
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-4 flex items-start gap-3">
                            <div className="p-2.5 rounded-lg bg-slate-800 text-white">
                                <GitBranch className="w-5 h-5" />
                            </div>
                            <div>
                                <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">
                                    Status Struktur ({effectiveDate})
                                </p>
                                <p className="text-sm font-bold text-slate-900 mt-0.5">
                                    {effectiveAssignments.length} Penugasan Aktif
                                </p>
                                <p className="text-xs text-slate-500">
                                    {units.length} Unit Kerja • {positions.length} Jabatan Terdaftar
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Navigasi Tab */}
                <div className="flex flex-wrap gap-2 border-b pb-3">
                    <Button
                        type="button"
                        variant={activeTab === 'assignments' ? 'default' : 'outline'}
                        onClick={() => setActiveTab('assignments')}
                        className={activeTab === 'assignments' ? 'bg-teal-700 hover:bg-teal-800' : ''}
                    >
                        <Award className="w-4 h-4 mr-2" />
                        Penugasan & SK Pejabat ({assignments.length})
                    </Button>
                    <Button
                        type="button"
                        variant={activeTab === 'units' ? 'default' : 'outline'}
                        onClick={() => setActiveTab('units')}
                        className={activeTab === 'units' ? 'bg-teal-700 hover:bg-teal-800' : ''}
                    >
                        <Building2 className="w-4 h-4 mr-2" />
                        Unit Kerja ({units.length})
                    </Button>
                    <Button
                        type="button"
                        variant={activeTab === 'positions' ? 'default' : 'outline'}
                        onClick={() => setActiveTab('positions')}
                        className={activeTab === 'positions' ? 'bg-teal-700 hover:bg-teal-800' : ''}
                    >
                        <Briefcase className="w-4 h-4 mr-2" />
                        Master Jabatan ({positions.length})
                    </Button>
                </div>

                {/* TAB 1: PENUGASAN & SK PEJABAT */}
                {activeTab === 'assignments' && (
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {canManage && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        {editingAssignment ? 'Edit Penugasan Jabatan' : 'Tambah Penugasan Baru'}
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Satu pegawai hanya boleh memiliki satu jabatan utama (primary) yang aktif pada periode yang sama.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={submitAssignment} className="space-y-3 text-sm">
                                        <div>
                                            <Label>Pegawai (GTK)</Label>
                                            <select
                                                className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                                value={assignmentForm.data.staff_id}
                                                onChange={e => assignmentForm.setData('staff_id', e.target.value)}
                                                required
                                            >
                                                <option value="">-- Pilih Pegawai --</option>
                                                {staffList.map(s => (
                                                    <option key={s.id} value={s.id}>
                                                        {s.full_name_with_titles} {s.nip ? `(${s.nip})` : ''}
                                                    </option>
                                                ))}
                                            </select>
                                            {assignmentForm.errors.staff_id && (
                                                <p className="text-xs text-rose-600 mt-1">{assignmentForm.errors.staff_id}</p>
                                            )}
                                        </div>

                                        <div>
                                            <Label>Jabatan</Label>
                                            <select
                                                className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                                value={assignmentForm.data.position_id}
                                                onChange={e => handlePositionSelect(e.target.value)}
                                                required
                                            >
                                                <option value="">-- Pilih Jabatan --</option>
                                                {positions.map(p => (
                                                    <option key={p.id} value={p.id}>
                                                        [{p.code}] {p.name} — {p.unit?.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {assignmentForm.errors.position_id && (
                                                <p className="text-xs text-rose-600 mt-1">{assignmentForm.errors.position_id}</p>
                                            )}
                                        </div>

                                        <div>
                                            <Label>Nomor SK Penugasan</Label>
                                            <Input
                                                value={assignmentForm.data.sk_number}
                                                onChange={e => assignmentForm.setData('sk_number', e.target.value)}
                                                placeholder="Contoh: SK/MTs/001/2026"
                                            />
                                        </div>

                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <Label>Mulai Berlaku (TMT)</Label>
                                                <Input
                                                    type="date"
                                                    value={assignmentForm.data.valid_from}
                                                    onChange={e => assignmentForm.setData('valid_from', e.target.value)}
                                                    required
                                                />
                                                {assignmentForm.errors.valid_from && (
                                                    <p className="text-xs text-rose-600 mt-1">{assignmentForm.errors.valid_from}</p>
                                                )}
                                            </div>
                                            <div>
                                                <Label>Berakhir (Opsional)</Label>
                                                <Input
                                                    type="date"
                                                    value={assignmentForm.data.valid_until}
                                                    onChange={e => assignmentForm.setData('valid_until', e.target.value)}
                                                />
                                                {assignmentForm.errors.valid_until && (
                                                    <p className="text-xs text-rose-600 mt-1">{assignmentForm.errors.valid_until}</p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <Label>Status Penugasan</Label>
                                                <select
                                                    className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                                    value={assignmentForm.data.status}
                                                    onChange={e => assignmentForm.setData('status', e.target.value as 'active' | 'ended' | 'revoked')}
                                                >
                                                    <option value="active">Aktif</option>
                                                    <option value="ended">Berakhir</option>
                                                    <option value="revoked">Dicabut</option>
                                                </select>
                                            </div>
                                            <div className="flex items-end pb-2">
                                                <label className="flex items-center gap-2 text-sm cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={assignmentForm.data.is_primary}
                                                        onChange={e => assignmentForm.setData('is_primary', e.target.checked)}
                                                    />
                                                    Jabatan Utama
                                                </label>
                                            </div>
                                        </div>
                                        {assignmentForm.errors.is_primary && (
                                            <p className="text-xs text-rose-600">{assignmentForm.errors.is_primary}</p>
                                        )}

                                        <div className="flex gap-2 pt-2">
                                            <Button
                                                type="submit"
                                                disabled={assignmentForm.processing}
                                                className="bg-teal-700 hover:bg-teal-800"
                                            >
                                                <Plus className="w-4 h-4 mr-1" />
                                                {editingAssignment ? 'Simpan Perubahan' : 'Simpan Penugasan'}
                                            </Button>
                                            {editingAssignment && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() => {
                                                        setEditingAssignment(null);
                                                        assignmentForm.reset();
                                                    }}
                                                >
                                                    Batal
                                                </Button>
                                            )}
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}

                        <Card className={canManage ? 'lg:col-span-2' : 'lg:col-span-3'}>
                            <CardHeader>
                                <CardTitle className="text-base">Daftar Penugasan & Riwayat Pejabat</CardTitle>
                                <CardDescription className="text-xs">
                                    Baris dengan tanda hijau berlaku efektif pada tanggal tinjauan ({effectiveDate}).
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {assignments.length === 0 ? (
                                    <p className="text-sm text-slate-500 py-6 text-center">
                                        Belum ada data penugasan jabatan.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-xs text-slate-500">
                                                    <th className="py-2 pr-3">Pegawai</th>
                                                    <th className="py-2 px-3">Jabatan & Unit</th>
                                                    <th className="py-2 px-3">Nomor SK & Masa Berlaku</th>
                                                    <th className="py-2 px-3">Status</th>
                                                    {canManage && <th className="py-2 pl-3 text-right">Aksi</th>}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {assignments.map(item => {
                                                    const isEffective = effectiveIds.has(item.id);
                                                    return (
                                                        <tr key={item.id} className={isEffective ? 'bg-emerald-50/40' : ''}>
                                                            <td className="py-3 pr-3">
                                                                {item.staff ? (
                                                                    <Link
                                                                        href={`/gtk/${item.staff.id}`}
                                                                        className="font-semibold text-slate-800 hover:text-teal-700"
                                                                    >
                                                                        {item.staff.full_name_with_titles}
                                                                    </Link>
                                                                ) : (
                                                                    <span className="text-slate-400">—</span>
                                                                )}
                                                                <div className="text-xs text-slate-500">
                                                                    NIP: {item.staff?.nip || '—'}
                                                                </div>
                                                            </td>
                                                            <td className="py-3 px-3">
                                                                <div className="font-medium text-slate-800 flex items-center gap-1.5">
                                                                    {item.position?.name}
                                                                    {item.is_primary && (
                                                                        <Badge className="bg-teal-100 text-teal-800 border-teal-200 text-[10px]">
                                                                            Utama
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                                <div className="text-xs text-slate-500">
                                                                    {item.unit?.name} ({item.position?.code})
                                                                </div>
                                                            </td>
                                                            <td className="py-3 px-3 text-xs">
                                                                <div className="font-medium text-slate-700">
                                                                    {item.sk_number || 'Tanpa Nomor SK'}
                                                                </div>
                                                                <div className="text-slate-500">
                                                                    {item.valid_from?.slice(0, 10)} s/d {item.valid_until ? item.valid_until.slice(0, 10) : 'Sekarang'}
                                                                </div>
                                                            </td>
                                                            <td className="py-3 px-3">
                                                                <div className="flex flex-col gap-1 items-start">
                                                                    <Badge
                                                                        variant="outline"
                                                                        className={
                                                                            item.status === 'active'
                                                                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                                                                                : 'border-slate-300 bg-slate-100 text-slate-600'
                                                                        }
                                                                    >
                                                                        {item.status === 'active' ? 'Aktif' : item.status === 'ended' ? 'Berakhir' : 'Dicabut'}
                                                                    </Badge>
                                                                    {isEffective && (
                                                                        <span className="inline-flex items-center gap-1 text-[11px] text-emerald-700 font-medium">
                                                                            <CheckCircle2 className="w-3 h-3" /> Efektif
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </td>
                                                            {canManage && (
                                                                <td className="py-3 pl-3 text-right">
                                                                    <div className="inline-flex gap-1">
                                                                        <Button
                                                                            type="button"
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            onClick={() => startEditAssignment(item)}
                                                                        >
                                                                            <Pencil className="w-3.5 h-3.5" />
                                                                        </Button>
                                                                        <Button
                                                                            type="button"
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            className="text-rose-600"
                                                                            onClick={() => {
                                                                                if (window.confirm('Hapus riwayat penugasan ini?')) {
                                                                                    router.delete(`/organisasi/penugasan/${item.id}`, {
                                                                                        preserveScroll: true,
                                                                                    });
                                                                                }
                                                                            }}
                                                                        >
                                                                            <Trash2 className="w-3.5 h-3.5" />
                                                                        </Button>
                                                                    </div>
                                                                </td>
                                                            )}
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* TAB 2: UNIT KERJA */}
                {activeTab === 'units' && (
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {canManage && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        {editingUnit ? 'Edit Unit Kerja' : 'Tambah Unit Kerja'}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={submitUnit} className="space-y-3 text-sm">
                                        <div>
                                            <Label>Kode Unit</Label>
                                            <Input
                                                value={unitForm.data.code}
                                                onChange={e => unitForm.setData('code', e.target.value)}
                                                placeholder="Contoh: TU, KUR, KES"
                                                required
                                            />
                                            {unitForm.errors.code && (
                                                <p className="text-xs text-rose-600 mt-1">{unitForm.errors.code}</p>
                                            )}
                                        </div>

                                        <div>
                                            <Label>Nama Unit Kerja</Label>
                                            <Input
                                                value={unitForm.data.name}
                                                onChange={e => unitForm.setData('name', e.target.value)}
                                                placeholder="Contoh: Urusan Tata Usaha"
                                                required
                                            />
                                        </div>

                                        <div>
                                            <Label>Unit Induk (Opsional)</Label>
                                            <select
                                                className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                                value={unitForm.data.parent_id}
                                                onChange={e => unitForm.setData('parent_id', e.target.value)}
                                            >
                                                <option value="">-- Tanpa Induk (Root) --</option>
                                                {units
                                                    .filter(u => !editingUnit || u.id !== editingUnit.id)
                                                    .map(u => (
                                                        <option key={u.id} value={u.id}>
                                                            [{u.code}] {u.name}
                                                        </option>
                                                    ))}
                                            </select>
                                            {unitForm.errors.parent_id && (
                                                <p className="text-xs text-rose-600 mt-1">{unitForm.errors.parent_id}</p>
                                            )}
                                        </div>

                                        <div>
                                            <Label>Tipe Unit</Label>
                                            <select
                                                className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                                value={unitForm.data.type}
                                                onChange={e => unitForm.setData('type', e.target.value)}
                                            >
                                                {Object.entries(UNIT_TYPES).map(([key, label]) => (
                                                    <option key={key} value={key}>{label}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <label className="flex items-center gap-2 text-sm pt-1 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={unitForm.data.is_active}
                                                onChange={e => unitForm.setData('is_active', e.target.checked)}
                                            />
                                            Unit Aktif
                                        </label>

                                        <div className="flex gap-2 pt-2">
                                            <Button type="submit" disabled={unitForm.processing} className="bg-teal-700 hover:bg-teal-800">
                                                <Plus className="w-4 h-4 mr-1" />
                                                {editingUnit ? 'Simpan' : 'Tambah Unit'}
                                            </Button>
                                            {editingUnit && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() => {
                                                        setEditingUnit(null);
                                                        unitForm.reset();
                                                    }}
                                                >
                                                    Batal
                                                </Button>
                                            )}
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}

                        <Card className={canManage ? 'lg:col-span-2' : 'lg:col-span-3'}>
                            <CardHeader>
                                <CardTitle className="text-base">Hierarki Unit Organisasi Madrasah</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-slate-500">
                                                <th className="py-2 pr-3">Kode</th>
                                                <th className="py-2 px-3">Nama Unit</th>
                                                <th className="py-2 px-3">Induk</th>
                                                <th className="py-2 px-3">Tipe</th>
                                                <th className="py-2 px-3">Jabatan</th>
                                                {canManage && <th className="py-2 pl-3 text-right">Aksi</th>}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {units.map(unit => (
                                                <tr key={unit.id}>
                                                    <td className="py-2.5 pr-3 font-mono text-xs font-semibold text-slate-700">
                                                        {unit.code}
                                                    </td>
                                                    <td className="py-2.5 px-3 font-medium text-slate-800">
                                                        {unit.name}
                                                        {!unit.is_active && (
                                                            <Badge variant="secondary" className="ml-2 text-[10px]">Nonaktif</Badge>
                                                        )}
                                                    </td>
                                                    <td className="py-2.5 px-3 text-xs text-slate-500">
                                                        {unit.parent ? `${unit.parent.name} (${unit.parent.code})` : '—'}
                                                    </td>
                                                    <td className="py-2.5 px-3 text-xs">
                                                        <Badge variant="outline">{UNIT_TYPES[unit.type] || unit.type}</Badge>
                                                    </td>
                                                    <td className="py-2.5 px-3 text-xs text-slate-600">
                                                        {unit.positions_count ?? 0} jabatan
                                                    </td>
                                                    {canManage && (
                                                        <td className="py-2.5 pl-3 text-right">
                                                            <div className="inline-flex gap-1">
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    onClick={() => startEditUnit(unit)}
                                                                >
                                                                    <Pencil className="w-3.5 h-3.5" />
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="text-rose-600"
                                                                    onClick={() => {
                                                                        if (window.confirm(`Hapus unit ${unit.name}?`)) {
                                                                            router.delete(`/organisasi/unit/${unit.id}`, {
                                                                                preserveScroll: true,
                                                                            });
                                                                        }
                                                                    }}
                                                                >
                                                                    <Trash2 className="w-3.5 h-3.5" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* TAB 3: MASTER JABATAN */}
                {activeTab === 'positions' && (
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {canManage && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        {editingPosition ? 'Edit Jabatan' : 'Tambah Jabatan'}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={submitPosition} className="space-y-3 text-sm">
                                        <div>
                                            <Label>Unit Kerja</Label>
                                            <select
                                                className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                                value={positionForm.data.org_unit_id}
                                                onChange={e => positionForm.setData('org_unit_id', e.target.value)}
                                                required
                                            >
                                                <option value="">-- Pilih Unit Kerja --</option>
                                                {units.map(u => (
                                                    <option key={u.id} value={u.id}>
                                                        [{u.code}] {u.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <Label>Kode Jabatan</Label>
                                            <Input
                                                value={positionForm.data.code}
                                                onChange={e => positionForm.setData('code', e.target.value)}
                                                placeholder="Contoh: KAMAD, KAUR-TU"
                                                required
                                            />
                                            {positionForm.errors.code && (
                                                <p className="text-xs text-rose-600 mt-1">{positionForm.errors.code}</p>
                                            )}
                                        </div>

                                        <div>
                                            <Label>Nama Jabatan</Label>
                                            <Input
                                                value={positionForm.data.name}
                                                onChange={e => positionForm.setData('name', e.target.value)}
                                                placeholder="Contoh: Kepala Urusan Tata Usaha"
                                                required
                                            />
                                        </div>

                                        <div>
                                            <Label>Level Otoritas / Hierarki</Label>
                                            <select
                                                className="mt-1 h-9 w-full rounded-md border px-3 text-sm bg-white"
                                                value={positionForm.data.level}
                                                onChange={e => positionForm.setData('level', Number(e.target.value))}
                                            >
                                                {Object.entries(LEVEL_LABELS).map(([lvl, label]) => (
                                                    <option key={lvl} value={lvl}>{label}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <label className="flex items-center gap-2 text-sm pt-1 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={positionForm.data.is_structural}
                                                onChange={e => positionForm.setData('is_structural', e.target.checked)}
                                            />
                                            Jabatan Struktural (Pejabat Approver)
                                        </label>

                                        <div className="flex gap-2 pt-2">
                                            <Button type="submit" disabled={positionForm.processing} className="bg-teal-700 hover:bg-teal-800">
                                                <Plus className="w-4 h-4 mr-1" />
                                                {editingPosition ? 'Simpan' : 'Tambah Jabatan'}
                                            </Button>
                                            {editingPosition && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() => {
                                                        setEditingPosition(null);
                                                        positionForm.reset();
                                                    }}
                                                >
                                                    Batal
                                                </Button>
                                            )}
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}

                        <Card className={canManage ? 'lg:col-span-2' : 'lg:col-span-3'}>
                            <CardHeader>
                                <CardTitle className="text-base">Daftar Master Jabatan</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-slate-500">
                                                <th className="py-2 pr-3">Kode</th>
                                                <th className="py-2 px-3">Nama Jabatan</th>
                                                <th className="py-2 px-3">Unit Kerja</th>
                                                <th className="py-2 px-3">Level & Jenis</th>
                                                {canManage && <th className="py-2 pl-3 text-right">Aksi</th>}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {positions.map(pos => (
                                                <tr key={pos.id}>
                                                    <td className="py-2.5 pr-3 font-mono text-xs font-semibold text-slate-700">
                                                        {pos.code}
                                                    </td>
                                                    <td className="py-2.5 px-3 font-medium text-slate-800">
                                                        {pos.name}
                                                    </td>
                                                    <td className="py-2.5 px-3 text-xs text-slate-600">
                                                        {pos.unit ? `${pos.unit.name} (${pos.unit.code})` : '—'}
                                                    </td>
                                                    <td className="py-2.5 px-3 text-xs">
                                                        <div className="flex flex-wrap gap-1">
                                                            <Badge variant="outline">Level {pos.level}</Badge>
                                                            {pos.is_structural ? (
                                                                <Badge className="bg-amber-100 text-amber-800 border-amber-200">
                                                                    Struktural
                                                                </Badge>
                                                            ) : (
                                                                <Badge variant="secondary">Fungsional / Pelaksana</Badge>
                                                            )}
                                                        </div>
                                                    </td>
                                                    {canManage && (
                                                        <td className="py-2.5 pl-3 text-right">
                                                            <div className="inline-flex gap-1">
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    onClick={() => startEditPosition(pos)}
                                                                >
                                                                    <Pencil className="w-3.5 h-3.5" />
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="text-rose-600"
                                                                    onClick={() => {
                                                                        if (window.confirm(`Hapus jabatan ${pos.name}?`)) {
                                                                            router.delete(`/organisasi/jabatan/${pos.id}`, {
                                                                                preserveScroll: true,
                                                                            });
                                                                        }
                                                                    }}
                                                                >
                                                                    <Trash2 className="w-3.5 h-3.5" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
