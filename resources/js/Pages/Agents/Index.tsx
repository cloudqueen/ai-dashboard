import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function AgentsIndex() {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-200">
                    Agents
                </h2>
            }
        >
            <Head title="Agents" />

            <div className="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
                <svg className="mx-auto h-12 w-12 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth={1} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                </svg>
                <h3 className="mt-4 text-sm font-medium text-gray-300">No agent runs yet</h3>
                <p className="mt-2 text-sm text-gray-500">
                    Move a ticket to "Ready for Agent" on the Kanban board to trigger an agent run.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
