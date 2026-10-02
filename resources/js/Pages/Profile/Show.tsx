import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { User, KeyRound, ShieldCheck, Save, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

interface ProfileProps {
    user: {
        id: string;
        name: string;
        username: string;
        role: 'admin' | 'staf' | 'guru';
    };
}

const roleLabel = { admin: 'Administrator', staf: 'Staf Tata Usaha', guru: 'Guru' };
const roleColor = { admin: 'bg-rose-500/15 text-rose-500 border-rose-500/20', staf: 'bg-blue-500/15 text-blue-500 border-blue-500/20', guru: 'bg-emerald-500/15 text-emerald-500 border-emerald-500/20' };

export default function ProfileShow({ user }: ProfileProps) {
    const [showCurrent, setShowCurrent] = useState(false);
    const [showNew, setShowNew] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);

    const profileForm = useForm({ name: user.name, username: user.username });
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

    function submitProfile(e: React.FormEvent) {
        e.preventDefault();
        profileForm.put('/profile');
    }

    function submitPassword(e: React.FormEvent) {
        e.preventDefault();
        passwordForm.put('/profile/password', {
            onSuccess: () => passwordForm.reset(),
        });
    }

    const initials = user.name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();

    return (
        <AppLayout>
            <Head title="Profil Saya" />

            <div className="mb-6">
                <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                    <User className="w-4 h-4" />
                    <span>Pengaturan</span>
                </div>
                <h1 className="text-2xl font-bold">Profil Saya</h1>
                <p className="text-muted-foreground text-sm mt-0.5">Kelola informasi akun Anda</p>
            </div>

            <div className="grid gap-6 max-w-2xl">

                {/* Avatar & Info */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center gap-5">
                            <div className="h-20 w-20 rounded-2xl bg-gradient-to-br from-primary to-primary/60 flex items-center justify-center text-2xl font-bold text-primary-foreground shadow-lg shrink-0">
                                {initials}
                            </div>
                            <div>
                                <h2 className="text-xl font-bold">{user.name}</h2>
                                <p className="text-muted-foreground text-sm font-mono">{user.username}</p>
                                <Badge variant="outline" className={cn('mt-2 text-xs', roleColor[user.role])}>
                                    <ShieldCheck className="w-3 h-3 mr-1" />
                                    {roleLabel[user.role]}
                                </Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Edit Profil */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base flex items-center gap-2">
                            <User className="w-4 h-4 text-primary" />
                            Informasi Akun
                        </CardTitle>
                        <CardDescription>Ubah nama dan username akun Anda</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submitProfile} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label>Nama Lengkap</Label>
                                <Input
                                    value={profileForm.data.name}
                                    onChange={e => profileForm.setData('name', e.target.value)}
                                    placeholder="Nama lengkap"
                                />
                                {profileForm.errors.name && <p className="text-xs text-destructive">{profileForm.errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Username / NIP</Label>
                                <Input
                                    value={profileForm.data.username}
                                    onChange={e => profileForm.setData('username', e.target.value)}
                                    placeholder="Username atau NIP"
                                />
                                {profileForm.errors.username && <p className="text-xs text-destructive">{profileForm.errors.username}</p>}
                            </div>
                            <div className="flex justify-end">
                                <Button type="submit" disabled={profileForm.processing} className="gap-2">
                                    <Save className="w-4 h-4" />
                                    {profileForm.processing ? 'Menyimpan...' : 'Simpan Perubahan'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* Ganti Password */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base flex items-center gap-2">
                            <KeyRound className="w-4 h-4 text-amber-500" />
                            Ganti Password
                        </CardTitle>
                        <CardDescription>Pastikan menggunakan password yang kuat dan unik</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submitPassword} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label>Password Saat Ini</Label>
                                <div className="relative">
                                    <Input
                                        type={showCurrent ? 'text' : 'password'}
                                        value={passwordForm.data.current_password}
                                        onChange={e => passwordForm.setData('current_password', e.target.value)}
                                        placeholder="Password lama"
                                        className="pr-10"
                                    />
                                    <button type="button" onClick={() => setShowCurrent(v => !v)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                                        {showCurrent ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                {passwordForm.errors.current_password && <p className="text-xs text-destructive">{passwordForm.errors.current_password}</p>}
                            </div>

                            <Separator />

                            <div className="space-y-1.5">
                                <Label>Password Baru</Label>
                                <div className="relative">
                                    <Input
                                        type={showNew ? 'text' : 'password'}
                                        value={passwordForm.data.password}
                                        onChange={e => passwordForm.setData('password', e.target.value)}
                                        placeholder="Min. 8 karakter"
                                        className="pr-10"
                                    />
                                    <button type="button" onClick={() => setShowNew(v => !v)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                                        {showNew ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                {passwordForm.errors.password && <p className="text-xs text-destructive">{passwordForm.errors.password}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Konfirmasi Password Baru</Label>
                                <div className="relative">
                                    <Input
                                        type={showConfirm ? 'text' : 'password'}
                                        value={passwordForm.data.password_confirmation}
                                        onChange={e => passwordForm.setData('password_confirmation', e.target.value)}
                                        placeholder="Ulangi password baru"
                                        className="pr-10"
                                    />
                                    <button type="button" onClick={() => setShowConfirm(v => !v)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                                        {showConfirm ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                            </div>
                            <div className="flex justify-end">
                                <Button type="submit" disabled={passwordForm.processing} variant="outline" className="gap-2">
                                    <KeyRound className="w-4 h-4" />
                                    {passwordForm.processing ? 'Mengubah...' : 'Ubah Password'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
