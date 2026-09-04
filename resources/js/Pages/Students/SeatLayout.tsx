import React, { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { 
    DndContext, 
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    DragEndEvent
} from '@dnd-kit/core';
import { 
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    rectSortingStrategy,
    useSortable
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Printer } from 'lucide-react';

interface Student {
    id: string;
    name: string;
    nisn: string;
}

interface Rombel {
    id: string;
    name: string;
}

// Sortable Item Component
function SortableSeat({ id, student, index }: { id: string, student: Student, index: number }) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        zIndex: isDragging ? 10 : 1,
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            {...attributes}
            {...listeners}
            className={`border rounded-md p-3 flex flex-col h-24 bg-white shadow-sm cursor-grab active:cursor-grabbing hover:border-lime-500 transition-colors ${isDragging ? 'opacity-50 ring-2 ring-lime-500' : ''}`}
        >
            <div className="flex justify-between items-start mb-2">
                <span className="text-xs font-bold text-slate-400">Meja {index + 1}</span>
            </div>
            <span className="text-sm font-medium text-slate-900 leading-tight line-clamp-2">
                {student.name}
            </span>
            <span className="text-xs text-slate-500 mt-auto">{student.nisn || '-'}</span>
        </div>
    );
}

export default function SeatLayout({ rombels, students: initialStudents, activeRombel }: { rombels: Rombel[], students: Student[], activeRombel: Rombel | null }) {
    const [students, setStudents] = useState<Student[]>(initialStudents || []);

    // Update state when props change
    useEffect(() => {
        setStudents(initialStudents || []);
    }, [initialStudents]);

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;

        if (over && active.id !== over.id) {
            setStudents((items) => {
                const oldIndex = items.findIndex(item => item.id === active.id);
                const newIndex = items.findIndex(item => item.id === over.id);
                return arrayMove(items, oldIndex, newIndex);
            });
        }
    };

    const handleSelectRombel = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const rombel_id = e.target.value;
        if (rombel_id) {
            router.get(route('students.seat-layout'), { rombel_id }, { preserveState: true });
        }
    };

    const handlePrint = () => {
        window.print();
    };

    return (
        <AppLayout>
            <Head title="Denah Tempat Duduk" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 print:hidden">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Denah Tempat Duduk</h1>
                        <p className="text-slate-500">Atur posisi duduk siswa saat ujian dengan drag & drop</p>
                    </div>
                    <div className="flex gap-2 items-center">
                        <select 
                            className="border-slate-300 rounded-md shadow-sm focus:border-lime-500 focus:ring-lime-500 sm:text-sm"
                            onChange={handleSelectRombel}
                            defaultValue={activeRombel?.id || ""}
                        >
                            <option value="" disabled>Pilih Rombel...</option>
                            {rombels.map(r => (
                                <option key={r.id} value={r.id}>{r.name}</option>
                            ))}
                        </select>
                        <Button variant="outline" className="gap-2" onClick={handlePrint} disabled={!activeRombel}>
                            <Printer className="h-4 w-4" />
                            Cetak
                        </Button>
                    </div>
                </div>

                {activeRombel ? (
                    <Card className="print:shadow-none print:border-none">
                        <CardHeader className="print:block text-center border-b pb-6 mb-6">
                            <CardTitle className="text-xl">Denah Ruang Ujian: {activeRombel.name}</CardTitle>
                            <p className="text-slate-500 mt-2">Papan Tulis / Pengawas</p>
                        </CardHeader>
                        <CardContent>
                            <div className="bg-slate-200 h-8 w-1/3 mx-auto mb-10 rounded text-center leading-8 text-sm font-semibold text-slate-600 print:bg-slate-200">
                                MEJA PENGAWAS
                            </div>

                            <DndContext 
                                sensors={sensors}
                                collisionDetection={closestCenter}
                                onDragEnd={handleDragEnd}
                            >
                                <SortableContext 
                                    items={students.map(s => s.id)}
                                    strategy={rectSortingStrategy}
                                >
                                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                                        {students.map((student, index) => (
                                            <SortableSeat 
                                                key={student.id} 
                                                id={student.id} 
                                                student={student} 
                                                index={index} 
                                            />
                                        ))}
                                    </div>
                                </SortableContext>
                            </DndContext>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center h-64 text-slate-500">
                            <p>Silakan pilih Rombel terlebih dahulu untuk mengatur denah kelas.</p>
                        </CardContent>
                    </Card>
                )}
            </div>

            {/* Print styles */}
            <style dangerouslySetInnerHTML={{__html: `
                @media print {
                    @page { size: landscape; margin: 1cm; }
                    body { -webkit-print-color-adjust: exact; }
                }
            `}} />
        </AppLayout>
    );
}
