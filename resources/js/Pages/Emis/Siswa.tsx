import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { RefreshCw, CheckCircle, AlertCircle, Eye, XIcon, Trash2, Download, Save } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { toast } from 'sonner';

type Step = 'idle' | 'pulling' | 'pull_done' | 'review' | 'committing' | 'commit_done' | 'error';

export default function EmisSiswaSync() {
    const [step, setStep] = useState<Step>('idle');
    const [tokenActive, setTokenActive] = useState(false);
    const [loading, setLoading] = useState(true);
    const [progressData, setProgressData] = useState<any>(null);
    
    const [stagingData, setStagingData] = useState<any[]>([]);
    const [loadingStaging, setLoadingStaging] = useState(false);
    const [committing, setCommitting] = useState(false);
    
    const [showClearConfirm, setShowClearConfirm] = useState(false);
    const [showCommitConfirm, setShowCommitConfirm] = useState(false);
    const [showCancelConfirm, setShowCancelConfirm] = useState(false);
    const [reviewPage, setReviewPage] = useState(1);

    const ITEMS_PER_PAGE = 20;
    const totalPages = Math.ceil(stagingData.length / ITEMS_PER_PAGE);
    const paginatedStagingData = stagingData.slice((reviewPage - 1) * ITEMS_PER_PAGE, reviewPage * ITEMS_PER_PAGE);

    const checkStatus = async () => {
        try {
            const res = await fetch('/api/emis/status');
            const data = await res.json();
            setTokenActive(data.emis_siswa?.active || false);

            // Cek pull progress
            const pullProg = data.emis_siswa?.pull_progress;
            const commitProg = data.emis_siswa?.commit_progress;

            if (pullProg && pullProg.progress < 100) {
                setProgressData(pullProg);
                setStep('pulling');
            } else if (pullProg && pullProg.progress === 100 && step === 'pulling') {
                setProgressData(pullProg);
                setStep('pull_done');
            } else if (commitProg && commitProg.progress < 100) {
                setProgressData(commitProg);
                setStep('committing');
            } else if (commitProg && commitProg.progress === 100 && step === 'committing') {
                setProgressData(commitProg);
                setStep('commit_done');
            }
        } catch (e) {
            console.error('Error fetching status', e);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        checkStatus();
        const interval = setInterval(checkStatus, 2000);
        return () => clearInterval(interval);
    }, [step]);

    // ── Step 1: Pull Data ──────────────────────────────────
    const handlePull = async () => {
        setStep('pulling');
        setProgressData(null);
        try {
            await fetch('/api/emis/pull', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ clear_staging: true })
            });
        } catch (e) {
            toast.error('Gagal menghubungi server.');
            setStep('error');
        }
    };

    // ── Step 2: Load Staging ───────────────────────────────
    const loadStagingData = async () => {
        setLoadingStaging(true);
        setReviewPage(1);
        try {
            const res = await fetch('/api/emis/staging');
            const data = await res.json();
            setStagingData(data.data || []);
            setStep('review');
        } catch (e) {
            toast.error('Gagal mengambil data review.');
        } finally {
            setLoadingStaging(false);
        }
    };

    // ── Step 3: Commit ke DB ───────────────────────────────
    const handleCommit = async () => {
        setCommitting(true);
        try {
            const res = await fetch('/api/emis/commit', { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                toast.success(data.message);
                setShowCommitConfirm(false);
                setStep('committing');
                setProgressData(null);
            } else {
                toast.error('Gagal: ' + data.message);
            }
        } catch (e) {
            toast.error('Gagal menyimpan data.');
        } finally {
            setCommitting(false);
        }
    };

    // ── Cancel ─────────────────────────────────────────────
    const handleCancelSync = async () => {
        try {
            await fetch('/api/emis/cancel-sync', { method: 'POST' });
            setStep('idle');
            setProgressData(null);
            toast.info('Sinkronisasi dibatalkan.');
        } catch (e) {
            toast.error('Gagal membatalkan.');
        } finally {
            setShowCancelConfirm(false);
        }
    };

    const bookmarkletCode = `javascript:(function(){let t=null;const r=/eyJ[a-zA-Z0-9_-]+\\.eyJ[a-zA-Z0-9_-]+\\.[a-zA-Z0-9_-]+/;for(let i=0;i<localStorage.length;i++){let v=localStorage.getItem(localStorage.key(i));let m=v?v.match(r):null;if(m){t=m[0];break;}}if(!t){for(let i=0;i<sessionStorage.length;i++){let v=sessionStorage.getItem(sessionStorage.key(i));let m=v?v.match(r):null;if(m){t=m[0];break;}}}if(!t&&document.cookie){let m=document.cookie.match(r);if(m){t=m[0];}}if(!t){alert('Gagal! Token EMIS tidak ditemukan.');return;}fetch('${window.location.origin}/api/emis/token',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({token:t,source:'bookmarklet'})}).then(r=>r.json()).then(d=>{if(d.success){alert('Token EMIS berhasil dikirim!');}else{alert('Gagal: '+d.message);}}).catch(e=>alert('Error: '+e));})();`;

    const isBusy = step === 'pulling' || step === 'committing';

    return (
        <AppLayout>
            <Head title="Sinkronisasi EMIS Siswa" />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">Sinkronisasi Data Siswa (EMIS)</h1>
                    <p className="text-sm text-slate-500 mt-1">Integrasi langsung dengan sistem EMIS Kemenag menggunakan Bookmarklet.</p>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    {/* ── Kartu Utama ───────────────────────────── */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Status & Aksi</CardTitle>
                            <CardDescription>Alur sinkronisasi: Hapus → Tarik → Review → Simpan</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">

                            {/* Token Status */}
                            {tokenActive ? (
                                <div className="flex items-center gap-3 p-3 bg-green-50 text-green-700 border border-green-200 rounded-lg text-sm">
                                    <CheckCircle className="h-5 w-5 shrink-0" />
                                    <span className="font-semibold">Token EMIS Aktif</span>
                                </div>
                            ) : (
                                <div className="flex items-center gap-3 p-3 bg-red-50 text-red-700 border border-red-200 rounded-lg text-sm">
                                    <AlertCircle className="h-5 w-5 shrink-0" />
                                    <span className="font-semibold">Token Tidak Aktif — Klik Bookmarklet</span>
                                </div>
                            )}

                            {/* Progress */}
                            {progressData && (
                                <div className="space-y-2 p-3 bg-slate-50 border rounded-lg">
                                    <div className="flex justify-between items-center text-sm">
                                        <span className="font-medium text-slate-700">Progress</span>
                                        <span className="font-bold text-lime-600">{progressData.progress}%</span>
                                    </div>
                                    <Progress value={progressData.progress} className="h-2" />
                                    <p className="text-xs text-slate-600 font-medium">{progressData.status}</p>
                                    
                                    {progressData.stats && (
                                        <div className="grid grid-cols-2 gap-2 text-xs mt-2">
                                            {progressData.stats.total_pulled !== undefined && (
                                                <div className="bg-white p-2 border rounded flex justify-between">
                                                    <span>Ditarik:</span>
                                                    <strong className="text-blue-600">{progressData.stats.total_pulled}</strong>
                                                </div>
                                            )}
                                            {progressData.stats.created !== undefined && (
                                                <div className="bg-white p-2 border rounded flex justify-between">
                                                    <span>Baru:</span>
                                                    <strong className="text-green-600">{progressData.stats.created}</strong>
                                                </div>
                                            )}
                                            {progressData.stats.updated !== undefined && (
                                                <div className="bg-white p-2 border rounded flex justify-between">
                                                    <span>Update:</span>
                                                    <strong className="text-blue-600">{progressData.stats.updated}</strong>
                                                </div>
                                            )}
                                            {progressData.stats.rombel_created !== undefined && (
                                                <div className="bg-white p-2 border rounded flex justify-between">
                                                    <span>Rombel Baru:</span>
                                                    <strong className="text-violet-600">{progressData.stats.rombel_created}</strong>
                                                </div>
                                            )}
                                            {progressData.stats.failed !== undefined && (
                                                <div className="bg-white p-2 border rounded flex justify-between">
                                                    <span>Gagal:</span>
                                                    <strong className="text-red-600">{progressData.stats.failed}</strong>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Step Indicators */}
                            <div className="flex items-center gap-1 text-xs">
                                {(['tarik', 'review', 'simpan'] as const).map((s, i) => {
                                    const active = (s === 'tarik' && (step === 'idle' || step === 'pulling' || step === 'pull_done')) ||
                                                   (s === 'review' && step === 'review') ||
                                                   (s === 'simpan' && (step === 'committing' || step === 'commit_done'));
                                    const done = (s === 'tarik' && ['pull_done', 'review', 'committing', 'commit_done'].includes(step)) ||
                                                 (s === 'review' && ['committing', 'commit_done'].includes(step)) ||
                                                 (s === 'simpan' && step === 'commit_done');
                                    return (
                                        <React.Fragment key={s}>
                                            {i > 0 && <div className="flex-1 h-px bg-slate-200 mx-1" />}
                                            <div className={`px-2 py-1 rounded-full font-medium ${
                                                active ? 'bg-lime-100 text-lime-700 ring-1 ring-lime-300' :
                                                done ? 'bg-green-100 text-green-700' :
                                                'bg-slate-100 text-slate-400'
                                            }`}>
                                                {done ? '✓ ' : ''}{s === 'tarik' ? '1. Tarik' : s === 'review' ? '2. Review' : '3. Simpan'}
                                            </div>
                                        </React.Fragment>
                                    );
                                })}
                            </div>

                            {/* Action Buttons per Step */}
                            {step === 'idle' && (
                                <Button 
                                    className="w-full gap-2 bg-lime-600 hover:bg-lime-700" 
                                    disabled={!tokenActive}
                                    onClick={() => setShowClearConfirm(true)}
                                >
                                    <Download className="h-4 w-4" />
                                    Tarik Data dari EMIS
                                </Button>
                            )}

                            {step === 'pulling' && (
                                <Button 
                                    variant="destructive" 
                                    className="w-full gap-2"
                                    onClick={() => setShowCancelConfirm(true)}
                                >
                                    <XIcon className="h-4 w-4" />
                                    Batalkan Sinkronisasi
                                </Button>
                            )}

                            {step === 'pull_done' && (
                                <Button 
                                    className="w-full gap-2 bg-blue-600 hover:bg-blue-700"
                                    onClick={loadStagingData}
                                    disabled={loadingStaging}
                                >
                                    <Eye className="h-4 w-4" />
                                    {loadingStaging ? 'Memuat...' : 'Review Data Hasil Tarikan'}
                                </Button>
                            )}

                            {step === 'committing' && (
                                <div className="text-center text-sm text-slate-500 p-2 bg-slate-50 rounded">
                                    Menyimpan data ke database, mohon tunggu...
                                </div>
                            )}

                            {step === 'commit_done' && (
                                <div className="text-center p-4 bg-green-50 text-green-700 border border-green-200 rounded-lg font-bold">
                                    ✅ Sinkronisasi & Penempatan Rombel Selesai!
                                </div>
                            )}

                            {step === 'error' && (
                                <Button 
                                    className="w-full gap-2"
                                    onClick={() => { setStep('idle'); setProgressData(null); }}
                                >
                                    Coba Lagi
                                </Button>
                            )}
                        </CardContent>
                    </Card>

                    {/* ── Panduan ───────────────────────────────── */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Panduan Penggunaan</CardTitle>
                            <CardDescription>Cara mendapatkan token akses dari EMIS.</CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm text-slate-600 space-y-4">
                            <div className="bg-slate-100 p-4 rounded-lg flex flex-col items-center border border-dashed border-slate-300 text-center">
                                <p className="mb-3 font-semibold text-slate-700">Tarik tombol ini ke Bookmark Bar:</p>
                                <div 
                                    dangerouslySetInnerHTML={{
                                        __html: `<a href="${bookmarkletCode}" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded shadow cursor-grab active:cursor-grabbing" onclick="event.preventDefault();">Pintu Madrasah (EMIS)</a>`
                                    }}
                                />
                            </div>
                            <ol className="list-decimal ml-4 space-y-2 text-sm">
                                <li><strong>Drag</strong> tombol biru ke Bookmark Bar Anda.</li>
                                <li>Buka tab baru, login ke <strong>EMIS Kemenag</strong>.</li>
                                <li>Klik bookmark <strong>"Pintu Madrasah (EMIS)"</strong>.</li>
                                <li>Kembali ke halaman ini, status jadi <strong>Terhubung</strong>.</li>
                                <li>Klik <strong>Tarik Data dari EMIS</strong>.</li>
                                <li>Setelah selesai, klik <strong>Review Data</strong>.</li>
                                <li>Klik <strong>Konfirmasi & Simpan</strong> untuk commit ke database.</li>
                            </ol>
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* ── Review Modal ──────────────────────────────── */}
            <Dialog open={step === 'review'} onOpenChange={(open) => { if (!open && !committing) setStep('pull_done'); }}>
                <DialogContent className="w-[95vw] sm:max-w-[95vw] md:max-w-6xl max-h-[95vh] flex flex-col overflow-hidden px-4 sm:px-6">
                    <DialogHeader>
                        <DialogTitle className="text-xl">Review Data EMIS ({stagingData.length} Siswa)</DialogTitle>
                        <DialogDescription>Periksa data sebelum disimpan. Data ini akan langsung ditempatkan ke rombel yang sesuai.</DialogDescription>
                    </DialogHeader>

                    <div className="flex-1 overflow-x-auto border rounded-md bg-white min-w-[300px] sm:min-w-[600px] md:min-w-[800px] lg:min-w-[1000px]">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 z-10 bg-slate-100 shadow-sm border-b">
                                <tr>
                                    <th className="h-10 px-4 text-left font-medium text-slate-600 w-12">#</th>
                                    <th className="h-10 px-4 text-left font-medium text-slate-600">NISN</th>
                                    <th className="h-10 px-4 text-left font-medium text-slate-600">Nama Siswa</th>
                                    <th className="h-10 px-4 text-center font-medium text-slate-600 w-14">L/P</th>
                                    <th className="h-10 px-4 text-left font-medium text-slate-600">Rombel</th>
                                    <th className="h-10 px-4 text-left font-medium text-slate-600 w-24">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {paginatedStagingData.map((row: any, idx: number) => (
                                    <tr key={row.id || idx} className="border-b hover:bg-slate-50 last:border-0">
                                        <td className="p-3 px-4 text-slate-400 font-mono text-xs text-center">{(reviewPage - 1) * 20 + idx + 1}</td>
                                        <td className="p-3 px-4 font-mono text-sm">{row.nisn || '—'}</td>
                                        <td className="p-3 px-4 font-medium">{row.name}</td>
                                        <td className="p-3 px-4 text-center">
                                            <span className={`text-xs font-bold px-2 py-0.5 rounded-full ${
                                                row.gender === 'L' ? 'bg-blue-50 text-blue-600' : 
                                                row.gender === 'P' ? 'bg-pink-50 text-pink-600' : 
                                                'bg-slate-100 text-slate-400'
                                            }`}>
                                                {row.gender || '?'}
                                            </span>
                                        </td>
                                        <td className="p-3 px-4 text-sm">{row.rombel_name || '—'}</td>
                                        <td className="p-3 px-4">
                                            {row.match_status === 'update' ? (
                                                <span className="bg-amber-100 text-amber-700 border border-amber-200 text-[10px] font-bold px-2 py-1 rounded">
                                                    UPDATE
                                                </span>
                                            ) : (
                                                <span className="bg-emerald-100 text-emerald-700 border border-emerald-200 text-[10px] font-bold px-2 py-1 rounded">
                                                    BARU
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    
                    <div className="flex items-center justify-between mt-4">
                        <div className="text-sm text-slate-500">
                            Menampilkan {stagingData.length > 0 ? (reviewPage - 1) * ITEMS_PER_PAGE + 1 : 0} - {Math.min(reviewPage * ITEMS_PER_PAGE, stagingData.length)} dari {stagingData.length} data
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setReviewPage(p => Math.max(1, p - 1))}
                                disabled={reviewPage === 1}
                            >
                                Sebelumnya
                            </Button>
                            <div className="text-sm px-2 text-slate-600">
                                {reviewPage} / {totalPages || 1}
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setReviewPage(p => Math.min(totalPages, p + 1))}
                                disabled={reviewPage >= totalPages || totalPages === 0}
                            >
                                Selanjutnya
                            </Button>
                        </div>
                    </div>

                    <DialogFooter className="pt-4 border-t mt-2">
                        <Button variant="outline" onClick={() => setStep('pull_done')} disabled={committing}>Kembali</Button>
                        <Button 
                            onClick={() => setShowCommitConfirm(true)}
                            disabled={stagingData.length === 0 || committing}
                            className="gap-2 bg-lime-600 hover:bg-lime-700"
                        >
                            <Save className="h-4 w-4" />
                            Konfirmasi & Simpan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ── Confirm Clear Staging ─────────────────────── */}
            <AlertDialog open={showClearConfirm} onOpenChange={setShowClearConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus Staging & Tarik Ulang?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Data staging lama akan dihapus. Sistem akan menarik data fresh dari EMIS.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => { setShowClearConfirm(false); handlePull(); }} className="bg-lime-600 hover:bg-lime-700">
                            Ya, Tarik Data
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* ── Confirm Commit ────────────────────────────── */}
            <AlertDialog open={showCommitConfirm} onOpenChange={setShowCommitConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Simpan ke Database?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {stagingData.length} siswa akan disimpan permanen dan ditempatkan ke rombel sesuai data EMIS. Pastikan data sudah benar.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={committing}>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={handleCommit} disabled={committing} className="bg-lime-600 hover:bg-lime-700">
                            {committing ? 'Menyimpan...' : 'Ya, Simpan Permanen'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* ── Confirm Cancel ────────────────────────────── */}
            <AlertDialog open={showCancelConfirm} onOpenChange={setShowCancelConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Batalkan Sinkronisasi?</AlertDialogTitle>
                        <AlertDialogDescription>Proses penarikan data akan dihentikan.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Kembali</AlertDialogCancel>
                        <AlertDialogAction onClick={handleCancelSync} className="bg-red-600 hover:bg-red-700">Ya, Batalkan</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
