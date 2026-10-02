import { ReactNode, useState, useEffect, useCallback } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    LayoutDashboard, GraduationCap, Users, BookOpen,
    FileText, Settings, Menu, X, ChevronDown,
    RefreshCw, Search, Bell, Shield, UserPlus, LogOut, User,
    TrendingUp, Mail, Command
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
            role: 'admin' | 'staf' | 'guru';
        } | null;
    };
    flash: {
        success?: string;
        error?: string;
    };
    appName: string;
    madrasahName: string;
}

interface AppLayoutProps {
    children: ReactNode;
}

const roleLabel = {
    admin: 'Administrator',
    staf:  'Staf Tata Usaha',
    guru:  'Guru',
};

const roleColor = {
    admin: 'bg-rose-500/10 text-rose-500',
    staf:  'bg-blue-500/10 text-blue-500',
    guru:  'bg-emerald-500/10 text-emerald-500',
};

function buildNavItems(role: string) {
    const all = [
        {
            label: 'Dashboard',
            href: '/',
            icon: LayoutDashboard,
            roles: ['admin', 'staf', 'guru'],
        },
        {
            label: 'PPDB',
            icon: UserPlus,
            roles: ['admin', 'staf'],
            children: [
                { label: 'Dashboard PPDB', href: '/ppdb' },
                { label: 'Daftar Pendaftar', href: '/ppdb/registrations' },
                { label: 'Input Pendaftar', href: '/ppdb/registrations/create' },
            ],
        },
        {
            label: 'Data Siswa',
            icon: GraduationCap,
            roles: ['admin', 'staf', 'guru'],
            children: [
                { label: 'Master Data Siswa', href: '/students' },
                { label: 'Penempatan Siswa', href: '/siswa/penempatan', roles: ['admin', 'staf'] },
                { label: 'Form Cetak', href: '/siswa/form-cetak' },
                { label: 'Kenaikan Kelas Massal', href: '/siswa/kenaikan-kelas', roles: ['admin', 'staf'] },
                { label: 'Register Mutasi', href: '/siswa/mutasi', roles: ['admin', 'staf'] },
            ],
        },
        {
            label: 'Data GTK',
            icon: Users,
            roles: ['admin', 'staf'],
            children: [
                { label: 'Master Data GTK', href: '/gtk' },
                { label: 'Validasi TPG', href: '/gtk/tpg' },
            ],
        },
        {
            label: 'Administrasi Kelas',
            icon: BookOpen,
            roles: ['admin', 'staf', 'guru'],
            children: [
                { label: 'Rombongan Belajar', href: '/rombel' },
                { label: 'Denah Tempat Duduk', href: '/students/seat-layout' },
                { label: 'Leger Nilai', href: '/kelas/leger' },
                { label: 'Instrumen Ujian', href: '/students/print-exam-cards' },
                { label: 'Generate NISM', href: '/students/generate-nism', roles: ['admin', 'staf'] },
                { label: 'Format Cetak', href: '/cetak-format' },
            ],
        },
        {
            label: 'Integrasi EMIS',
            icon: RefreshCw,
            roles: ['admin', 'staf'],
            children: [
                { label: 'Sinkronisasi Siswa', href: '/emis/siswa' },
                { label: 'Sinkronisasi GTK', href: '/emis/gtk' },
            ],
        },
        {
            label: 'Persuratan',
            href: '/persuratan',
            icon: Mail,
            roles: ['admin', 'staf'],
        },
        {
            label: 'Statistik',
            icon: TrendingUp,
            roles: ['admin', 'staf'],
            children: [
                { label: 'Demografi Siswa & GTK', href: '/statistik/demografi' },
                { label: 'Validasi TPG', href: '/gtk/tpg' },
            ],
        },
        {
            label: 'Keamanan & Audit',
            icon: Shield,
            roles: ['admin'],
            children: [
                { label: 'Pengguna & Akses', href: '/users' },
                { label: 'Activity Logs', href: '/audit-logs' },
            ],
        },
        {
            label: 'Pengaturan',
            href: '/pengaturan',
            icon: Settings,
            roles: ['admin'],
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

function NavItem({ item, expanded, onToggle }: {
    item: ReturnType<typeof buildNavItems>[0];
    expanded: boolean;
    onToggle: () => void;
}) {
    const Icon = item.icon;

    if (!item.children || item.children.length === 0) {
        return (
            <Link
                href={item.href!}
                className={cn(
                    'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                    'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                    'transition-colors duration-150'
                )}
            >
                <Icon className="h-4 w-4 shrink-0 text-sidebar-foreground/50 group-hover:text-sidebar-primary transition-colors" />
                {item.label}
            </Link>
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
    const { auth, flash, appName, madrasahName } = usePage<SharedProps>().props;
    const user = auth?.user;

    const [sidebarOpen, setSidebarOpen] = useState(false);
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

    // Show flash messages
    if (flash?.success) toast.success(flash.success);
    if (flash?.error)   toast.error(flash.error);

    const toggleMenu = (label: string) => {
        setExpandedMenus((prev) =>
            prev.includes(label) ? prev.filter((l) => l !== label) : [...prev, label]
        );
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
                    'fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-sidebar transition-transform duration-300 ease-in-out',
                    'lg:static lg:translate-x-0',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                )}>
                    {/* Logo */}
                    <div className="flex h-16 items-center gap-3 border-b border-sidebar-border px-5">
                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground font-bold text-sm shrink-0">
                            PM
                        </div>
                        <div className="min-w-0">
                            <p className="text-sm font-bold text-sidebar-foreground truncate">{appName}</p>
                            <p className="text-xs text-sidebar-foreground/50 truncate">{madrasahName}</p>
                        </div>
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
                            />
                        ))}
                    </nav>

                    <Separator className="bg-sidebar-border" />

                    {/* User Footer */}
                    <div className="p-4">
                        <DropdownMenu>
                            <DropdownMenuTrigger render={<button className="w-full flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-sidebar-accent transition-colors" />}>
                                    <Avatar className="h-8 w-8 shrink-0">
                                        <AvatarFallback className="bg-sidebar-primary text-sidebar-primary-foreground text-xs font-bold">
                                            {initials}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0 text-left">
                                        <p className="text-xs font-semibold text-sidebar-foreground truncate">{user?.name ?? '—'}</p>
                                        <p className="text-xs text-sidebar-foreground/50 truncate">{roleLabel[user?.role ?? 'staf']}</p>
                                    </div>
                                    <ChevronDown className="h-3.5 w-3.5 text-sidebar-foreground/40 ml-auto shrink-0" />
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
                                <DropdownMenuItem asChild>
                                    <Link href="/profile" className="cursor-pointer">
                                        <User className="w-4 h-4 mr-2" />
                                        Profil Saya
                                    </Link>
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
                                    <DropdownMenuItem asChild>
                                        <Link href="/profile" className="cursor-pointer">
                                            <User className="w-4 h-4 mr-2" />
                                            Profil Saya
                                        </Link>
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
