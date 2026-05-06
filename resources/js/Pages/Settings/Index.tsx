import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Vault = {
    path: string | null;
    git_remote: string | null;
    sync_enabled: boolean;
    sync_branch: string | null;
    configured: boolean;
    notes_count: number;
    last_note_modified_at: string | null;
    last_sync_at: string | null;
    last_sync_status: string | null;
    last_sync_error: string | null;
};

type Agent = {
    cli_path: string;
    max_concurrent: number;
    timeout_minutes: number;
    heartbeat_minutes: number;
    monthly_budget_usd: number | null;
    monthly_spend_usd: number;
    active_runs: number;
    paused: boolean;
};

type Psychology = {
    enabled: boolean;
    wip_soft_limit: number;
    wip_hard_limit: number;
};

type Queue = { pending: number; failed: number };

type Props = { vault: Vault; agent: Agent; psychology: Psychology; queue: Queue };

export default function SettingsIndex({ vault, agent, psychology, queue }: Props) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-200">Settings</h2>}
        >
            <Head title="Settings" />

            <div className="space-y-6">
                <VaultSection vault={vault} />
                <AgentSection agent={agent} />
                <PsychologySection psychology={psychology} />
                <QueueSection queue={queue} />
            </div>
        </AuthenticatedLayout>
    );
}

function VaultSection({ vault }: { vault: Vault }) {
    const [syncing, setSyncing] = useState(false);

    const syncNow = () => {
        setSyncing(true);
        router.post(route('settings.sync_now'), {}, {
            preserveScroll: true,
            onFinish: () => setSyncing(false),
        });
    };

    return (
        <Card title="Vault" subtitle="Obsidian-Anbindung (über .env konfiguriert)">
            <Grid>
                <Field label="Pfad" value={vault.path ?? '— nicht gesetzt —'} mono />
                <Field label="Git Remote" value={vault.git_remote ?? '—'} mono />
                <Field label="Sync-Branch" value={vault.sync_branch ?? '—'} />
                <Field label="Notizen indiziert" value={vault.notes_count.toLocaleString()} />
                <Field label="Letzte Notiz-Änderung" value={fmtDate(vault.last_note_modified_at)} />
                <Field label="Letzter Sync" value={
                    <span className="flex items-center gap-2">
                        <Dot color={vault.last_sync_status === 'ok' ? 'green' : vault.last_sync_status === 'failed' ? 'red' : 'gray'} />
                        {fmtDate(vault.last_sync_at) ?? 'noch nie'}
                    </span>
                } />
            </Grid>
            {vault.last_sync_error && (
                <p className="mt-3 rounded-md border border-red-900/50 bg-red-950/30 px-3 py-2 text-xs text-red-300">
                    {vault.last_sync_error}
                </p>
            )}
            <div className="mt-4 flex gap-2">
                <button
                    onClick={syncNow}
                    disabled={syncing || !vault.configured}
                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    {syncing ? 'Synchronisiere…' : 'Jetzt synchronisieren'}
                </button>
            </div>
        </Card>
    );
}

function AgentSection({ agent }: { agent: Agent }) {
    const budget = agent.monthly_budget_usd;
    const pct = budget && budget > 0 ? Math.min(100, (agent.monthly_spend_usd / budget) * 100) : 0;
    const barColor = pct > 90 ? 'bg-red-500' : pct > 70 ? 'bg-yellow-500' : 'bg-emerald-500';

    return (
        <Card title="Agent" subtitle="Claude-CLI Ausführung">
            <Grid>
                <Field label="CLI-Pfad" value={agent.cli_path} mono />
                <Field label="Max. parallel" value={agent.max_concurrent} />
                <Field label="Timeout" value={`${agent.timeout_minutes} min`} />
                <Field label="Heartbeat" value={`alle ${agent.heartbeat_minutes} min`} />
                <Field label="Aktive Runs" value={agent.active_runs} />
                <Field label="Monats-Budget" value={budget !== null ? `$${budget.toFixed(2)}` : '— kein Limit —'} />
            </Grid>

            {budget !== null && (
                <div className="mt-4">
                    <div className="flex items-center justify-between text-xs text-gray-400">
                        <span>Verbrauch diesen Monat</span>
                        <span>${agent.monthly_spend_usd.toFixed(4)} / ${budget.toFixed(2)}</span>
                    </div>
                    <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-800">
                        <div className={`h-full ${barColor} transition-all`} style={{ width: `${pct}%` }} />
                    </div>
                </div>
            )}

            <ToggleRow
                label="Agent pausieren"
                description="Wenn aktiv, dispatcht der Heartbeat keine neuen Tickets mehr."
                checked={agent.paused}
                settingKey="agent.paused"
            />
        </Card>
    );
}

function PsychologySection({ psychology }: { psychology: Psychology }) {
    return (
        <Card title="Psychology" subtitle="Prokrastinations-Schutz, WIP-Limits">
            <ToggleRow
                label="Psychology Engine aktiv"
                description="Triggers, Interventions und Insights auf dem Dashboard."
                checked={psychology.enabled}
                settingKey="psychology.enabled"
            />

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <NumberSetting
                    label="WIP soft limit"
                    description="Ab hier kommen sanfte Hinweise."
                    value={psychology.wip_soft_limit}
                    settingKey="psychology.wip_soft_limit"
                    min={1}
                    max={20}
                />
                <NumberSetting
                    label="WIP hard limit"
                    description="Ab hier blockt der Chimp-Reframe."
                    value={psychology.wip_hard_limit}
                    settingKey="psychology.wip_hard_limit"
                    min={1}
                    max={20}
                />
            </div>
        </Card>
    );
}

function QueueSection({ queue }: { queue: Queue }) {
    const [retrying, setRetrying] = useState(false);

    const retry = () => {
        setRetrying(true);
        router.post(route('settings.retry_failed'), {}, {
            preserveScroll: true,
            onFinish: () => setRetrying(false),
        });
    };

    return (
        <Card title="Queue" subtitle="Laravel-Job-Worker">
            <Grid>
                <Field label="Pending" value={queue.pending} />
                <Field label="Failed" value={
                    <span className={queue.failed > 0 ? 'text-red-400' : 'text-gray-300'}>{queue.failed}</span>
                } />
            </Grid>
            {queue.failed > 0 && (
                <button
                    onClick={retry}
                    disabled={retrying}
                    className="mt-4 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    {retrying ? 'Versuche…' : `${queue.failed} fehlgeschlagene Jobs erneut versuchen`}
                </button>
            )}
        </Card>
    );
}

// ---------- shared bits ----------

function Card({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
            <h3 className="text-sm font-medium text-gray-200">{title}</h3>
            {subtitle && <p className="mt-1 text-xs text-gray-500">{subtitle}</p>}
            <div className="mt-4">{children}</div>
        </div>
    );
}

function Grid({ children }: { children: React.ReactNode }) {
    return <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{children}</div>;
}

function Field({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
    return (
        <div>
            <label className="block text-xs font-medium text-gray-400">{label}</label>
            <div className={`mt-1 text-sm text-gray-300 ${mono ? 'font-mono text-xs break-all' : ''}`}>{value}</div>
        </div>
    );
}

function Dot({ color }: { color: 'green' | 'red' | 'yellow' | 'gray' }) {
    const cls = { green: 'bg-emerald-500', red: 'bg-red-500', yellow: 'bg-yellow-400', gray: 'bg-gray-600' }[color];
    return <span className={`inline-block h-2 w-2 rounded-full ${cls}`} />;
}

function ToggleRow({
    label,
    description,
    checked,
    settingKey,
}: {
    label: string;
    description?: string;
    checked: boolean;
    settingKey: string;
}) {
    const [on, setOn] = useState(checked);
    const [saving, setSaving] = useState(false);

    const toggle = () => {
        const next = !on;
        setOn(next);
        setSaving(true);
        router.patch(
            route('settings.update', { key: settingKey }),
            { value: next },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
                onError: () => setOn(!next),
            }
        );
    };

    return (
        <div className="mt-4 flex items-start justify-between gap-4 rounded-md border border-gray-800 bg-gray-950/50 px-4 py-3">
            <div>
                <div className="text-sm text-gray-200">{label}</div>
                {description && <div className="mt-0.5 text-xs text-gray-500">{description}</div>}
            </div>
            <button
                type="button"
                onClick={toggle}
                disabled={saving}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors ${
                    on ? 'bg-indigo-600' : 'bg-gray-700'
                } disabled:opacity-50`}
                aria-pressed={on}
            >
                <span
                    className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                        on ? 'translate-x-6' : 'translate-x-1'
                    }`}
                />
            </button>
        </div>
    );
}

function NumberSetting({
    label,
    description,
    value,
    settingKey,
    min,
    max,
}: {
    label: string;
    description?: string;
    value: number;
    settingKey: string;
    min: number;
    max: number;
}) {
    const [val, setVal] = useState(value);
    const [saving, setSaving] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');

    const commit = (n: number) => {
        if (n < min || n > max || n === value) return;
        setSaving('saving');
        router.patch(
            route('settings.update', { key: settingKey }),
            { value: n },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSaving('saved');
                    setTimeout(() => setSaving('idle'), 1500);
                },
                onError: () => {
                    setSaving('error');
                    setVal(value);
                },
            }
        );
    };

    return (
        <div>
            <label className="block text-xs font-medium text-gray-400">{label}</label>
            {description && <div className="mt-0.5 text-xs text-gray-500">{description}</div>}
            <div className="mt-1.5 flex items-center gap-2">
                <input
                    type="number"
                    min={min}
                    max={max}
                    value={val}
                    onChange={(e) => setVal(Number(e.target.value))}
                    onBlur={() => commit(val)}
                    onKeyDown={(e) => { if (e.key === 'Enter') commit(val); }}
                    className="w-20 rounded-md border-gray-700 bg-gray-950 text-sm text-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                />
                <span className="text-xs text-gray-500">
                    {saving === 'saving' && '…'}
                    {saving === 'saved' && '✓'}
                    {saving === 'error' && '✗'}
                </span>
            </div>
        </div>
    );
}

function fmtDate(iso: string | null): string | null {
    if (!iso) return null;
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
