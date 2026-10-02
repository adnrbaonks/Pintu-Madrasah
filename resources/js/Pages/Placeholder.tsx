import React from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { HardHat } from 'lucide-react';

export default function Placeholder({ title }: { title: string }) {
    return (
        <AppLayout>
            <Head title={title} />
            <div className="flex flex-col items-center justify-center h-[70vh] text-center px-4">
                <div className="bg-lime-50 text-lime-500 p-4 rounded-full mb-6">
                    <HardHat className="w-12 h-12" />
                </div>
                <h1 className="text-3xl font-bold text-neutral-900 mb-2">{title}</h1>
                <p className="text-neutral-500 max-w-md mx-auto">
                    Halaman ini sedang dalam tahap pengembangan (Under Construction).
                    Fitur ini akan segera tersedia pada pembaruan sprint berikutnya.
                </p>
            </div>
        </AppLayout>
    );
}
