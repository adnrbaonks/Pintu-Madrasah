import { FormEvent, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, LogIn, School } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

interface LoginProps {
    appName: string;
    madrasahName: string;
    errors: Record<string, string>;
}

export default function Login({ appName, madrasahName }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title={`Login — ${appName}`} />

            {/* ── Background ─────────────────────────────────────── */}
            <div className="min-h-screen flex items-center justify-center relative overflow-hidden bg-[#0a1628]">

                {/* Radial glows */}
                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute top-[-10%] left-[-10%] w-[50vw] h-[50vw] rounded-full bg-teal-500/10 blur-[120px]" />
                    <div className="absolute bottom-[-10%] right-[-10%] w-[45vw] h-[45vw] rounded-full bg-emerald-600/10 blur-[100px]" />
                    <div className="absolute top-[40%] left-[40%] w-[30vw] h-[30vw] rounded-full bg-cyan-400/5 blur-[80px]" />
                </div>

                {/* Subtle grid pattern */}
                <div
                    className="absolute inset-0 opacity-[0.03]"
                    style={{
                        backgroundImage: `
                            linear-gradient(rgba(255,255,255,0.5) 1px, transparent 1px),
                            linear-gradient(90deg, rgba(255,255,255,0.5) 1px, transparent 1px)
                        `,
                        backgroundSize: '40px 40px',
                    }}
                />

                {/* ── Card ───────────────────────────────────────── */}
                <div className="relative w-full max-w-md mx-4">

                    {/* Card glow border */}
                    <div className="absolute -inset-[1px] rounded-2xl bg-gradient-to-br from-teal-500/40 via-transparent to-emerald-500/30 blur-[2px]" />

                    <div className="relative bg-white/[0.04] backdrop-blur-xl rounded-2xl border border-white/10 p-8 shadow-2xl shadow-black/40">

                        {/* Header */}
                        <div className="text-center mb-8">
                            <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-teal-500 to-emerald-600 shadow-lg shadow-teal-500/25 mb-4">
                                <School className="w-8 h-8 text-white" />
                            </div>
                            <h1 className="text-2xl font-bold text-white tracking-tight">
                                {appName}
                            </h1>
                            <p className="text-sm text-white/50 mt-1">{madrasahName}</p>
                        </div>

                        {/* Form */}
                        <form onSubmit={handleSubmit} className="space-y-5">

                            {/* Username */}
                            <div className="space-y-1.5">
                                <Label htmlFor="username" className="text-white/70 text-sm font-medium">
                                    Username / NIP
                                </Label>
                                <Input
                                    id="username"
                                    type="text"
                                    value={data.username}
                                    onChange={e => setData('username', e.target.value)}
                                    placeholder="Masukkan username atau NIP"
                                    autoComplete="username"
                                    autoFocus
                                    className={cn(
                                        'bg-white/5 border-white/10 text-white placeholder:text-white/30',
                                        'focus:border-teal-500/60 focus:ring-teal-500/20 focus:bg-white/8',
                                        'transition-all duration-200 h-11',
                                        errors.username && 'border-red-500/60 focus:border-red-500/60'
                                    )}
                                />
                                {errors.username && (
                                    <p className="text-xs text-red-400 mt-1">{errors.username}</p>
                                )}
                            </div>

                            {/* Password */}
                            <div className="space-y-1.5">
                                <Label htmlFor="password" className="text-white/70 text-sm font-medium">
                                    Password
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        placeholder="Masukkan password"
                                        autoComplete="current-password"
                                        className={cn(
                                            'bg-white/5 border-white/10 text-white placeholder:text-white/30 pr-11',
                                            'focus:border-teal-500/60 focus:ring-teal-500/20 focus:bg-white/8',
                                            'transition-all duration-200 h-11',
                                            errors.password && 'border-red-500/60'
                                        )}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(v => !v)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-white/30 hover:text-white/70 transition-colors"
                                    >
                                        {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                {errors.password && (
                                    <p className="text-xs text-red-400 mt-1">{errors.password}</p>
                                )}
                            </div>

                            {/* Remember me */}
                            <div className="flex items-center gap-2.5">
                                <Checkbox
                                    id="remember"
                                    checked={data.remember}
                                    onCheckedChange={(v) => setData('remember', v === true)}
                                    className="border-white/20 data-[state=checked]:bg-teal-500 data-[state=checked]:border-teal-500"
                                />
                                <Label htmlFor="remember" className="text-sm text-white/50 cursor-pointer select-none">
                                    Ingat saya selama 30 hari
                                </Label>
                            </div>

                            {/* Submit */}
                            <Button
                                type="submit"
                                disabled={processing}
                                className={cn(
                                    'w-full h-11 bg-gradient-to-r from-teal-500 to-emerald-600',
                                    'hover:from-teal-400 hover:to-emerald-500',
                                    'text-white font-semibold shadow-lg shadow-teal-500/25',
                                    'transition-all duration-200',
                                    'disabled:opacity-60 disabled:cursor-not-allowed'
                                )}
                            >
                                {processing ? (
                                    <span className="flex items-center gap-2">
                                        <span className="h-4 w-4 rounded-full border-2 border-white/30 border-t-white animate-spin" />
                                        Masuk...
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-2">
                                        <LogIn className="w-4 h-4" />
                                        Masuk ke Sistem
                                    </span>
                                )}
                            </Button>
                        </form>

                        {/* Footer */}
                        <p className="text-center text-xs text-white/20 mt-6">
                            Akses terbatas untuk pengguna yang berwenang.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
