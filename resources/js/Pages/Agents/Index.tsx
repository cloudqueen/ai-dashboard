import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

interface AgentRun {
    id: number;
    ticket_id: number | null;
    ticket: { id: number; title: string } | null;
    skill: string;
    status: string;
    summary: string | null;
    duration_seconds: number | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

interface Props {
    runs: {
        data: AgentRun[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    activeCount: number;
    skills: { name: string; display_name: string }[];
}

const statusColors: Record<string, { bg: string; text: string }> = {
    queued: { bg: 'bg-gray-700', text: 'text-gray-300' },
    running: { bg: 'bg-blue-900/50', text: 'text-blue-400' },
    completed: { bg: 'bg-green-900/50', text: 'text-green-400' },
    failed: { bg: 'bg-red-900/50', text: 'text-red-400' },
    timed_out: { bg: 'bg-yellow-900/50', text: 'text-yellow-400' },
};

export default function AgentsIndex({ runs, activeCount, skills }: Props) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-200">Agents</h2>
                    <div className="flex items-center gap-3">
                        {activeCount > 0 && (
                            <span className="flex items-center gap-2 rounded-lg bg-blue-900/30 px-3 py-1.5 text-xs text-blue-400">
                                <span className="h-2 w-2 animate-pulse rounded-full bg-blue-400" />
                                {activeCount} running
                            </span>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Agents" />

            {runs.data.length === 0 ? (
                <div className="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
                    <svg className="mx-auto h-12 w-12 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth={1} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                    </svg>
                    <h3 className="mt-4 text-sm font-medium text-gray-300">No agent runs yet</h3>
                    <p className="mt-2 text-sm text-gray-500">
                        Move a ticket to "Ready for Agent" on the Kanban board to trigger an agent run.
                    </p>
                </div>
            ) : (
                <div className="rounded-xl border border-gray-800 bg-gray-900">
                    <table className="min-w-full">
                        <thead>
                            <tr className="border-b border-gray-800">
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Run</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Ticket</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skill</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-800">
                            {runs.data.map((run) => {
                                const colors = statusColors[run.status] ?? statusColors.queued;
                                return (
                                    <tr key={run.id} className="hover:bg-gray-800/50">
                                        <td className="px-4 py-3">
                                            <Link href={route('agents.show', { agentRun: run.id })} className="text-sm font-medium text-indigo-400 hover:text-indigo-300">
                                                #{run.id}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-400 truncate max-w-[200px]">
                                            {run.ticket ? run.ticket.title : '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="rounded bg-gray-700 px-2 py-0.5 text-xs text-gray-300">{run.skill}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded px-2 py-0.5 text-xs ${colors.bg} ${colors.text}`}>
                                                {run.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500">
                                            {run.duration_seconds ? `${run.duration_seconds}s` : '-'}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500">
                                            {new Date(run.created_at).toLocaleString()}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
