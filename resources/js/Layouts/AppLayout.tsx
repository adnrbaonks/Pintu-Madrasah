import { ReactNode, useState, useEffect, useCallback, useRef } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    LayoutDashboard, GraduationCap, Users, BookOpen,
    FileText, Menu, X, ChevronDown,
    RefreshCw, Search, Bell, Shield, UserPlus, LogOut, User,
    Mail, Command, PanelLeftClose, PanelLeftOpen
} from 'lucide-react';
import CommandPalette from '@/components/CommandPalette';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Toaster } from '@/components/ui/sonner';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface SharedProps {
    auth: {
        user: {
            id: string;
            name: string;
            username: string;
            role: 'admin' | 'kepala_madrasah' | 'kaur_tu' | 'staf' | 'operator' | 'guru' | 'pegawai';
        } | null;
    };
    flash: {
        error?: string;
        warning?: string;
        error?: string;
    };
    appName: string;
    madrasahName: string;
    madrasahPhoto?: string | null;
}

interface AppLayoutProps {
    children: ReactNode;
}

const roleLabel: Record<string, string> = {
    admin:           'Administrator',
    kepala_madrasah: 'Kepala Madrasah',
    kaur_tu:         'Kepala Tata Usaha',
    staf:            'Staf Tata Usaha',
    operator:        'Operator Madrasah',
    guru:            'Guru',
    pegawai:         'Pegawai',
};

const roleColor: Record<string, string> = {
    admin:           'bg-rose-500/10 text-rose-500',
    kepala_madrasah: 'bg-purple-500/10 text-purple-500',
    kaur_tu:         'bg-indigo-500/10 text-indigo-500',
    staf:            'bg-blue-500/10 text-blue-500',
    operator:        'bg-cyan-500/10 text-cyan-500',
    guru:            'bg-emerald-500/10 text-emerald-500',
    pegawai:         'bg-slate-500/10 text-slate-500',
};

function buildNavItems(role: string) {
    const all = [
        // ── Utama ────────────────────────────────────────────────
        {
            label: 'Dashboard',
            href: '/',
            icon: LayoutDashboard,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator', 'guru', 'pegawai'],
        },
        // ── Kesiswaan ────────────────────────────────────────────
        {
            label: 'Kesiswaan',
            icon: GraduationCap,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator', 'guru'],
            children: [
                { label: 'Master Siswa', href: '/students', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Rombel', href: '/rombel', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Penempatan Siswa', href: '/siswa/penempatan', roles: ['admin', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Kenaikan Kelas', href: '/siswa/kenaikan-kelas', roles: ['admin', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Mutasi', href: '/siswa/mutasi', roles: ['admin', 'kaur_tu', 'staf', 'operator'] },
                { label: 'BK & Kedisiplinan', href: '/bk' },
            ],
        },
        // ── Akademik ─────────────────────────────────────────────
        {
            label: 'Akademik',
            icon: BookOpen,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'],
            children: [
                { label: 'Leger Nilai', href: '/kelas/leger' },
                { label: 'Denah Kelas', href: '/students/seat-layout' },
                { label: 'Kartu Ujian', href: '/students/print-exam-cards' },
                { label: 'Form Cetak', href: '/siswa/form-cetak' },
                { label: 'Format Cetak', href: '/cetak-format' },
                { label: 'Generate NISM', href: '/students/generate-nism', roles: ['admin', 'operator'] },
            ],
        },
        // ── GTK ──────────────────────────────────────────────────
        {
            label: 'GTK',
            icon: Users,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator', 'guru', 'pegawai'],
            children: [
                { label: 'Layanan Mandiri GTK', href: '/profil-gtk' },
                { label: 'Pengajuan Data Saya', href: '/profil-gtk/pengajuan' },
                { label: 'Master GTK', href: '/gtk', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Struktur & Jabatan', href: '/organisasi', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Verifikasi TU (GTK)', href: '/verifikasi-gtk', roles: ['admin', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Persetujuan Kamad', href: '/persetujuan-gtk', roles: ['admin', 'kepala_madrasah'] },
                { label: 'Validasi TPG', href: '/gtk/tpg', roles: ['admin', 'kepala_madrasah', 'operator', 'kaur_tu'] },
            ],
        },
        // ── Master Data ──────────────────────────────────────────
        {
            label: 'Master Data',
            icon: BookOpen,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'],
            children: [
                { label: 'Mata Pelajaran', href: '/master/subjects' },
                { label: 'Kelas', href: '/master/classes' },
                { label: 'Tahun Ajaran', href: '/master/academic-years' },
            ],
        },
        // ── PPDB ─────────────────────────────────────────────────
        {
            label: 'PPDB',
            icon: UserPlus,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'],
            children: [
                { label: 'Dashboard PPDB', href: '/ppdb' },
                { label: 'Daftar Pendaftar', href: '/ppdb/registrations' },
                { label: 'Input Pendaftar', href: '/ppdb/registrations/create' },
            ],
        },
        // ── Administrasi ─────────────────────────────────────────
        {
            label: 'Administrasi',
            icon: Mail,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator', 'guru', 'pegawai'],
            children: [
                { label: 'Arsip Digital', href: '/arsip-digital' },
                { label: 'Program Kerja', href: '/program-kerja' },
                { label: 'PKKM', href: '/pkkm', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Rapat & Notulen', href: '/rapat-dinas' },
                { label: 'Pengumuman', href: '/pengumuman' },
                { label: 'Kalender Madrasah', href: '/kalender' },
                { label: 'Persuratan', href: '/persuratan', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'] },
                { label: 'Demografi', href: '/statistik/demografi', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'] },
            ],
        },
        // ── Integrasi ────────────────────────────────────────────
        {
            label: 'Integrasi',
            icon: RefreshCw,
            roles: ['admin', 'operator'],
            children: [
                { label: 'EMIS Siswa', href: '/emis/siswa' },
                { label: 'EMIS GTK', href: '/emis/gtk' },
                { label: 'API Clients', href: '/api-clients', roles: ['admin'] },
                { label: 'Webhooks', href: '/webhooks', roles: ['admin'] },
            ],
        },
        // ── Sistem ───────────────────────────────────────────────
        {
            label: 'Sistem',
            icon: Shield,
            roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'operator'],
            children: [
                { label: 'Pengguna & Akses', href: '/users', roles: ['admin'] },
                { label: 'Matriks Role & Izin', href: '/pengaturan/roles', roles: ['admin'] },
                { label: 'Kamus Kepemilikan Data', href: '/pengaturan/kamus-data', roles: ['admin', 'kepala_madrasah', 'kaur_tu', 'operator'] },
                { label: 'Activity Logs', href: '/audit-logs', roles: ['admin', 'kepala_madrasah'] },
                { label: 'Pengaturan', href: '/pengaturan', roles: ['admin'] },
            ],
        },
    ];

    // Filter by role
    return all
        .filter(item => item.roles.includes(role))
        .map(item => ({
            ...item,
            children: item.children?.filter(c => !('roles' in c) || (c as any).roles.includes(role)),
        }));
}

function NavItem({ item, expanded, onToggle, collapsed, onExpand }: {
    item: ReturnType<typeof buildNavItems>[0];
    expanded: boolean;
    onToggle: () => void;
    collapsed: boolean;
    onExpand: () => void;
}) {
    const Icon = item.icon;

    const linkCls = (extra = '') => cn(
        'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
        'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
        'transition-colors duration-150',
        collapsed && 'justify-center px-0',
        extra
    );

    const iconEl = <Icon className="h-4 w-4 shrink-0 text-sidebar-foreground/50 group-hover:text-sidebar-primary transition-colors" />;

    const withTip = (el: React.ReactElement) => collapsed ? (
        <Tooltip>
            <TooltipTrigger render={el} />
            <TooltipContent side="right">{item.label}</TooltipContent>
        </Tooltip>
    ) : el;

    if (!item.children || item.children.length === 0) {
        return withTip(
            <Link href={item.href!} className={linkCls()}>
                {iconEl}
                {!collapsed && item.label}
            </Link>
        );
    }

    // Mode ikon: klik ikon = buka sidebar (ala shadcn collapsible="icon")
    if (collapsed) {
        return withTip(
            <button onClick={onExpand} className={linkCls('w-full')}>
                {iconEl}
            </button>
        );
    }

    return (
        <div>
            <button
                onClick={onToggle}
                className={cn(
                    'group w-full flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                    'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                    'transition-colors duration-150'
                )}
            >
                <Icon className="h-4 w-4 shrink-0 text-sidebar-foreground/50 group-hover:text-sidebar-primary transition-colors" />
                <span className="flex-1 text-left">{item.label}</span>
                <ChevronDown className={cn(
                    'h-3.5 w-3.5 text-sidebar-foreground/40 transition-transform duration-200',
                    expanded && 'rotate-180'
                )} />
            </button>
            {expanded && (
                <div className="mt-1 ml-3 border-l border-sidebar-border/50 pl-3 space-y-0.5">
                    {item.children.map((child) => (
                        <Link
                            key={child.label}
                            href={child.href}
                            className={cn(
                                'flex items-center rounded-md px-3 py-2 text-xs font-medium',
                                'text-sidebar-foreground/60 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                                'transition-colors duration-150'
                            )}
                        >
                            {child.label}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function AppLayout({ children }: AppLayoutProps) {
    const { auth, flash, appName, madrasahName, madrasahPhoto } = usePage<SharedProps>().props;
    const user = auth?.user;

    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => {
        try { return localStorage.getItem('pm-sidebar') === 'collapsed'; } catch { return false; }
    });
    const [expandedMenus, setExpandedMenus] = useState<string[]>([]);
    const [cmdOpen, setCmdOpen] = useState(false);

    // Ctrl+K / Cmd+K to open command palette
    useEffect(() => {
        const handleKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                setCmdOpen(prev => !prev);
            }
        };
        window.addEventListener('keydown', handleKey);
        return () => window.removeEventListener('keydown', handleKey);
    }, []);

    // Show flash messages — sekali per pesan (bukan tiap render)
    const shownFlash = useRef<string | null>(null);
    useEffect(() => {
        const key = flash?.success ? `ok:${flash.success}` : flash?.warning ? `warn:${flash.warning}` : flash?.error ? `err:${flash.error}` : null;
        if (key && shownFlash.current !== key) {
            shownFlash.current = key;
            if (flash?.success) toast.success(flash.success);
            else if (flash?.warning) toast.warning(flash.warning, { duration: 10000 });
            else if (flash?.error) toast.error(flash.error);
        }
        if (!key) shownFlash.current = null;
    }, [flash?.success, flash?.warning, flash?.error]);

    const toggleMenu = (label: string) => {
        setExpandedMenus((prev) =>
            prev.includes(label) ? prev.filter((l) => l !== label) : [...prev, label]
        );
    };

    const toggleCollapsed = () => {
        setCollapsed((prev) => {
            try { localStorage.setItem('pm-sidebar', prev ? 'expanded' : 'collapsed'); } catch { /* abaikan */ }
            return !prev;
        });
    };

    const navItems = buildNavItems(user?.role ?? 'staf');

    const initials = user?.name
        ? user.name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
        : 'TU';

    function handleLogout() {
        router.post('/logout');
    }

    return (
        <TooltipProvider>
            <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} />
            <div className="flex h-screen bg-background overflow-hidden">

                {/* Mobile Overlay */}
                {sidebarOpen && (
                    <div
                        className="fixed inset-0 bg-black/50 z-20 lg:hidden"
                        onClick={() => setSidebarOpen(false)}
                    />
                )}

                {/* Sidebar */}
                <aside className={cn(
                    'fixed inset-y-0 left-0 z-30 flex flex-col bg-sidebar transition-all duration-300 ease-in-out',
                    'lg:static lg:translate-x-0',
                    sidebarOpen ? 'translate-x-0 w-64' : '-translate-x-full w-64',
                    collapsed ? 'lg:w-[76px]' : 'lg:w-64'
                )}>
                    {/* Logo */}
                    <div className={cn('flex h-16 items-center gap-3 border-b border-sidebar-border px-5', collapsed && 'lg:justify-center lg:px-0')}>
                        {madrasahPhoto ? (
                            <img src={madrasahPhoto} alt="Logo madrasah" className="h-9 w-9 rounded-lg object-cover shrink-0" />
                        ) : (
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground font-bold text-sm shrink-0">
                                PM
                            </div>
                        )}
                        {!collapsed && (
                            <div className="min-w-0">
                                <p className="text-sm font-bold text-sidebar-foreground truncate">{appName}</p>
                                <p className="text-xs text-sidebar-foreground/50 truncate">{madrasahName}</p>
                            </div>
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            className="ml-auto lg:hidden text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent"
                            onClick={() => setSidebarOpen(false)}
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </div>

                    {/* Navigation */}
                    <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
                        {navItems.map((item) => (
                            <NavItem
                                key={item.label}
                                item={item}
                                expanded={expandedMenus.includes(item.label)}
                                onToggle={() => toggleMenu(item.label)}
                                collapsed={collapsed}
                                onExpand={() => { if (collapsed) toggleCollapsed(); }}
                            />
                        ))}
                    </nav>

                    <Separator className="bg-sidebar-border" />

                    {/* User Footer */}
                    <div className="p-4">
                        <DropdownMenu>
                            <DropdownMenuTrigger render={<button className={cn('w-full flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-sidebar-accent transition-colors', collapsed && 'lg:justify-center lg:px-0')} />}>
                                    <Avatar className="h-8 w-8 shrink-0">
                                        <AvatarFallback className="bg-sidebar-primary text-sidebar-primary-foreground text-xs font-bold">
                                            {initials}
                                        </AvatarFallback>
                                    </Avatar>
                                    {!collapsed && (
                                        <>
                                            <div className="min-w-0 text-left">
                                                <p className="text-xs font-semibold text-sidebar-foreground truncate">{user?.name ?? '—'}</p>
                                                <p className="text-xs text-sidebar-foreground/50 truncate">{roleLabel[user?.role ?? 'staf']}</p>
                                            </div>
                                            <ChevronDown className="h-3.5 w-3.5 text-sidebar-foreground/40 ml-auto shrink-0" />
                                        </>
                                    )}
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                                <DropdownMenuLabel>
                                    <div className="flex flex-col gap-1">
                                        <span>{user?.name}</span>
                                        <Badge variant="outline" className={cn('w-fit text-xs', roleColor[user?.role ?? 'staf'])}>
                                            {roleLabel[user?.role ?? 'staf']}
                                        </Badge>
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem render={<Link href="/profile" className="cursor-pointer" />}>
                                    <User className="w-4 h-4 mr-2" />
                                    Profil Saya
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem className="text-destructive cursor-pointer" onClick={handleLogout}>
                                    <LogOut className="w-4 h-4 mr-2" />
                                    Keluar
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </aside>

                {/* Main Content */}
                <div className="flex flex-1 flex-col min-w-0 overflow-hidden">
                    {/* Topbar */}
                    <header className="flex h-16 items-center gap-4 border-b border-border bg-card px-4 lg:px-6 shrink-0">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="lg:hidden"
                            onClick={() => setSidebarOpen(true)}
                        >
                            <Menu className="h-5 w-5" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="hidden lg:inline-flex"
                            onClick={toggleCollapsed}
                            title={collapsed ? 'Bentangkan sidebar' : 'Ciutkan sidebar'}
                        >
                            {collapsed ? <PanelLeftOpen className="h-5 w-5" /> : <PanelLeftClose className="h-5 w-5" />}
                        </Button>

                        {/* Search → Command Palette trigger */}
                        <div className="flex-1 max-w-sm">
                            <button
                                onClick={() => setCmdOpen(true)}
                                className="w-full flex items-center gap-2 h-9 px-3 rounded-md bg-muted text-sm text-muted-foreground hover:bg-muted/80 transition-colors border border-transparent hover:border-border"
                            >
                                <Search className="h-3.5 w-3.5 shrink-0" />
                                <span className="flex-1 text-left">Cari siswa, guru, surat...</span>
                                <kbd className="hidden sm:flex items-center gap-0.5 text-[10px] font-mono border border-border rounded px-1.5 py-0.5 bg-background/60 text-muted-foreground/70">
                                    <Command className="w-2.5 h-2.5" />K
                                </kbd>
                            </button>
                        </div>

                        <div className="ml-auto flex items-center gap-1">
                            {/* Avatar */}
                            <DropdownMenu>
                                <DropdownMenuTrigger render={<Button variant="ghost" size="icon" className="rounded-full" />}>
                                        <Avatar className="h-8 w-8">
                                            <AvatarFallback className="bg-primary text-primary-foreground text-xs font-bold">
                                                {initials}
                                            </AvatarFallback>
                                        </Avatar>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-52">
                                    <DropdownMenuLabel>
                                        <div>
                                            <p className="font-medium">{user?.name}</p>
                                            <p className="text-xs text-muted-foreground font-normal">{user?.username}</p>
                                        </div>
                                    </DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem render={<Link href="/profile" className="cursor-pointer" />}>
                                        <User className="w-4 h-4 mr-2" />
                                        Profil Saya
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem className="text-destructive cursor-pointer" onClick={handleLogout}>
                                        <LogOut className="w-4 h-4 mr-2" />
                                        Keluar
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </header>

                    {/* Page Content */}
                    <main className="flex-1 overflow-y-auto p-4 lg:p-6">
                        {children}
                    </main>
                </div>
            </div>
            <Toaster position="top-right" />
        </TooltipProvider>
    );
}
