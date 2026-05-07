import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Project {
    id: number;
    name: string;
    path: string;
    status: 'active' | 'paused' | 'done';
    default_branch: string;
    allowed_tools: string;
    model: string | null;
    max_run_minutes: number;
    max_turns: number;
    nightly_enabled: boolean;
    nightly_schedule: string;
    consecutive_failures: number;
    last_run_at: string | null;
    state_path: string;
}

interface Run {
    id: number;
    status: string;
    branch_name: string | null;
    worktree_path: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
    tokens_used: number | null;
    summary: string | null;
    error_message: string | null;
    review_ticket_id: number | null;
}

interface Worktree {
    path: string;
    branch?: string;
    head?: string;
}

interface Props {
    project: Project;
    nightlyMd: string;
    reviewMd: string;
    runs: Run[];
    activeWorktrees: Worktree[];
}

const statusBadge: Record<string, string> = {
    completed: 'bg-emerald-900/40 text-emerald-400',
    running: 'bg-blue-900/40 text-blue-400',
    queued: 'bg-gray-800 text-gray-400',
    failed: 'bg-red-900/40 text-red-400',
    timed_out: 'bg-yellow-900/40 text-yellow-400',
};

export default function ProjectsShow({ project, nightlyMd: initialNightly, reviewMd, runs, activeWorktrees }: Props) {
    const [nightlyMd, setNightlyMd] = useState(initialNightly);
    const [savingNightly, setSavingNightly] = useState(false);
    const [running, setRunning] = useState(false);

    const dirty = nightlyMd !== initialNightly;

    const saveNightly = () => {
        setSavingNightly(true);
        router.patch(route('projects.nightly', { project: project.id }), { content: nightlyMd }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSavingNightly(false),
        });
    };

    const runNow = () => {
        if (!confirm('Nightly-Run jetzt starten? Das kann mehrere Minuten dauern.')) return;
        setRunning(true);
        router.post(route('projects.run', { project: project.id }), {}, {
            preserveScroll: true,
            onFinish: () => setRunning(false),
        });
    };

    const togglePause = () => {
        const newStatus = project.status === 'active' ? 'paused' : 'active';
        router.patch(route('projects.update', { project: project.id }), { status: newStatus }, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Link href={route('projects')} className="text-gray-500 hover:text-gray-300">←</Link>
                        <h2 className="text-xl font-semibold text-gray-200">{project.name}</h2>
                        <span className={`rounded px-2 py-0.5 text-[10px] uppercase tracking-wider ${
                            project.status === 'active' ? 'bg-emerald-900/40 text-emerald-400' :
                            project.status === 'paused' ? 'bg-yellow-900/40 text-yellow-400' :
                            'bg-gray-800 text-gray-400'
                        }`}>{project.status}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            onClick={togglePause}
                            className="rounded-md bg-gray-800 px-3 py-1.5 text-xs text-gray-300 hover:bg-gray-700"
                        >
                            {project.status === 'active' ? 'Pausieren' : 'Aktivieren'}
                        </button>
                        <button
                            onClick={runNow}
                            disabled={running || project.status !== 'active'}
                            className="rounded-md bg-purple-600 px-3 py-1.5 text-xs text-white hover:bg-purple-500 disabled:opacity-50"
                        >
                            {running ? 'Läuft…' : 'Jetzt nightly run'}
                        </button>
                    </div>
                </div>
            }
        >
            <Head title={project.name} />

            <div className="grid gap-6 lg:grid-cols-3">
                {/* Left column: nightly.md editor + review */}
                <div className="space-y-6 lg:col-span-2">
                    <Card title="nightly.md" subtitle="Tasks für den nächsten Run. Coach kann diese via MCP aktualisieren.">
                        <textarea
                            value={nightlyMd}
                            onChange={(e) => setNightlyMd(e.target.value)}
                            rows={18}
                            className="w-full rounded-md border-gray-700 bg-gray-950 font-mono text-xs text-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <div className="mt-2 flex items-center gap-3">
                            <button
                                onClick={saveNightly}
                                disabled={savingNightly || !dirty}
                                className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs text-white hover:bg-indigo-500 disabled:opacity-50"
                            >
                                {savingNightly ? 'Speichere…' : 'Speichern'}
                            </button>
                            {dirty && <span className="text-xs text-yellow-400">Ungespeichert</span>}
                        </div>
                    </Card>

                    {reviewMd.trim() !== '' && (
                        <Card title="review.md" subtitle="Letzter Output zur Review.">
                            <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-md bg-gray-950 p-3 text-xs text-gray-300">{reviewMd}</pre>
                        </Card>
                    )}
                </div>

                {/* Right column: meta + runs + branches */}
                <div className="space-y-6">
                    <Card title="Konfiguration">
                        <dl className="space-y-1.5 text-xs">
                            <Field label="Pfad" value={<code className="font-mono text-[11px] text-gray-300 break-all">{project.path}</code>} />
                            <Field label="Default-Branch" value={project.default_branch} />
                            <Field label="Tools" value={project.allowed_tools} />
                            <Field label="Model" value={project.model ?? '—'} />
                            <Field label="Max Minuten" value={`${project.max_run_minutes}`} />
                            <Field label="Max Turns" value={`${project.max_turns}`} />
                            <Field label="Nightly" value={`${project.nightly_enabled ? 'an' : 'aus'} · ${project.nightly_schedule}`} />
                            <Field label="State" value={<code className="font-mono text-[10px] text-gray-400 break-all">{project.state_path}</code>} />
                        </dl>
                    </Card>

                    {activeWorktrees.length > 0 && (
                        <Card title="Aktive Nightly-Worktrees" subtitle="Per `git worktree remove` aufräumen, wenn nicht mehr gebraucht.">
                            <ul className="space-y-1.5 text-[11px]">
                                {activeWorktrees.map((w) => (
                                    <li key={w.path}>
                                        <code className="text-gray-400 break-all">{w.path}</code>
                                        {w.branch && <div className="text-gray-600">{w.branch}</div>}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    <Card title="Recent Runs" subtitle={`${runs.length} letzte Runs`}>
                        {runs.length === 0 ? (
                            <p className="text-xs text-gray-500">Noch keine Runs.</p>
                        ) : (
                            <ul className="space-y-2">
                                {runs.map((r) => (
                                    <li key={r.id} className="rounded-md border border-gray-800 bg-gray-950/50 px-3 py-2">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-gray-300">#{r.id}</span>
                                            <span className={`rounded px-1.5 py-0.5 text-[10px] ${statusBadge[r.status] ?? 'bg-gray-800 text-gray-400'}`}>
                                                {r.status}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-[10px] text-gray-500">
                                            {r.started_at && new Date(r.started_at).toLocaleString('de-DE')}
                                            {r.duration_seconds !== null && ` · ${r.duration_seconds}s`}
                                            {r.tokens_used !== null && ` · ${r.tokens_used.toLocaleString()} tokens`}
                                        </div>
                                        {r.branch_name && (
                                            <code className="mt-1 block text-[10px] text-gray-500 break-all">⎇ {r.branch_name}</code>
                                        )}
                                        {r.error_message && (
                                            <p className="mt-1 text-[10px] text-red-400">{r.error_message}</p>
                                        )}
                                        {r.summary && (
                                            <p className="mt-1 text-[10px] text-gray-400 line-clamp-2">{r.summary}</p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Card({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 className="text-sm font-medium text-gray-200">{title}</h3>
            {subtitle && <p className="mt-0.5 text-[11px] text-gray-500">{subtitle}</p>}
            <div className="mt-3">{children}</div>
        </div>
    );
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-gray-500">{label}</dt>
            <dd className="text-right text-gray-300">{value}</dd>
        </div>
    );
}
