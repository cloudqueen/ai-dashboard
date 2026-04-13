import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function KanbanIndex() {
    const columns = [
        { key: 'backlog', label: 'Backlog', count: 0 },
        { key: 'todo', label: 'Todo', count: 0 },
        { key: 'ready_for_agent', label: 'Ready for Agent', count: 0 },
        { key: 'in_progress', label: 'In Progress', count: 0 },
        { key: 'review', label: 'Review', count: 0 },
        { key: 'done', label: 'Done', count: 0 },
    ];

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-200">
                    Kanban Board
                </h2>
            }
        >
            <Head title="Kanban" />

            <div className="flex gap-4 overflow-x-auto pb-4">
                {columns.map((col) => (
                    <div
                        key={col.key}
                        className="flex w-72 flex-shrink-0 flex-col rounded-xl border border-gray-800 bg-gray-900"
                    >
                        <div className="flex items-center justify-between border-b border-gray-800 px-4 py-3">
                            <h3 className="text-sm font-medium text-gray-300">{col.label}</h3>
                            <span className="rounded-full bg-gray-800 px-2 py-0.5 text-xs text-gray-500">
                                {col.count}
                            </span>
                        </div>
                        <div className="flex-1 p-3">
                            <p className="text-center text-xs text-gray-600 py-8">
                                Connect vault to see tickets
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
