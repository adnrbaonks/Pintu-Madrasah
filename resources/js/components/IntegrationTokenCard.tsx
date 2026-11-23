import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { KeyRound, Plus, Ban, ShieldCheck, Copy } from 'lucide-react';
import { toast } from 'sonner';

export interface IntegrationTokenItem {
    id: string;
    label: string;
    purpose: string;
    masked_token: string;
    expires_at: string | null;
    last_used_at: string | null;
    revoked_at: string | null;
    created_at: string;
}

export function IntegrationTokenCard({
    purpose,
    title,
    tokens = [],
}: {
    purpose: 'emis_siswa' | 'emis_gtk';
    title: string;
    tokens?: IntegrationTokenItem[];
}) {
    const { flash } = usePage<{ flash: { new_integration_token?: string } }>().props;
    const [showCreate, setShowCreate] = useState(false);

    const form = useForm({
        purpose,
        label: `Bookmarklet ${purpose === 'emis_siswa' ? 'EMIS Siswa' : 'EMIS GTK'}`,
        expires_in_days: 30,
    });

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/emis/tokens', {
            preserveScroll: true,
            onSuccess: () => {
                setShowCreate(false);
                toast.success('Token integrasi bookmarklet berhasil diterbitkan.');
            },
        });
    };

    const copyText = (text: string) => {
        navigator.clipboard?.writeText(text);
        toast.success('Token disalin ke clipboard.');
    };

    return (
        <Card className="border-slate-200">
            <CardHeader className="pb-3">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                    <div>
                        <CardTitle className="text-base flex items-center gap-2">
                            <KeyRound className="w-4 h-4 text-teal-600" />
                            {title}
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Token kredensial terkelola (SHA-256) untuk mengautentikasi pengiriman data dari bookmarklet browser ke PINTU.
                        </CardDescription>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setShowCreate(v => !v)}
                        className="gap-1.5"
                    >
                        <Plus className="w-3.5 h-3.5" />
                        Buat Token Bookmarklet
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                {flash?.new_integration_token && (
                    <div className="p-3 rounded-lg border border-emerald-300 bg-emerald-50 space-y-1.5">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-emerald-800 flex items-center gap-1.5">
                                <ShieldCheck className="w-4 h-4" />
                                Token Baru (Salin sekarang — hanya ditampilkan sekali):
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="h-7 text-xs gap-1 text-emerald-800"
                                onClick={() => copyText(flash.new_integration_token!)}
                            >
                                <Copy className="w-3.5 h-3.5" /> Salin
                            </Button>
                        </div>
                        <code className="block font-mono text-xs bg-white p-2 rounded border break-all">
                            {flash.new_integration_token}
                        </code>
                    </div>
                )}

                {showCreate && (
                    <form onSubmit={handleCreate} className="p-3 rounded-lg border bg-slate-50 flex flex-col sm:flex-row gap-2 items-end">
                        <div className="flex-1 w-full">
                            <label className="text-xs font-medium text-slate-700">Label Perangkat / Operator</label>
                            <Input
                                value={form.data.label}
                                onChange={e => form.setData('label', e.target.value)}
                                className="h-8 bg-white text-xs mt-1"
                                required
                            />
                        </div>
                        <div className="w-full sm:w-36">
                            <label className="text-xs font-medium text-slate-700">Masa Berlaku (Hari)</label>
                            <Input
                                type="number"
                                min={1}
                                max={365}
                                value={form.data.expires_in_days}
                                onChange={e => form.setData('expires_in_days', Number(e.target.value))}
                                className="h-8 bg-white text-xs mt-1"
                                required
                            />
                        </div>
                        <Button type="submit" size="sm" disabled={form.processing} className="bg-teal-700 hover:bg-teal-800 h-8">
                            Terbitkan
                        </Button>
                    </form>
                )}

                {tokens.length === 0 ? (
                    <p className="text-xs text-slate-400">
                        Belum ada token integrasi bookmarklet yang diterbitkan.
                    </p>
                ) : (
                    <div className="divide-y border rounded-lg">
                        {tokens.slice(0, 5).map(t => {
                            const isRevoked = !!t.revoked_at;
                            return (
                                <div key={t.id} className="p-2.5 flex justify-between items-center text-xs">
                                    <div>
                                        <div className="font-semibold text-slate-800 flex items-center gap-2">
                                            {t.label}
                                            <code className="font-mono text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">
                                                {t.masked_token}
                                            </code>
                                        </div>
                                        <div className="text-[11px] text-slate-500">
                                            Kedaluwarsa: {t.expires_at ? t.expires_at.slice(0, 10) : '—'} •{' '}
                                            Dipakai: {t.last_used_at ? t.last_used_at.slice(0, 16).replace('T', ' ') : 'Belum pernah'}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {isRevoked ? (
                                            <Badge variant="outline" className="text-rose-600 border-rose-200 bg-rose-50 text-[10px]">
                                                Dicabut
                                            </Badge>
                                        ) : (
                                            <>
                                                <Badge variant="outline" className="text-emerald-700 border-emerald-200 bg-emerald-50 text-[10px]">
                                                    Aktif
                                                </Badge>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 text-rose-600 hover:text-rose-700 text-xs gap-1"
                                                    onClick={() => router.post(`/emis/tokens/${t.id}/revoke`, {}, { preserveScroll: true })}
                                                >
                                                    <Ban className="w-3 h-3" /> Cabut
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
