import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Project {
    id: number;
    name: string;
    path: string;
    status: 'active' | 'paused' | 'done';
    default_branch: string;
    nightly_enabled: boolean;
    last_run_at: string | null;
    consecutive_failures: number;
    awaiting_review: boolean;
}

interface Props {
    projects: Project[];
    allowedRoots: string[];
}

export default function ProjectsIndex({ projects, allowedRoots }: Props) {
    const [showCreate, setShowCreate] = useState(false);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold text-gray-200">Projects</h2>
                    <button
                        onClick={() => setShowCreate(true)}
                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500"
                    >
                        + Neues Projekt
                    </button>
                </div>
            }
        >
            <Head title="Projects" />

            {showCreate && (
                <CreateForm allowedRoots={allowedRoots} onClose={() => setShowCreate(false)} />
            )}

            {projects.length === 0 ? (
                <div className="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
                    <p className="text-sm text-gray-500">
                        Noch keine Projekte. Lege eines an — dann läuft jede Nacht 02:00 ein
                        Claude-Code-Agent in einem isolierten Worktree-Branch.
                    </p>
                </div>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {projects.map((p) => <ProjectCard key={p.id} project={p} />)}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function ProjectCard({ project }: { project: Project }) {
    const statusColor = {
        active: 'bg-emerald-900/40 text-emerald-400',
        paused: 'bg-yellow-900/40 text-yellow-400',
        done: 'bg-gray-800 text-gray-400',
    }[project.status];

    return (
        <Link
            href={route('projects.show', { project: project.id })}
            className="block rounded-xl border border-gray-800 bg-gray-900 p-4 transition-all hover:border-gray-700"
        >
            <div className="flex items-start justify-between">
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-medium text-gray-100">{project.name}</h3>
                    <p className="mt-0.5 truncate text-[10px] text-gray-500">{project.path}</p>
                </div>
                <span className={`flex-shrink-0 rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wider ${statusColor}`}>
                    {project.status}
                </span>
            </div>

            <div className="mt-3 flex items-center gap-2 text-[10px]">
                <span className="rounded bg-gray-800 px-1.5 py-0.5 text-gray-400">⎇ {project.default_branch}</span>
                {project.nightly_enabled ? (
                    <span className="rounded bg-purple-900/30 px-1.5 py-0.5 text-purple-400">nightly</span>
                ) : (
                    <span className="rounded bg-gray-800 px-1.5 py-0.5 text-gray-600">nightly off</span>
                )}
                {project.consecutive_failures > 0 && (
                    <span className="rounded bg-red-900/40 px-1.5 py-0.5 text-red-400">
                        {project.consecutive_failures}× fail
                    </span>
                )}
            </div>

            <div className="mt-3 flex items-center justify-between text-[10px] text-gray-500">
                <span>{project.last_run_at ? `letzter Run: ${new Date(project.last_run_at).toLocaleString('de-DE')}` : 'noch kein Run'}</span>
                {project.awaiting_review && (
                    <span className="rounded-full bg-amber-500/20 px-2 py-0.5 text-amber-400">Review wartet</span>
                )}
            </div>
        </Link>
    );
}

function CreateForm({ allowedRoots, onClose }: { allowedRoots: string[]; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        path: '',
        default_branch: '',
        allowed_tools: 'all',
        model: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('projects.store'), {
            onSuccess: () => onClose(),
            preserveScroll: true,
        });
    };

    return (
        <div className="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-6">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-sm font-medium text-gray-200">Neues Projekt</h3>
                <button onClick={onClose} className="text-gray-500 hover:text-gray-300">✕</button>
            </div>

            <form onSubmit={submit} className="space-y-3">
                <div>
                    <label className="block text-xs text-gray-500">Name (kebab/snake_case)</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, '-'))}
                        className="mt-1 w-full rounded-md border-gray-700 bg-gray-950 text-sm text-gray-200"
                        autoFocus
                    />
                    {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
                </div>
                <div>
                    <label className="block text-xs text-gray-500">Absoluter Pfad zum Repo</label>
                    <input
                        type="text"
                        value={data.path}
                        onChange={(e) => setData('path', e.target.value)}
                        placeholder={allowedRoots[0] ?? '/Users/steffi/projekte/...'}
                        className="mt-1 w-full rounded-md border-gray-700 bg-gray-950 font-mono text-xs text-gray-200"
                    />
                    {errors.path && <p className="mt-1 text-xs text-red-400">{errors.path}</p>}
                    <p className="mt-1 text-[10px] text-gray-500">
                        Erlaubte Roots: {allowedRoots.map((r) => <code key={r} className="ml-1 text-gray-400">{r}</code>)}
                    </p>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label className="block text-xs text-gray-500">Default Branch (optional)</label>
                        <input
                            type="text"
                            value={data.default_branch}
                            onChange={(e) => setData('default_branch', e.target.value)}
                            placeholder="auto-detect"
                            className="mt-1 w-full rounded-md border-gray-700 bg-gray-950 text-sm text-gray-200"
                        />
                    </div>
                    <div>
                        <label className="block text-xs text-gray-500">Model-Override (optional)</label>
                        <input
                            type="text"
                            value={data.model}
                            onChange={(e) => setData('model', e.target.value)}
                            placeholder="claude-sonnet-4-6"
                            className="mt-1 w-full rounded-md border-gray-700 bg-gray-950 text-sm text-gray-200"
                        />
                    </div>
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className="rounded-md bg-gray-800 px-3 py-1.5 text-xs text-gray-300 hover:bg-gray-700">
                        Abbrechen
                    </button>
                    <button type="submit" disabled={processing || !data.name || !data.path}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs text-white hover:bg-indigo-500 disabled:opacity-50">
                        Anlegen
                    </button>
                </div>
            </form>
        </div>
    );
}
