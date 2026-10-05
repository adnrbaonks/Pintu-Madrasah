import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';

interface Log {
    id: string;
    action: string;
    model_type: string;
    model_id: string;
    ip_address: string;
    created_at: string;
    user: {
        name: string;
    } | null;
}

interface Pagination {
    data: Log[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
}

interface Props {
    logs: Pagination;
    filters: { search?: string };
}

export default function Index({ logs, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            router.get(
                '/audit-logs',
                { search },
                { preserveState: true, preserveScroll: true }
            );
        }
    };

    const getActionBadge = (action: string) => {
        switch (action) {
            case 'created':
                return <Badge className="bg-teal-600 hover:bg-teal-700">Created</Badge>;
            case 'updated':
                return <Badge className="bg-blue-500 hover:bg-blue-600">Updated</Badge>;
            case 'deleted':
                return <Badge variant="destructive">Deleted</Badge>;
            default:
                return <Badge variant="secondary">{action}</Badge>;
        }
    };

    const getModelShortName = (modelType: string) => {
        const parts = modelType.split('\\');
        return parts[parts.length - 1];
    };

    return (
        <AppLayout>
            <Head title="Audit Logs - Security & Compliance" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-neutral-900">Audit Logs</h1>
                    <p className="text-neutral-500">
                        Catatan aktivitas dan perubahan data (Audit Trail) untuk keamanan dan kepatuhan sistem.
                    </p>
                </div>

                <div className="flex justify-between items-center bg-white p-4 rounded-xl border border-neutral-200 shadow-sm">
                    <div className="w-full max-w-sm">
                        <Input
                            placeholder="Cari aksi, entitas, atau pengguna (Tekan Enter)..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={handleSearch}
                            className="bg-neutral-50 focus-visible:ring-teal-600"
                        />
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-neutral-200 shadow-sm overflow-hidden">
                    <Table>
                        <TableHeader className="bg-neutral-50/50">
                            <TableRow>
                                <TableHead className="w-[180px]">Waktu</TableHead>
                                <TableHead>Pengguna</TableHead>
                                <TableHead>Aksi</TableHead>
                                <TableHead>Entitas (Model)</TableHead>
                                <TableHead>IP Address</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.length > 0 ? (
                                logs.data.map((log) => (
                                    <TableRow key={log.id} className="hover:bg-neutral-50/50">
                                        <TableCell className="font-medium text-neutral-600 whitespace-nowrap">
                                            {new Date(log.created_at).toLocaleString('id-ID')}
                                        </TableCell>
                                        <TableCell>{log.user?.name || 'Sistem'}</TableCell>
                                        <TableCell>{getActionBadge(log.action)}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-neutral-700">
                                                    {getModelShortName(log.model_type)}
                                                </span>
                                                <span className="text-xs text-neutral-400 font-mono truncate max-w-[120px]" title={log.model_id}>
                                                    #{log.model_id.substring(0, 8)}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-neutral-500 font-mono text-sm">
                                            {log.ip_address}
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="h-24 text-center text-neutral-500">
                                        Tidak ada catatan log ditemukan.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Pagination */}
                <div className="flex items-center justify-between">
                    <p className="text-sm text-neutral-500">
                        Halaman {logs.current_page} dari {logs.last_page}
                    </p>
                    <div className="flex gap-1">
                        {logs.links.map((link, i) => (
                            <button
                                key={i}
                                onClick={() => link.url && router.get(link.url)}
                                disabled={!link.url}
                                className={`px-3 py-1 rounded-md text-sm border ${
                                    link.active
                                        ? 'bg-teal-600 text-white border-teal-700 font-medium'
                                        : 'bg-white text-neutral-700 border-neutral-200 hover:bg-neutral-50 disabled:opacity-50'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
