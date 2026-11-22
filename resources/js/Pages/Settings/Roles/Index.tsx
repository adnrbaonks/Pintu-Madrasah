import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Shield, Lock, Save, CheckSquare, Square, Users } from 'lucide-react';

interface RoleItem {
    id: number;
    name: string;
    label: string;
    description: string;
    is_locked: boolean;
    users_count: number;
    permissions: string[];
}

interface PermissionGroup {
    label: string;
    permissions: Record<string, string>;
}

interface Props {
    roles: RoleItem[];
    catalog: Record<string, PermissionGroup>;
}

export default function RolesIndex({ roles, catalog }: Props) {
    const [selectedRoleName, setSelectedRoleName] = useState<string>(roles[0]?.name || 'admin');
    const selectedRole = roles.find((r) => r.name === selectedRoleName) || roles[0];

    const [checkedPermissions, setCheckedPermissions] = useState<Record<string, string[]>>(() => {
        const initial: Record<string, string[]> = {};
        roles.forEach((r) => {
            initial[r.name] = [...r.permissions];
        });
        return initial;
    });

    const [saving, setSaving] = useState(false);

    const currentRolePermissions = checkedPermissions[selectedRole.name] || [];

    const togglePermission = (permName: string) => {
        if (selectedRole.is_locked) return;

        setCheckedPermissions((prev) => {
            const current = prev[selectedRole.name] || [];
            const exists = current.includes(permName);
            return {
                ...prev,
                [selectedRole.name]: exists
                    ? current.filter((p) => p !== permName)
                    : [...current, permName],
            };
        });
    };

    const handleSave = () => {
        if (selectedRole.is_locked) return;
        setSaving(true);
        router.put(
            `/pengaturan/roles/${selectedRole.id}`,
            { permissions: currentRolePermissions },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            }
        );
    };

    return (
        <AppLayout title="Matriks Role & Izin (RBAC)">
            <Head title="Matriks Role & Izin (RBAC)" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <Shield className="w-6 h-6 text-teal-700" />
                    Matriks Role & Izin (RBAC Spatie)
                </h1>
                <p className="text-sm text-slate-500 mt-1">
                    Kelola hak akses granular untuk 7 taksonomi jabatan resmi madrasah. Setiap perubahan tercatat di Audit Logs.
                </p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                {/* Daftar Role di Sisi Kiri */}
                <div className="space-y-2.5">
                    {roles.map((role) => {
                        const isSelected = role.name === selectedRole.name;
                        const permCount = (checkedPermissions[role.name] || []).length;

                        return (
                            <button
                                key={role.name}
                                type="button"
                                onClick={() => setSelectedRoleName(role.name)}
                                className={`w-full text-left p-3.5 rounded-xl border transition-all ${
                                    isSelected
                                        ? 'bg-teal-700 text-white border-teal-700 shadow-sm'
                                        : 'bg-white text-slate-800 border-slate-200 hover:bg-slate-50'
                                }`}
                            >
                                <div className="flex justify-between items-center">
                                    <span className="font-semibold text-sm">{role.label}</span>
                                    {role.is_locked && (
                                        <Lock className={`w-3.5 h-3.5 ${isSelected ? 'text-teal-200' : 'text-amber-600'}`} />
                                    )}
                                </div>
                                <div className="flex items-center justify-between mt-2 text-xs">
                                    <span className={isSelected ? 'text-teal-100' : 'text-slate-500'}>
                                        <Users className="w-3 h-3 inline mr-1" />
                                        {role.users_count} pengguna
                                    </span>
                                    <Badge
                                        variant={isSelected ? 'secondary' : 'outline'}
                                        className="text-[11px]"
                                    >
                                        {permCount} izin
                                    </Badge>
                                </div>
                            </button>
                        );
                    })}
                </div>

                {/* Panel Matriks Permission */}
                <div className="lg:col-span-3">
                    <Card className="border-t-4 border-t-teal-600">
                        <CardHeader className="border-b pb-4">
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <CardTitle className="text-lg">{selectedRole.label}</CardTitle>
                                        <Badge variant="outline" className="font-mono text-xs">
                                            {selectedRole.name}
                                        </Badge>
                                        {selectedRole.is_locked && (
                                            <Badge className="bg-amber-100 text-amber-800 border-amber-200">
                                                <Lock className="w-3 h-3 mr-1" /> Super Admin (Terkunci)
                                            </Badge>
                                        )}
                                    </div>
                                    <CardDescription className="mt-1">
                                        {selectedRole.description}
                                    </CardDescription>
                                </div>

                                {!selectedRole.is_locked && (
                                    <Button
                                        onClick={handleSave}
                                        disabled={saving}
                                        className="gap-2 bg-teal-700 hover:bg-teal-800 shrink-0"
                                    >
                                        <Save className="w-4 h-4" />
                                        {saving ? 'Menyimpan...' : 'Simpan Matriks Izin'}
                                    </Button>
                                )}
                            </div>
                        </CardHeader>

                        <CardContent className="pt-6 space-y-6">
                            {Object.entries(catalog).map(([groupKey, group]) => (
                                <div key={groupKey} className="space-y-3">
                                    <h3 className="text-sm font-bold text-slate-700 uppercase tracking-wider border-b pb-1.5">
                                        {group.label}
                                    </h3>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                                        {Object.entries(group.permissions).map(([permKey, permLabel]) => {
                                            const isChecked = currentRolePermissions.includes(permKey);
                                            return (
                                                <button
                                                    key={permKey}
                                                    type="button"
                                                    disabled={selectedRole.is_locked}
                                                    onClick={() => togglePermission(permKey)}
                                                    className={`flex items-start gap-3 p-3 rounded-lg border text-left transition-colors ${
                                                        isChecked
                                                            ? 'bg-teal-50/70 border-teal-200 text-slate-900'
                                                            : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                                                    } ${selectedRole.is_locked ? 'opacity-80 cursor-not-allowed' : ''}`}
                                                >
                                                    <div className="mt-0.5 shrink-0">
                                                        {isChecked ? (
                                                            <CheckSquare className="w-4 h-4 text-teal-700" />
                                                        ) : (
                                                            <Square className="w-4 h-4 text-slate-400" />
                                                        )}
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-medium leading-tight">{permLabel}</p>
                                                        <p className="text-xs font-mono text-slate-400 mt-0.5">{permKey}</p>
                                                    </div>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
