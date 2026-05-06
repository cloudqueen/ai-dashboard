import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { useDashboardEvents } from '@/hooks/useDashboardEvents';

interface PsychInsights {
    completed_30d: number;
    postponements_30d: number;
    avoided_tasks: number;
    wip_count: number;
    wip_soft_limit: number;
    wip_hard_limit: number;
    wip_status: 'ok' | 'warning' | 'overloaded';
    streak_days: number;
    active_interventions: Intervention[];
}

interface Intervention {
    id: number;
    framework: string;
    intervention_type: string;
    trigger: string;
    ticket_path: string | null;
    content: string;
    was_helpful: boolean | null;
    dismissed: boolean;
    created_at: string;
}

interface UsageStats {
    today: { input_tokens: number; output_tokens: number; cost_usd: number; calls: number };
    month: { input_tokens: number; output_tokens: number; cost_usd: number; calls: number };
}

interface Props {
    vaultConfigured: boolean;
    kanbanSummary: { key: string; label: string; count: number }[];
    dueSoon: { id: number; title: string; due_date: string; priority: string; status: string }[];
    agentStatus: { active: number; recent: { id: number; skill: string; status: string; created_at: string; ticket: { id: number; title: string } | null }[] };
    recentActivity: { actor_type: string; action: string; entity_type: string; entity_id: string | null; details: Record<string, unknown> | null; created_at: string }[];
    psychInsights: PsychInsights | null;
    usageStats: UsageStats | null;
    routineResults: { id: number; routine_id: number; routine: { id: number; name: string } | null; summary: string | null; output_note_path: string | null; completed_at: string }[];
}

export default function Dashboard({ vaultConfigured, kanbanSummary, dueSoon, agentStatus, recentActivity, psychInsights, usageStats, routineResults }: Props) {
    useDashboardEvents();

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-200">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            {/* Psychology interventions banner */}
            {psychInsights && psychInsights.active_interventions.length > 0 && (
                <div className="mb-6 space-y-3">
                    {psychInsights.active_interventions.map((intervention) => (
                        <InterventionCard key={intervention.id} intervention={intervention} />
                    ))}
                </div>
            )}

            {/* Routine Results — prominent */}
            {routineResults.length > 0 && (
                <div className="mb-6 space-y-3">
                    <h3 className="text-xs font-medium uppercase tracking-wider text-gray-500">Routine-Ergebnisse</h3>
                    {routineResults.map((r) => (
                        <div key={r.id} className="rounded-xl border border-indigo-800/30 bg-indigo-900/10 p-5">
                            <div className="mb-2 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-indigo-400" />
                                    <span className="text-sm font-medium text-gray-200">{r.routine?.name ?? 'Routine'}</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-[10px] text-gray-500">
                                        {new Date(r.completed_at).toLocaleDateString('de-DE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                    </span>
                                    {r.output_note_path && (
                                        <Link
                                            href={route('vault.show', { path: r.output_note_path })}
                                            className="rounded-md bg-gray-800 px-2 py-0.5 text-[10px] text-indigo-400 hover:bg-gray-700"
                                        >
                                            Vollständig lesen
                                        </Link>
                                    )}
                                </div>
                            </div>
                            {r.summary && (
                                <p className="text-sm leading-relaxed text-gray-300 line-clamp-4">{r.summary}</p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                {/* Psychology Insights */}
                {psychInsights && (
                    <PsychCard insights={psychInsights} />
                )}

                {/* Kanban Summary */}
                <Card title="Kanban" description="Task overview">
                    {kanbanSummary.length > 0 ? (
                        <div className="space-y-1.5">
                            {kanbanSummary.map((s) => (
                                <div key={s.key} className="flex items-center justify-between">
                                    <span className="text-xs text-gray-400">{s.label}</span>
                                    <span className={`text-sm font-medium ${s.count > 0 ? 'text-gray-200' : 'text-gray-600'}`}>
                                        {s.count}
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-gray-500">
                            {vaultConfigured ? 'No tickets yet.' : 'Connect vault to get started.'}
                        </p>
                    )}
                </Card>

                {/* Agent Status */}
                <Card title="Agents" description="AI agent status">
                    <div className="flex items-center gap-2 mb-3">
                        <span className={`h-2 w-2 rounded-full ${agentStatus.active > 0 ? 'bg-blue-400 animate-pulse' : 'bg-gray-600'}`} />
                        <span className="text-sm text-gray-400">
                            {agentStatus.active > 0 ? `${agentStatus.active} running` : 'No agents running'}
                        </span>
                    </div>
                    {agentStatus.recent.length > 0 && (
                        <div className="space-y-1">
                            {agentStatus.recent.slice(0, 3).map((r) => (
                                <Link key={r.id} href={route('agents.show', { agentRun: r.id })} className="flex items-center justify-between text-xs hover:bg-gray-800/50 rounded px-1 py-0.5">
                                    <span className="text-gray-400">#{r.id} {r.skill}</span>
                                    <StatusDot status={r.status} />
                                </Link>
                            ))}
                        </div>
                    )}
                </Card>

                {/* Vault Status */}
                <Card title="Vault" description="Memory system">
                    <div className="flex items-center gap-2">
                        <span className={`h-2 w-2 rounded-full ${vaultConfigured ? 'bg-green-500' : 'bg-yellow-500'}`} />
                        <span className="text-sm text-gray-400">
                            {vaultConfigured ? 'Connected' : 'Not configured'}
                        </span>
                    </div>
                    {!vaultConfigured && (
                        <p className="mt-2 text-xs text-gray-500">Set VAULT_PATH in .env</p>
                    )}
                </Card>

                {/* Usage Stats */}
                {usageStats && (
                    <Card title="Token Usage" description="Claude Verbrauch">
                        <div className="space-y-3">
                            <div>
                                <p className="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Heute</p>
                                <div className="flex items-baseline gap-2">
                                    <span className="text-lg font-semibold text-gray-200">
                                        {formatTokens(usageStats.today.input_tokens + usageStats.today.output_tokens)}
                                    </span>
                                    <span className="text-xs text-gray-500">tokens</span>
                                    {usageStats.today.cost_usd > 0 && (
                                        <span className="text-xs text-gray-500 ml-auto">
                                            ~${Number(usageStats.today.cost_usd).toFixed(2)}
                                        </span>
                                    )}
                                </div>
                                <p className="text-[10px] text-gray-600">{usageStats.today.calls} calls</p>
                            </div>
                            <div className="border-t border-gray-800 pt-2">
                                <p className="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Diesen Monat</p>
                                <div className="flex items-baseline gap-2">
                                    <span className="text-sm font-medium text-gray-300">
                                        {formatTokens(usageStats.month.input_tokens + usageStats.month.output_tokens)}
                                    </span>
                                    <span className="text-xs text-gray-500">tokens</span>
                                    {usageStats.month.cost_usd > 0 && (
                                        <span className="text-xs text-gray-500 ml-auto">
                                            ~${Number(usageStats.month.cost_usd).toFixed(2)}
                                        </span>
                                    )}
                                </div>
                                <p className="text-[10px] text-gray-600">{usageStats.month.calls} calls</p>
                            </div>
                        </div>
                    </Card>
                )}

                {/* Due Soon */}
                <Card title="Due Soon" description="Next 7 days">
                    {dueSoon.length > 0 ? (
                        <div className="space-y-2">
                            {dueSoon.map((t) => (
                                <Link key={t.id} href={route('kanban')} className="block text-xs hover:bg-gray-800/50 rounded px-1 py-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-gray-300 truncate">{t.title}</span>
                                        <span className="text-gray-500 flex-shrink-0 ml-2">{t.due_date}</span>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-gray-500">No upcoming deadlines</p>
                    )}
                </Card>

                {/* Recent Activity */}
                <Card title="Aktivität" description="Letzte Ereignisse">
                    {recentActivity.length > 0 ? (
                        <div className="space-y-1.5">
                            {recentActivity.slice(0, 8).map((e, i) => (
                                <div key={i} className="flex items-start gap-2 text-xs">
                                    <span className={`mt-0.5 h-1.5 w-1.5 flex-shrink-0 rounded-full ${
                                        e.actor_type === 'agent' ? 'bg-purple-400' :
                                        e.actor_type === 'human' ? 'bg-indigo-400' : 'bg-gray-500'
                                    }`} />
                                    <div className="min-w-0">
                                        <span className="text-gray-400">{e.action}</span>
                                        {e.entity_id && (
                                            <span className="text-gray-500 ml-1">
                                                {String(e.entity_id).replace(/\.md$/, '').split('/').pop()}
                                            </span>
                                        )}
                                        {e.details?.to && (
                                            <span className="text-gray-600"> → {String(e.details.to)}</span>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-gray-500">Keine Aktivität</p>
                    )}
                </Card>

                {/* Quick Actions */}
                <Card title="Quick Actions" description="Shortcuts">
                    <div className="space-y-2">
                        <Link href={route('kanban')} className="block rounded-lg bg-gray-800 px-3 py-2 text-xs text-gray-300 hover:bg-gray-700 transition-colors">
                            Open Kanban Board
                        </Link>
                        <Link href={route('vault')} className="block rounded-lg bg-gray-800 px-3 py-2 text-xs text-gray-300 hover:bg-gray-700 transition-colors">
                            Browse Vault
                        </Link>
                        <Link href={route('agents')} className="block rounded-lg bg-gray-800 px-3 py-2 text-xs text-gray-300 hover:bg-gray-700 transition-colors">
                            View Agent Runs
                        </Link>
                    </div>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

// --- Psychology Card ---

function PsychCard({ insights }: { insights: PsychInsights }) {
    const wipColor = insights.wip_status === 'overloaded'
        ? 'text-red-400'
        : insights.wip_status === 'warning'
            ? 'text-amber-400'
            : 'text-green-400';

    return (
        <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
            <div className="mb-4">
                <h3 className="text-sm font-medium text-gray-200">Mind State</h3>
                <p className="text-xs text-gray-500">Chimp Paradox & ZRM</p>
            </div>

            <div className="space-y-3">
                {/* Streak */}
                <div className="flex items-center justify-between">
                    <span className="text-xs text-gray-400">Completion streak</span>
                    <span className="text-sm font-medium text-gray-200">
                        {insights.streak_days > 0 ? `${insights.streak_days}d` : '--'}
                    </span>
                </div>

                {/* WIP */}
                <div className="flex items-center justify-between">
                    <span className="text-xs text-gray-400">Work in progress</span>
                    <span className={`text-sm font-medium ${wipColor}`}>
                        {insights.wip_count}/{insights.wip_soft_limit}
                    </span>
                </div>

                {/* Completions */}
                <div className="flex items-center justify-between">
                    <span className="text-xs text-gray-400">Done (30d)</span>
                    <span className="text-sm font-medium text-gray-200">{insights.completed_30d}</span>
                </div>

                {/* Postponements */}
                {insights.postponements_30d > 0 && (
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-gray-400">Postponed (30d)</span>
                        <span className="text-sm font-medium text-amber-400">{insights.postponements_30d}</span>
                    </div>
                )}

                {/* Avoided */}
                {insights.avoided_tasks > 0 && (
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-gray-400">High-charge avoided</span>
                        <span className="text-sm font-medium text-red-400">{insights.avoided_tasks}</span>
                    </div>
                )}

                {/* WIP bar */}
                <div className="pt-1">
                    <div className="h-1.5 rounded-full bg-gray-800">
                        <div
                            className={`h-1.5 rounded-full transition-all ${
                                insights.wip_status === 'overloaded' ? 'bg-red-500' :
                                insights.wip_status === 'warning' ? 'bg-amber-500' : 'bg-green-500'
                            }`}
                            style={{ width: `${Math.min((insights.wip_count / insights.wip_hard_limit) * 100, 100)}%` }}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}

// --- Intervention Card ---

function InterventionCard({ intervention }: { intervention: Intervention }) {
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) return null;

    const isChimp = intervention.framework === 'chimp';
    const borderColor = isChimp ? 'border-amber-800/50' : 'border-teal-800/50';
    const bgColor = isChimp ? 'bg-amber-900/10' : 'bg-teal-900/10';
    const labelColor = isChimp ? 'text-amber-400' : 'text-teal-400';
    const label = isChimp ? 'Chimp Paradox' : 'ZRM';
    const typeLabel = intervention.intervention_type.replace(/_/g, ' ');

    const handleFeedback = (helpful: boolean) => {
        router.post(route('psych.feedback'), {
            id: intervention.id,
            was_helpful: helpful,
        }, { preserveState: true, preserveScroll: true });
        setDismissed(true);
    };

    const handleDismiss = () => {
        router.post(route('psych.dismiss'), {
            id: intervention.id,
        }, { preserveState: true, preserveScroll: true });
        setDismissed(true);
    };

    return (
        <div className={`rounded-xl border ${borderColor} ${bgColor} p-5`}>
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <span className={`rounded-md px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ${labelColor} ${isChimp ? 'bg-amber-900/30' : 'bg-teal-900/30'}`}>
                        {label}
                    </span>
                    <span className="text-[10px] text-gray-500">{typeLabel}</span>
                </div>
                <button onClick={handleDismiss} className="text-gray-600 hover:text-gray-400">
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div
                className="text-sm leading-relaxed text-gray-300"
                dangerouslySetInnerHTML={{ __html: markdownBold(intervention.content) }}
            />

            {/* Feedback buttons */}
            <div className="mt-3 flex items-center gap-2">
                <button
                    onClick={() => handleFeedback(true)}
                    className="rounded-md bg-gray-800 px-2.5 py-1 text-[10px] text-gray-400 hover:bg-gray-700 hover:text-green-400 transition-colors"
                >
                    Helpful
                </button>
                <button
                    onClick={() => handleFeedback(false)}
                    className="rounded-md bg-gray-800 px-2.5 py-1 text-[10px] text-gray-400 hover:bg-gray-700 hover:text-gray-300 transition-colors"
                >
                    Not helpful
                </button>
            </div>
        </div>
    );
}

// Simple **bold** to <strong> converter
function markdownBold(text: string): string {
    return text
        .replace(/\*\*(.+?)\*\*/g, '<strong class="text-gray-100">$1</strong>')
        .replace(/\n/g, '<br />');
}

// --- Shared components ---

function Card({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
            <div className="mb-4">
                <h3 className="text-sm font-medium text-gray-200">{title}</h3>
                <p className="text-xs text-gray-500">{description}</p>
            </div>
            {children}
        </div>
    );
}

function formatTokens(n: number): string {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return String(n);
}

function StatusDot({ status }: { status: string }) {
    const colors: Record<string, string> = {
        queued: 'bg-gray-500',
        running: 'bg-blue-400 animate-pulse',
        completed: 'bg-green-400',
        failed: 'bg-red-400',
        timed_out: 'bg-yellow-400',
    };

    return (
        <span className={`inline-block h-1.5 w-1.5 rounded-full ${colors[status] ?? colors.queued}`} />
    );
}
