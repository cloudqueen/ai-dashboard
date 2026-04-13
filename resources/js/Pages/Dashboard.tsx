import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

interface Props {
    vaultConfigured: boolean;
    kanbanSummary: { key: string; label: string; count: number }[];
    dueSoon: { title: string; relative_path: string; due_date: string; priority: string; status: string }[];
    agentStatus: { active: number; recent: { id: number; skill: string; status: string; created_at: string }[] };
    recentActivity: { id: number; ticket_path: string; event_type: string; to_status: string | null; created_at: string }[];
}

export default function Dashboard({ vaultConfigured, kanbanSummary, dueSoon, agentStatus, recentActivity }: Props) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-200">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
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

                {/* Due Soon */}
                <Card title="Due Soon" description="Next 7 days">
                    {dueSoon.length > 0 ? (
                        <div className="space-y-2">
                            {dueSoon.map((t) => (
                                <Link key={t.relative_path} href={route('vault.show', { path: t.relative_path })} className="block text-xs hover:bg-gray-800/50 rounded px-1 py-1">
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
                <Card title="Activity" description="Recent events">
                    {recentActivity.length > 0 ? (
                        <div className="space-y-1.5">
                            {recentActivity.slice(0, 5).map((e) => (
                                <div key={e.id} className="text-xs text-gray-400">
                                    <span className="text-gray-500">{e.event_type}</span>
                                    {e.to_status && <span> → {e.to_status}</span>}
                                    <span className="text-gray-600 ml-1">
                                        {e.ticket_path.replace(/\.md$/, '').split('/').pop()}
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-gray-500">No recent activity</p>
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
