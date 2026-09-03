import { ReactNode, useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    LayoutDashboard, GraduationCap, Users, BookOpen,
    FileText, Settings, Menu, X, ChevronDown,
    RefreshCw, Search, Bell
} from 'lucide-react';
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
import { cn } from '@/lib/utils';

interface AppLayoutProps {
    children: ReactNode;
}

const navItems = [
    {
        label: 'Dashboard',
        href: '/',
        icon: LayoutDashboard,
    },
    {
        label: 'Data Siswa',
        icon: GraduationCap,
        children: [
            { label: 'Master Data Siswa', href: '/siswa' },
            { label: 'PPDB & Pendaftaran', href: '/siswa/ppdb' },
            { label: 'Kenaikan Kelas Massal', href: '/siswa/kenaikan-kelas' },
            { label: 'Register Mutasi', href: '/siswa/mutasi' },
        ],
    },
    {
        label: 'Data GTK',
        icon: Users,
        children: [
            { label: 'Master Data GTK', href: '/gtk' },
            { label: 'Validasi TPG', href: '/gtk/tpg' },
        ],
    },
    {
        label: 'Administrasi Kelas',
        icon: BookOpen,
        children: [
            { label: 'Rombongan Belajar', href: '/rombel' },
            { label: 'Denah Tempat Duduk', href: '/kelas/denah' },
            { label: 'Leger Nilai', href: '/kelas/leger' },
            { label: 'Instrumen Ujian', href: '/kelas/ujian' },
        ],
    },
    {
        label: 'Integrasi EMIS',
        icon: RefreshCw,
        children: [
            { label: 'Sinkronisasi Siswa', href: '/emis/siswa' },
            { label: 'Sinkronisasi GTK', href: '/emis/gtk' },
        ],
    },
    {
        label: 'Persuratan',
        href: '/surat',
        icon: FileText,
    },
    {
        label: 'Pengaturan',
        href: '/pengaturan',
        icon: Settings,
    },
];

function NavItem({ item, expanded, onToggle }: {
    item: typeof navItems[0];
    expanded: boolean;
    onToggle: () => void;
}) {
    const Icon = item.icon;

    if (!item.children) {
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
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [expandedMenus, setExpandedMenus] = useState<string[]>([]);

    const toggleMenu = (label: string) => {
        setExpandedMenus((prev) =>
            prev.includes(label) ? prev.filter((l) => l !== label) : [...prev, label]
        );
    };

    return (
        <TooltipProvider>
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
                            <p className="text-sm font-bold text-sidebar-foreground truncate">PINTU MADRASAH</p>
                            <p className="text-xs text-sidebar-foreground/50 truncate">Sistem Tata Usaha</p>
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
                            <DropdownMenuTrigger asChild>
                                <button className="w-full flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-sidebar-accent transition-colors">
                                    <Avatar className="h-8 w-8 shrink-0">
                                        <AvatarFallback className="bg-sidebar-primary text-sidebar-primary-foreground text-xs font-bold">
                                            TU
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0 text-left">
                                        <p className="text-xs font-semibold text-sidebar-foreground truncate">Admin Tata Usaha</p>
                                        <p className="text-xs text-sidebar-foreground/50 truncate">Administrator</p>
                                    </div>
                                    <ChevronDown className="h-3.5 w-3.5 text-sidebar-foreground/40 ml-auto shrink-0" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                                <DropdownMenuLabel>Akun Saya</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem>Profil</DropdownMenuItem>
                                <DropdownMenuItem>Pengaturan</DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem className="text-destructive">
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

                        {/* Search */}
                        <div className="flex-1 max-w-sm">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" />
                                <Input
                                    type="text"
                                    placeholder="Cari siswa, guru, surat... (Ctrl+K)"
                                    className="pl-9 h-9 text-sm bg-muted border-0 focus-visible:ring-1 focus-visible:ring-ring"
                                />
                            </div>
                        </div>

                        <div className="ml-auto flex items-center gap-1">
                            {/* Notifikasi */}
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button variant="ghost" size="icon" className="relative">
                                        <Bell className="h-5 w-5" />
                                        <span className="absolute top-2 right-2 h-2 w-2 rounded-full bg-secondary" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>Notifikasi</TooltipContent>
                            </Tooltip>

                            {/* Avatar */}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" className="rounded-full">
                                        <Avatar className="h-8 w-8">
                                            <AvatarFallback className="bg-primary text-primary-foreground text-xs font-bold">
                                                TU
                                            </AvatarFallback>
                                        </Avatar>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuLabel>Admin Tata Usaha</DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem>Profil</DropdownMenuItem>
                                    <DropdownMenuItem>Pengaturan</DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem className="text-destructive">Keluar</DropdownMenuItem>
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
        </TooltipProvider>
    );
}
