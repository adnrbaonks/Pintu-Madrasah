import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Database, Lock, Edit3, ShieldAlert, Search, ArrowDownToLine, ArrowUpFromLine, ArrowLeftRight } from 'lucide-react';

interface FieldRule {
    owner_system: string;
    source_system?: string;
    sync_direction?: string;
    change_policy: string;
    sensitive: boolean;
    label: string;
}

interface Props {
    dictionary: Record<string, Record<string, FieldRule>>;
}

const ENTITY_META: Record<string, { title: string; description: string }> = {
    staff: {
        title: 'Entitas GTK (Guru & Tenaga Kependidikan)',
        description: 'Atribut identitas resmi kepegawaian dari EMIS GTK dan data operasional lokal madrasah.',
    },
    student: {
        title: 'Entitas Siswa (Peserta Didik)',
        description: 'Atribut data induk siswa dari EMIS Siswa, PPDB, dan administrasi lokal madrasah.',
    },
    academic: {
        title: 'Entitas Akademik & Penilaian',
        description: 'Kepemilikan master tahun ajaran, kelas, rombel, mata pelajaran, dan nilai leger/RDM.',
    },
};

export default function DataDictionary({ dictionary }: Props) {
    const entityKeys = Object.keys(dictionary);
    const [activeEntity, setActiveEntity] = useState<string>(entityKeys[0] || 'staff');
    const [search, setSearch] = useState('');

    const currentFields = dictionary[activeEntity] || {};
    const filteredEntries = Object.entries(currentFields).filter(([field, rule]) => {
        if (!search.trim()) return true;
        const q = search.toLowerCase();
        return (
            field.toLowerCase().includes(q) ||
            (rule.label || '').toLowerCase().includes(q) ||
            (rule.owner_system || '').toLowerCase().includes(q)
        );
    });

    const renderOwnerBadge = (owner: string) => {
        if (owner === 'emis') {
            return (
                <Badge className="bg-amber-100 text-amber-800 border-amber-200 hover:bg-amber-100">
                    EMIS Kemenag
                </Badge>
            );
        }
        if (owner === 'rdm') {
            return (
                <Badge className="bg-purple-100 text-purple-800 border-purple-200 hover:bg-purple-100">
                    RDM (Rapor Digital)
                </Badge>
            );
        }
        return (
            <Badge className="bg-teal-100 text-teal-800 border-teal-200 hover:bg-teal-100">
                PINTU Madrasah (Lokal)
            </Badge>
        );
    };

    const renderPolicyBadge = (policy: string) => {
        if (policy === 'read_only' || policy === 'external_authority') {
            return (
                <Badge variant="outline" className="gap-1 bg-slate-100 text-slate-700 border-slate-300">
                    <Lock className="w-3 h-3 text-amber-600" />
                    Read-Only (Otoritas Pusat)
                </Badge>
            );
        }
        return (
            <Badge variant="outline" className="gap-1 bg-emerald-50 text-emerald-700 border-emerald-200">
                <Edit3 className="w-3 h-3" />
                Direct (Dapat Diubah Lokal)
            </Badge>
        );
    };

    const renderSyncDirection = (dir?: string) => {
        if (dir === 'inbound') {
            return (
                <span className="inline-flex items-center gap-1 text-xs text-amber-700 font-medium">
                    <ArrowDownToLine className="w-3.5 h-3.5" /> Inbound
                </span>
            );
        }
        if (dir === 'outbound') {
            return (
                <span className="inline-flex items-center gap-1 text-xs text-blue-700 font-medium">
                    <ArrowUpFromLine className="w-3.5 h-3.5" /> Outbound
                </span>
            );
        }
        if (dir === 'bidirectional') {
            return (
                <span className="inline-flex items-center gap-1 text-xs text-teal-700 font-medium">
                    <ArrowLeftRight className="w-3.5 h-3.5" /> Dua Arah
                </span>
            );
        }
        return <span className="text-xs text-slate-500">Manual / Lokal</span>;
    };

    return (
        <AppLayout title="Kamus Kepemilikan Data">
            <Head title="Kamus Kepemilikan Data" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <Database className="w-6 h-6 text-teal-700" />
                    Kamus Kepemilikan Data (Data Ownership Dictionary)
                </h1>
                <p className="text-sm text-slate-500 mt-1">
                    Referensi sumber kebenaran tunggal (Single Source of Truth), arah sinkronisasi, kebijakan perubahan, dan klasifikasi privasi PII.
                </p>
            </div>

            {/* Pemilih Entitas & Pencarian */}
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                <div className="flex flex-wrap gap-2">
                    {entityKeys.map((key) => {
                        const count = Object.keys(dictionary[key] || {}).length;
                        const isActive = activeEntity === key;
                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setActiveEntity(key)}
                                className={`px-4 py-2 rounded-lg text-sm font-medium border transition-colors ${
                                    isActive
                                        ? 'bg-teal-700 text-white border-teal-700 shadow-sm'
                                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                                }`}
                            >
                                {key.toUpperCase()} ({count})
                            </button>
                        );
                    })}
                </div>

                <div className="relative w-full sm:w-72">
                    <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                    <Input
                        placeholder="Cari kode atribut atau label..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="pl-9 bg-white"
                    />
                </div>
            </div>

            <Card className="border-t-4 border-t-teal-600">
                <CardHeader>
                    <CardTitle className="text-lg">
                        {ENTITY_META[activeEntity]?.title || `Entitas: ${activeEntity}`}
                    </CardTitle>
                    <CardDescription>
                        {ENTITY_META[activeEntity]?.description || 'Daftar aturan kepemilikan field.'}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="rounded-md border overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode Field</TableHead>
                                    <TableHead>Label Atribut</TableHead>
                                    <TableHead>Pemilik Otoritatif</TableHead>
                                    <TableHead>Sumber & Arah</TableHead>
                                    <TableHead>Kebijakan Perubahan</TableHead>
                                    <TableHead>Klasifikasi PII</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredEntries.length > 0 ? (
                                    filteredEntries.map(([field, rule]) => (
                                        <TableRow key={field}>
                                            <TableCell className="font-mono text-xs font-semibold text-slate-800">
                                                {field}
                                            </TableCell>
                                            <TableCell className="font-medium text-slate-700">
                                                {rule.label}
                                            </TableCell>
                                            <TableCell>
                                                {renderOwnerBadge(rule.owner_system)}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="text-xs font-medium text-slate-700">
                                                        {rule.source_system || rule.owner_system.toUpperCase()}
                                                    </span>
                                                    {renderSyncDirection(rule.sync_direction)}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {renderPolicyBadge(rule.change_policy)}
                                            </TableCell>
                                            <TableCell>
                                                {rule.sensitive ? (
                                                    <Badge className="gap-1 bg-rose-100 text-rose-800 border-rose-200 hover:bg-rose-100">
                                                        <ShieldAlert className="w-3 h-3" />
                                                        Sensitif (PII)
                                                    </Badge>
                                                ) : (
                                                    <span className="text-xs text-slate-400">Publik Internal</span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-slate-500">
                                            Tidak ada atribut yang cocok dengan pencarian.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
