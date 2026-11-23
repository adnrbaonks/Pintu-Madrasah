import React from 'react';
import { Badge } from '@/components/ui/badge';
import { ArrowRight, CheckCircle2, Clock, AlertTriangle, XCircle, FileText, ExternalLink } from 'lucide-react';

export interface ChangeRequestItemData {
    id: string;
    field: string;
    old_value: string | null;
    proposed_value: string | null;
    owner_system: string;
    change_policy: string;
    apply_status: string;
    apply_error: string | null;
}

export interface ChangeAttachmentData {
    id: string;
    path: string;
    original_name: string | null;
    mime: string | null;
    size: number;
    checksum: string | null;
}

export interface ChangeApprovalData {
    id: string;
    stage: string;
    decision: string;
    notes: string | null;
    decided_at: string;
    actor?: {
        id: string;
        name: string;
        username: string;
        role: string;
    } | null;
}

export interface ChangeHistoryData {
    id: string;
    item_id: string;
    applied_value: string | null;
    applied_at: string;
    source: string;
}

export type OwnershipDictionary = Record<
    string,
    {
        owner_system: string;
        change_policy: string;
        label: string;
    }
>;

const STATUS_META: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draf', className: 'bg-slate-100 text-slate-700 border-slate-300' },
    submitted: { label: 'Diajukan', className: 'bg-blue-100 text-blue-800 border-blue-200' },
    under_tu_review: { label: 'Review Tata Usaha', className: 'bg-amber-100 text-amber-800 border-amber-300' },
    revision_requested: { label: 'Perlu Revisi', className: 'bg-orange-100 text-orange-800 border-orange-300' },
    verified: { label: 'Terverifikasi TU', className: 'bg-cyan-100 text-cyan-800 border-cyan-200' },
    pending_head_approval: { label: 'Menunggu Kamad', className: 'bg-indigo-100 text-indigo-800 border-indigo-200' },
    approved: { label: 'Disetujui Kamad', className: 'bg-teal-100 text-teal-800 border-teal-200' },
    rejected: { label: 'Ditolak', className: 'bg-rose-100 text-rose-800 border-rose-200' },
    applying: { label: 'Menerapkan...', className: 'bg-purple-100 text-purple-800 border-purple-200' },
    pending_external_sync: { label: 'Menunggu Sinkron EMIS', className: 'bg-amber-100 text-amber-900 border-amber-300' },
    completed: { label: 'Selesai Diterapkan', className: 'bg-emerald-100 text-emerald-800 border-emerald-200' },
    failed: { label: 'Gagal Terapkan', className: 'bg-rose-100 text-rose-800 border-rose-300' },
};

export function StatusBadge({ status }: { status: string }) {
    const meta = STATUS_META[status] || { label: status, className: 'bg-slate-100 text-slate-700' };
    return (
        <Badge variant="outline" className={meta.className}>
            {meta.label}
        </Badge>
    );
}

export function FieldDiff({
    items,
    ownership,
}: {
    items: ChangeRequestItemData[];
    ownership: OwnershipDictionary;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-xs text-slate-500">
                        <th className="py-2 pr-3">Kolom Data</th>
                        <th className="py-2 px-3">Nilai Lama</th>
                        <th className="py-2 px-3">Nilai Diajukan</th>
                        <th className="py-2 px-3">Otoritas</th>
                        <th className="py-2 pl-3">Status Penerapan</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {items.map(item => {
                        const fieldLabel = ownership[item.field]?.label || item.field;
                        const isEmis = item.owner_system === 'emis';
                        return (
                            <tr key={item.id}>
                                <td className="py-3 pr-3 font-medium text-slate-800">
                                    {fieldLabel}
                                    <div className="text-[11px] font-mono text-slate-400">{item.field}</div>
                                </td>
                                <td className="py-3 px-3 text-slate-500">
                                    {item.old_value || <span className="italic text-slate-400">(Kosong)</span>}
                                </td>
                                <td className="py-3 px-3">
                                    <div className="inline-flex items-center gap-1.5 font-semibold text-teal-800 bg-teal-50 px-2 py-0.5 rounded border border-teal-200">
                                        <ArrowRight className="w-3.5 h-3.5 text-teal-600" />
                                        {item.proposed_value}
                                    </div>
                                </td>
                                <td className="py-3 px-3">
                                    {isEmis ? (
                                        <Badge variant="outline" className="text-xs bg-amber-50 text-amber-800 border-amber-200">
                                            EMIS Kemenag
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="text-xs bg-teal-50 text-teal-700 border-teal-200">
                                            Lokal PINTU
                                        </Badge>
                                    )}
                                </td>
                                <td className="py-3 pl-3 text-xs">
                                    {item.apply_status === 'applied' && (
                                        <span className="inline-flex items-center gap-1 text-emerald-700 font-medium">
                                            <CheckCircle2 className="w-3.5 h-3.5" /> Diterapkan
                                        </span>
                                    )}
                                    {item.apply_status === 'pending_external_sync' && (
                                        <div>
                                            <span className="inline-flex items-center gap-1 text-amber-700 font-medium">
                                                <AlertTriangle className="w-3.5 h-3.5" /> Tugas Sinkron EMIS
                                            </span>
                                            {item.apply_error && (
                                                <p className="text-[11px] text-slate-500 mt-0.5">{item.apply_error}</p>
                                            )}
                                        </div>
                                    )}
                                    {item.apply_status === 'pending' && (
                                        <span className="inline-flex items-center gap-1 text-slate-500">
                                            <Clock className="w-3.5 h-3.5" /> Menunggu
                                        </span>
                                    )}
                                    {item.apply_status === 'failed' && (
                                        <span className="inline-flex items-center gap-1 text-rose-600 font-medium">
                                            <XCircle className="w-3.5 h-3.5" /> Gagal
                                        </span>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

export function AttachmentList({ attachments }: { attachments: ChangeAttachmentData[] }) {
    if (!attachments || attachments.length === 0) {
        return <p className="text-xs text-slate-400">Tidak ada lampiran dokumen bukti.</p>;
    }

    const getFilename = (path: string) => {
        const parts = path.split('/');
        return parts[parts.length - 1];
    };

    return (
        <ul className="space-y-2 text-sm">
            {attachments.map(att => {
                const filename = getFilename(att.path);
                return (
                    <li key={att.id} className="p-2.5 rounded border bg-slate-50 flex justify-between items-center">
                        <div className="flex items-center gap-2">
                            <FileText className="w-4 h-4 text-teal-600 shrink-0" />
                            <div>
                                <p className="font-medium text-slate-800 text-xs">
                                    {att.original_name || filename}
                                </p>
                                <p className="text-[11px] text-slate-500">
                                    {Math.round((att.size || 0) / 1024)} KB • {att.mime || 'Berkas'}
                                </p>
                            </div>
                        </div>
                        <a
                            href={`/archives/staff_change_attachment/${filename}`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:underline"
                        >
                            Lihat <ExternalLink className="w-3 h-3" />
                        </a>
                    </li>
                );
            })}
        </ul>
    );
}

export function ApprovalTimeline({ approvals }: { approvals: ChangeApprovalData[] }) {
    if (!approvals || approvals.length === 0) {
        return <p className="text-xs text-slate-400">Belum ada riwayat keputusan verifikasi atau persetujuan.</p>;
    }

    return (
        <ol className="space-y-3 text-sm">
            {approvals.map(app => (
                <li key={app.id} className="p-3 rounded-lg border bg-slate-50/70 space-y-1">
                    <div className="flex justify-between items-center">
                        <span className="font-semibold text-slate-800 text-xs uppercase tracking-wider">
                            {app.stage === 'head_approval' ? 'Tahap 2: Kepala Madrasah' : 'Tahap 1: Verifikasi Tata Usaha'}
                        </span>
                        <StatusBadge status={app.decision} />
                    </div>
                    <p className="text-xs text-slate-600">
                        Oleh: <span className="font-medium text-slate-800">{app.actor?.name || 'Pejabat'}</span> •{' '}
                        {app.decided_at ? app.decided_at.slice(0, 16).replace('T', ' ') : ''}
                    </p>
                    {app.notes && (
                        <p className="text-xs text-slate-700 bg-white p-2 rounded border mt-1">
                            Catatan: "{app.notes}"
                        </p>
                    )}
                </li>
            ))}
        </ol>
    );
}
