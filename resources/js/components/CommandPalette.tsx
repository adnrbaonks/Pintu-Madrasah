import { useState, useEffect, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { Search, GraduationCap, Users, FileText, LayoutDashboard, Loader2, Command } from 'lucide-react';
import { cn } from '@/lib/utils';

interface SearchResult {
    type: 'siswa' | 'gtk' | 'surat' | 'menu';
    label: string;
    subtitle: string;
    href: string;
    icon: string;
}

const iconMap: Record<string, React.ComponentType<{ className?: string }>> = {
    student: GraduationCap,
    gtk:     Users,
    letter:  FileText,
    menu:    LayoutDashboard,
};

const typeLabel: Record<string, string> = {
    siswa: 'Siswa',
    gtk:   'GTK',
    surat: 'Surat',
    menu:  'Menu',
};

const typeColor: Record<string, string> = {
    siswa: 'bg-blue-500/10 text-blue-600',
    gtk:   'bg-emerald-500/10 text-emerald-600',
    surat: 'bg-amber-500/10 text-amber-600',
    menu:  'bg-purple-500/10 text-purple-600',
};

interface Props {
    open: boolean;
    onClose: () => void;
}

export default function CommandPalette({ open, onClose }: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [selectedIndex, setSelectedIndex] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout>>();

    // Focus input on open
    useEffect(() => {
        if (open) {
            setQuery('');
            setResults([]);
            setSelectedIndex(0);
            setTimeout(() => inputRef.current?.focus(), 50);
        }
    }, [open]);

    // Fetch results
    const fetchResults = useCallback(async (q: string) => {
        if (q.length < 2) {
            setResults([]);
            return;
        }
        setLoading(true);
        try {
            const res = await fetch(`/api/search?q=${encodeURIComponent(q)}`);
            const data = await res.json();
            setResults(data.results ?? []);
            setSelectedIndex(0);
        } catch {
            setResults([]);
        } finally {
            setLoading(false);
        }
    }, []);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const q = e.target.value;
        setQuery(q);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => fetchResults(q), 250);
    };

    // Navigate
    const navigate = useCallback((result: SearchResult) => {
        onClose();
        router.visit(result.href);
    }, [onClose]);

    // Keyboard
    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setSelectedIndex(i => Math.min(i + 1, results.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setSelectedIndex(i => Math.max(i - 1, 0));
        } else if (e.key === 'Enter' && results[selectedIndex]) {
            navigate(results[selectedIndex]);
        } else if (e.key === 'Escape') {
            onClose();
        }
    };

    if (!open) return null;

    return (
        // Backdrop
        <div
            className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-start justify-center pt-[15vh]"
            onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div className="w-full max-w-2xl mx-4 bg-popover border border-border rounded-2xl shadow-2xl overflow-hidden">

                {/* Input */}
                <div className="flex items-center gap-3 px-4 py-3 border-b border-border">
                    {loading ? (
                        <Loader2 className="w-4 h-4 shrink-0 text-muted-foreground animate-spin" />
                    ) : (
                        <Search className="w-4 h-4 shrink-0 text-muted-foreground" />
                    )}
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={handleChange}
                        onKeyDown={handleKeyDown}
                        placeholder="Cari siswa, guru, surat, menu..."
                        className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground/60"
                        autoComplete="off"
                    />
                    <div className="flex items-center gap-1 shrink-0">
                        <kbd className="pointer-events-none inline-flex h-5 select-none items-center gap-1 rounded border border-border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground opacity-100">
                            ESC
                        </kbd>
                    </div>
                </div>

                {/* Results */}
                {results.length > 0 ? (
                    <ul className="py-2 max-h-80 overflow-y-auto">
                        {results.map((result, i) => {
                            const Icon = iconMap[result.icon] ?? LayoutDashboard;
                            return (
                                <li key={i}>
                                    <button
                                        className={cn(
                                            'w-full flex items-center gap-3 px-4 py-2.5 text-left transition-colors',
                                            i === selectedIndex ? 'bg-accent' : 'hover:bg-accent/50'
                                        )}
                                        onClick={() => navigate(result)}
                                        onMouseEnter={() => setSelectedIndex(i)}
                                    >
                                        <div className={cn('rounded-lg p-1.5 shrink-0', typeColor[result.type])}>
                                            <Icon className="w-3.5 h-3.5" />
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium truncate">{result.label}</p>
                                            <p className="text-xs text-muted-foreground truncate">{result.subtitle}</p>
                                        </div>
                                        <span className={cn('text-xs px-1.5 py-0.5 rounded shrink-0', typeColor[result.type])}>
                                            {typeLabel[result.type]}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                ) : query.length >= 2 && !loading ? (
                    <div className="py-10 text-center text-sm text-muted-foreground">
                        Tidak ada hasil untuk <span className="font-medium">"{query}"</span>
                    </div>
                ) : query.length === 0 ? (
                    <div className="py-6 px-4">
                        <p className="text-xs text-muted-foreground mb-3 font-medium uppercase tracking-wide">Akses Cepat</p>
                        <div className="grid grid-cols-2 gap-1.5">
                            {[
                                { label: 'Dashboard', href: '/' },
                                { label: 'Master Siswa', href: '/students' },
                                { label: 'GTK', href: '/gtk' },
                                { label: 'Leger Nilai', href: '/kelas/leger' },
                                { label: 'PPDB', href: '/ppdb/registrations' },
                                { label: 'Persuratan', href: '/persuratan' },
                            ].map(item => (
                                <button
                                    key={item.href}
                                    onClick={() => { onClose(); router.visit(item.href); }}
                                    className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs text-left hover:bg-accent transition-colors"
                                >
                                    <LayoutDashboard className="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                                    {item.label}
                                </button>
                            ))}
                        </div>
                    </div>
                ) : null}

                {/* Footer */}
                <div className="flex items-center justify-between px-4 py-2 border-t border-border bg-muted/30">
                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                            <kbd className="border border-border rounded px-1 font-mono bg-background">↑↓</kbd>
                            navigasi
                        </span>
                        <span className="flex items-center gap-1">
                            <kbd className="border border-border rounded px-1 font-mono bg-background">↵</kbd>
                            buka
                        </span>
                    </div>
                    <span className="text-xs text-muted-foreground flex items-center gap-1">
                        <Command className="w-3 h-3" />K untuk buka
                    </span>
                </div>
            </div>
        </div>
    );
}
