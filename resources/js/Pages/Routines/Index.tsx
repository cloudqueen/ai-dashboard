import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface RoutineRun {
    id: number;
    status: string;
    summary: string | null;
    output_note_path: string | null;
    completed_at: string | null;
}

interface Routine {
    id: number;
    name: string;
    description: string | null;
    cron_expression: string;
    skill: string;
    prompt_template: string;
    priority: string;
    model: string | null;
    enabled: boolean;
    last_run_at: string | null;
    next_run_at: string | null;
    latest_run: RoutineRun | null;
}

interface Skill {
    name: string;
    display_name: string;
    source: string;
}

interface Props {
    routines: Routine[];
    skills: Skill[];
}

const CRON_PRESETS = [
    { label: 'Täglich 5:00', value: '0 5 * * *' },
    { label: 'Täglich 8:00', value: '0 8 * * *' },
    { label: 'Mo-Fr 7:00', value: '0 7 * * 1-5' },
    { label: 'Montags 9:00', value: '0 9 * * 1' },
    { label: 'Alle 6 Stunden', value: '0 */6 * * *' },
    { label: 'Sonntags 10:00', value: '0 10 * * 0' },
];

export default function RoutinesIndex({ routines, skills }: Props) {
    const [showCreate, setShowCreate] = useState(false);
    const [expandedId, setExpandedId] = useState<number | null>(null);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-200">Routines</h2>
                    <button
                        onClick={() => setShowCreate(true)}
                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500"
                    >
                        + Neue Routine
                    </button>
                </div>
            }
        >
            <Head title="Routines" />

            {showCreate && <CreateRoutineForm skills={skills} onClose={() => setShowCreate(false)} />}

            <div className="space-y-3">
                {routines.length === 0 && !showCreate && (
                    <div className="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
                        <p className="text-sm text-gray-500">Noch keine Routines. Erstelle eine wiederkehrende Aufgabe.</p>
                    </div>
                )}

                {routines.map((routine) => (
                    <RoutineCard
                        key={routine.id}
                        routine={routine}
                        expanded={expandedId === routine.id}
                        onToggle={() => setExpandedId(expandedId === routine.id ? null : routine.id)}
                    />
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function RoutineCard({ routine, expanded, onToggle }: { routine: Routine; expanded: boolean; onToggle: () => void }) {
    const [runs, setRuns] = useState<RoutineRun[]>([]);
    const [loadingRuns, setLoadingRuns] = useState(false);

    const loadRuns = () => {
        if (runs.length > 0) return;
        setLoadingRuns(true);
        fetch(route('routines.runs', { routine: routine.id }), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((data) => { setRuns(data); setLoadingRuns(false); })
            .catch(() => setLoadingRuns(false));
    };

    const handleToggle = () => {
        onToggle();
        if (!expanded) loadRuns();
    };

    const statusColor = routine.latest_run?.status === 'completed' ? 'bg-green-400'
        : routine.latest_run?.status === 'failed' ? 'bg-red-400'
        : routine.latest_run?.status === 'running' ? 'bg-blue-400 animate-pulse'
        : 'bg-gray-600';

    return (
        <div className={`rounded-xl border bg-gray-900 ${routine.enabled ? 'border-gray-800' : 'border-gray-800/50 opacity-60'}`}>
            <div className="flex items-center gap-4 p-5 cursor-pointer" onClick={handleToggle}>
                <span className={`h-2.5 w-2.5 flex-shrink-0 rounded-full ${statusColor}`} />
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                        <h3 className="text-sm font-medium text-gray-200">{routine.name}</h3>
                        <code className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] text-gray-400">{routine.cron_expression}</code>
                        <span className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] text-gray-500">{routine.skill}</span>
                    </div>
                    {routine.description && (
                        <p className="mt-0.5 text-xs text-gray-500 truncate">{routine.description}</p>
                    )}
                </div>
                <div className="flex items-center gap-3 flex-shrink-0">
                    {routine.next_run_at && (
                        <span className="text-[10px] text-gray-500">
                            Nächster: {new Date(routine.next_run_at).toLocaleDateString('de-DE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                        </span>
                    )}
                    <button
                        onClick={(e) => { e.stopPropagation(); router.post(route('routines.trigger', { routine: routine.id })); }}
                        className="rounded-lg bg-gray-800 px-2.5 py-1 text-[10px] text-indigo-400 hover:bg-gray-700"
                        title="Jetzt ausführen"
                    >
                        Starten
                    </button>
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            router.patch(route('routines.update', { routine: routine.id }), { enabled: !routine.enabled });
                        }}
                        className={`rounded-lg px-2.5 py-1 text-[10px] ${routine.enabled ? 'bg-green-900/30 text-green-400' : 'bg-gray-800 text-gray-500'}`}
                    >
                        {routine.enabled ? 'Aktiv' : 'Inaktiv'}
                    </button>
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            if (confirm('Routine löschen?')) router.delete(route('routines.destroy', { routine: routine.id }));
                        }}
                        className="text-gray-600 hover:text-red-400"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            {expanded && (
                <div className="border-t border-gray-800 p-5">
                    <p className="mb-3 text-xs text-gray-400 whitespace-pre-wrap">{routine.prompt_template}</p>

                    <h4 className="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Letzte Ausführungen</h4>
                    {loadingRuns ? (
                        <p className="text-xs text-gray-600">Laden...</p>
                    ) : runs.length > 0 ? (
                        <div className="space-y-1.5">
                            {runs.slice(0, 5).map((run) => (
                                <div key={run.id} className="flex items-center justify-between rounded-lg bg-gray-800/50 px-3 py-2">
                                    <div className="flex items-center gap-2">
                                        <span className={`h-1.5 w-1.5 rounded-full ${
                                            run.status === 'completed' ? 'bg-green-400' :
                                            run.status === 'failed' ? 'bg-red-400' :
                                            run.status === 'running' ? 'bg-blue-400 animate-pulse' : 'bg-gray-500'
                                        }`} />
                                        <span className="text-xs text-gray-400">{run.status}</span>
                                    </div>
                                    {run.summary && (
                                        <p className="text-[10px] text-gray-500 truncate max-w-[300px] ml-3">{run.summary}</p>
                                    )}
                                    {run.completed_at && (
                                        <span className="text-[10px] text-gray-600 flex-shrink-0 ml-2">
                                            {new Date(run.completed_at).toLocaleDateString('de-DE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-xs text-gray-600">Noch keine Ausführungen</p>
                    )}
                </div>
            )}
        </div>
    );
}

function CreateRoutineForm({ skills, onClose }: { skills: Skill[]; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        cron_expression: '0 5 * * *',
        skill: skills[0]?.name ?? 'research',
        prompt_template: '',
        priority: 'medium',
        model: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('routines.store'), { onSuccess: () => onClose() });
    };

    return (
        <div className="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-6">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-sm font-medium text-gray-200">Neue Routine</h3>
                <button onClick={onClose} className="text-gray-500 hover:text-gray-300">
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <label className="mb-1 block text-xs text-gray-500">Name</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="z.B. AI News Digest"
                            className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                            autoFocus
                        />
                        {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
                    </div>

                    <div>
                        <label className="mb-1 block text-xs text-gray-500">Zeitplan (Cron)</label>
                        <select
                            value={CRON_PRESETS.find((p) => p.value === data.cron_expression) ? data.cron_expression : '__custom'}
                            onChange={(e) => { if (e.target.value !== '__custom') setData('cron_expression', e.target.value); }}
                            className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                        >
                            {CRON_PRESETS.map((p) => (
                                <option key={p.value} value={p.value}>{p.label}</option>
                            ))}
                            <option value="__custom">Benutzerdefiniert</option>
                        </select>
                        <input
                            type="text"
                            value={data.cron_expression}
                            onChange={(e) => setData('cron_expression', e.target.value)}
                            className="mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs text-gray-400 font-mono focus:border-indigo-500 focus:outline-none"
                        />
                    </div>

                    <div>
                        <label className="mb-1 block text-xs text-gray-500">Skill</label>
                        <select
                            value={data.skill}
                            onChange={(e) => setData('skill', e.target.value)}
                            className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                        >
                            {skills.map((s) => (
                                <option key={s.name} value={s.name}>
                                    {s.display_name}{s.source === 'vault' ? ' (vault)' : ''}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="mb-1 block text-xs text-gray-500">Priorität</label>
                        <select
                            value={data.priority}
                            onChange={(e) => setData('priority', e.target.value)}
                            className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                        >
                            <option value="low">Niedrig</option>
                            <option value="medium">Mittel</option>
                            <option value="high">Hoch</option>
                        </select>
                    </div>

                    <div>
                        <label className="mb-1 block text-xs text-gray-500">Modell (optional)</label>
                        <select
                            value={data.model}
                            onChange={(e) => setData('model', e.target.value)}
                            className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                        >
                            <option value="">Standard</option>
                            <option value="claude-opus-4-6">Opus 4.6</option>
                            <option value="claude-sonnet-4-6">Sonnet 4.6</option>
                            <option value="claude-haiku-4-5-20251001">Haiku 4.5</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label className="mb-1 block text-xs text-gray-500">Aufgabenbeschreibung (Prompt)</label>
                    <textarea
                        value={data.prompt_template}
                        onChange={(e) => setData('prompt_template', e.target.value)}
                        placeholder="Beschreibe was der Agent bei jeder Ausführung tun soll..."
                        rows={4}
                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                    {errors.prompt_template && <p className="mt-1 text-xs text-red-400">{errors.prompt_template}</p>}
                </div>

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-lg bg-gray-800 px-4 py-2 text-xs text-gray-300 hover:bg-gray-700">
                        Abbrechen
                    </button>
                    <button type="submit" disabled={processing} className="rounded-lg bg-indigo-600 px-4 py-2 text-xs text-white hover:bg-indigo-500 disabled:opacity-50">
                        Routine erstellen
                    </button>
                </div>
            </form>
        </div>
    );
}
