import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

interface AgentRun {
    id: number;
    ticket_id: number | null;
    skill: string;
    status: string;
    prompt: string | null;
    raw_output: string | null;
    summary: string | null;
    error_message: string | null;
    duration_seconds: number | null;
    tokens_used: number | null;
    output_note_path: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
    ticket: { id: number; title: string; status: string } | null;
}

interface Props {
    run: AgentRun;
}

export default function AgentShow({ run }: Props) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-3">
                    <Link href={route('agents')} className="text-gray-500 hover:text-gray-300">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-gray-200">
                        Agent Run #{run.id}
                    </h2>
                    <StatusBadge status={run.status} />
                </div>
            }
        >
            <Head title={`Agent Run #${run.id}`} />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {/* Prompt */}
                    {run.prompt && (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
                            <h3 className="mb-3 text-sm font-medium text-gray-300">Prompt</h3>
                            <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-800 p-4 text-xs text-gray-400">
                                {run.prompt}
                            </pre>
                        </div>
                    )}

                    {/* Output */}
                    {run.raw_output && (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
                            <h3 className="mb-3 text-sm font-medium text-gray-300">Output</h3>
                            <pre className="max-h-[600px] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-800 p-4 text-xs text-gray-300">
                                {run.raw_output}
                            </pre>
                        </div>
                    )}

                    {/* Error */}
                    {run.error_message && (
                        <div className="rounded-xl border border-red-900/50 bg-red-950/20 p-6">
                            <h3 className="mb-3 text-sm font-medium text-red-400">Error</h3>
                            <pre className="whitespace-pre-wrap text-xs text-red-300">
                                {run.error_message}
                            </pre>
                        </div>
                    )}
                </div>

                {/* Sidebar */}
                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-800 bg-gray-900 p-4">
                        <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Details</h3>
                        <dl className="space-y-2 text-sm">
                            <div>
                                <dt className="text-xs text-gray-500">Skill</dt>
                                <dd><span className="rounded bg-gray-700 px-2 py-0.5 text-xs text-gray-300">{run.skill}</span></dd>
                            </div>
                            {run.ticket && (
                                <div>
                                    <dt className="text-xs text-gray-500">Source Ticket</dt>
                                    <dd>
                                        <Link
                                            href={route('kanban')}
                                            className="text-xs text-indigo-400 hover:text-indigo-300"
                                        >
                                            #{run.ticket.id} {run.ticket.title}
                                        </Link>
                                    </dd>
                                </div>
                            )}
                            {run.output_note_path && (
                                <div>
                                    <dt className="text-xs text-gray-500">Output File</dt>
                                    <dd className="text-xs text-gray-400 break-all">{run.output_note_path}</dd>
                                </div>
                            )}
                            {run.duration_seconds !== null && (
                                <div>
                                    <dt className="text-xs text-gray-500">Duration</dt>
                                    <dd className="text-gray-400">{run.duration_seconds}s</dd>
                                </div>
                            )}
                            {run.tokens_used !== null && (
                                <div>
                                    <dt className="text-xs text-gray-500">Tokens</dt>
                                    <dd className="text-gray-400">{run.tokens_used.toLocaleString()}</dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-xs text-gray-500">Created</dt>
                                <dd className="text-gray-400">{new Date(run.created_at).toLocaleString()}</dd>
                            </div>
                            {run.started_at && (
                                <div>
                                    <dt className="text-xs text-gray-500">Started</dt>
                                    <dd className="text-gray-400">{new Date(run.started_at).toLocaleString()}</dd>
                                </div>
                            )}
                            {run.completed_at && (
                                <div>
                                    <dt className="text-xs text-gray-500">Completed</dt>
                                    <dd className="text-gray-400">{new Date(run.completed_at).toLocaleString()}</dd>
                                </div>
                            )}
                        </dl>
                    </div>

                    {run.summary && (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-4">
                            <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Summary</h3>
                            <p className="text-sm text-gray-400">{run.summary}</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function StatusBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        queued: 'bg-gray-700 text-gray-300',
        running: 'bg-blue-900/50 text-blue-400',
        completed: 'bg-green-900/50 text-green-400',
        failed: 'bg-red-900/50 text-red-400',
        timed_out: 'bg-yellow-900/50 text-yellow-400',
    };

    return (
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${styles[status] ?? styles.queued}`}>
            {status}
        </span>
    );
}
