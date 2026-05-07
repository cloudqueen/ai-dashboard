import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { DragDropContext, Droppable, Draggable, DropResult } from '@hello-pangea/dnd';
import { useState, useEffect, useRef, useCallback } from 'react';

interface Ticket {
    id: number;
    title: string;
    description: string | null;
    type: string | null;
    status: string;
    priority: string | null;
    assigned_to: string | null;
    agent_skill: string | null;
    model: string | null;
    tags: string[] | null;
    due_date: string | null;
    emotional_charge: string | null;
    system_level: number | null;
    postpone_count: number;
    context_links: string[] | null;
    depends_on: number[] | null;
    routine_id: number | null;
    source_vault_note_id: number | null;
}

interface ContextLink {
    relative_path: string;
    title: string;
    vault_folder: string | null;
}

interface VaultSearchResult {
    id: number;
    relative_path: string;
    title: string;
    vault_folder: string;
}

interface TicketSearchResult {
    id: number;
    title: string;
    status: string;
}

interface DependencyTicket {
    id: number;
    title: string;
    status: string;
}

interface Column {
    key: string;
    label: string;
    tickets: Ticket[];
}

interface Props {
    columns: Record<string, Column>;
    filterOptions: { tags: string[]; assignees: string[] };
    filters: Record<string, string>;
}

type Lane = 'human' | 'agent';
type LaneFilters = { priority: string; tags: string[] };
const emptyFilters: LaneFilters = { priority: 'all', tags: [] };

const LANES: Lane[] = ['human', 'agent'];
const LANE_LABELS: Record<Lane, string> = { human: 'Du', agent: 'Agent' };

const priorityColors: Record<string, string> = {
    critical: 'border-l-red-500',
    high: 'border-l-orange-500',
    medium: 'border-l-blue-500',
    low: 'border-l-gray-600',
};

const statusOrder = ['backlog', 'todo', 'ready_for_agent', 'in_progress', 'review', 'done'];

const statusForLane = (lane: Lane): string[] =>
    lane === 'human' ? statusOrder.filter((s) => s !== 'ready_for_agent') : statusOrder;

function formatDateDe(dateStr: string): string {
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' });
    } catch { return dateStr; }
}

export default function KanbanIndex({ columns, filterOptions }: Props) {
    const [localColumns, setLocalColumns] = useState(columns);
    const [showNewTicket, setShowNewTicket] = useState(false);
    const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null);
    const [interventionToast, setInterventionToast] = useState<{ id: number; framework: string; intervention_type: string; content: string } | null>(null);
    const [humanFilters, setHumanFilters] = useState<LaneFilters>(emptyFilters);
    const [agentFilters, setAgentFilters] = useState<LaneFilters>(emptyFilters);
    const flash = usePage().props.flash as Record<string, unknown> | undefined;

    useEffect(() => {
        setLocalColumns(columns);
    }, [columns]);

    useEffect(() => {
        if (flash?.intervention) {
            setInterventionToast(flash.intervention as typeof interventionToast);
        }
    }, [flash]);

    const parseDroppable = (id: string): { status: string; lane: Lane } => {
        const idx = id.lastIndexOf('-');
        return { status: id.slice(0, idx), lane: id.slice(idx + 1) as Lane };
    };

    const handleDragEnd = (result: DropResult) => {
        const { source, destination, draggableId } = result;
        if (!destination) return;
        if (source.droppableId === destination.droppableId && source.index === destination.index) return;

        const dest = parseDroppable(destination.droppableId);

        // Find and remove ticket from its current status column (by id, since lane is rendering-only)
        const updated = { ...localColumns };
        let movedTicket: Ticket | null = null;
        for (const statusKey of Object.keys(updated)) {
            const col = updated[statusKey];
            const idx = col.tickets.findIndex((t) => String(t.id) === draggableId);
            if (idx >= 0) {
                const tickets = [...col.tickets];
                [movedTicket] = tickets.splice(idx, 1);
                updated[statusKey] = { ...col, tickets };
                break;
            }
        }
        if (!movedTicket) return;

        movedTicket = { ...movedTicket, status: dest.status, assigned_to: dest.lane };
        const destCol = updated[dest.status];
        updated[dest.status] = { ...destCol, tickets: [...destCol.tickets, movedTicket] };

        setLocalColumns(updated);

        router.patch(route('kanban.move'), {
            id: Number(draggableId),
            status: dest.status,
            assigned_to: dest.lane,
        }, {
            preserveState: true,
            preserveScroll: true,
            onError: () => setLocalColumns(columns),
        });
    };

    const filterTickets = (tickets: Ticket[], lane: Lane, f: LaneFilters): Ticket[] => {
        return tickets.filter((t) => {
            if (t.assigned_to !== lane) return false;
            if (f.priority !== 'all' && t.priority !== f.priority) return false;
            if (f.tags.length > 0) {
                const tt = t.tags ?? [];
                if (!f.tags.every((tag) => tt.includes(tag))) return false;
            }
            return true;
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-200">Kanban Board</h2>
                    <button
                        onClick={() => setShowNewTicket(true)}
                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500"
                    >
                        + New Ticket
                    </button>
                </div>
            }
        >
            <Head title="Kanban" />

            {showNewTicket && (
                <NewTicketForm onClose={() => setShowNewTicket(false)} />
            )}

            <DragDropContext onDragEnd={handleDragEnd}>
                <div className="overflow-x-auto pb-4">
                    <div className="min-w-fit space-y-6">
                        {LANES.map((lane) => {
                            const filters = lane === 'human' ? humanFilters : agentFilters;
                            const setFilters = lane === 'human' ? setHumanFilters : setAgentFilters;
                            const lanesStatuses = statusForLane(lane);
                            const totalCount = lanesStatuses.reduce(
                                (sum, s) => sum + filterTickets(localColumns[s]?.tickets ?? [], lane, filters).length,
                                0
                            );
                            return (
                                <div key={lane}>
                                    <LaneHeader
                                        lane={lane}
                                        count={totalCount}
                                        filters={filters}
                                        setFilters={setFilters}
                                        availableTags={filterOptions.tags}
                                    />
                                    <div className="flex gap-4">
                                        {statusOrder.map((statusKey) => {
                                            const col = localColumns[statusKey];
                                            if (!col) return null;
                                            if (!lanesStatuses.includes(statusKey)) {
                                                // Keep alignment with the other lane via an invisible spacer
                                                return <div key={`${statusKey}-${lane}-spacer`} className="w-72 flex-shrink-0" aria-hidden="true" />;
                                            }
                                            const laneTickets = filterTickets(col.tickets, lane, filters);
                                            return (
                                                <KanbanColumn
                                                    key={`${statusKey}-${lane}`}
                                                    column={col}
                                                    lane={lane}
                                                    laneTickets={laneTickets}
                                                    onCardClick={setSelectedTicket}
                                                />
                                            );
                                        })}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </DragDropContext>

            {selectedTicket && (
                <TicketDetailModal
                    ticket={selectedTicket}
                    onClose={() => setSelectedTicket(null)}
                />
            )}

            {interventionToast && (
                <div className="fixed bottom-6 right-6 z-50 max-w-md animate-slide-up">
                    <div className={`rounded-xl border p-5 shadow-2xl ${
                        interventionToast.framework === 'chimp'
                            ? 'border-amber-800/50 bg-gray-900'
                            : 'border-teal-800/50 bg-gray-900'
                    }`}>
                        <div className="mb-2 flex items-center justify-between">
                            <span className={`rounded-md px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ${
                                interventionToast.framework === 'chimp'
                                    ? 'bg-amber-900/30 text-amber-400'
                                    : 'bg-teal-900/30 text-teal-400'
                            }`}>
                                {interventionToast.framework === 'chimp' ? 'Chimp Paradox' : 'ZRM'}
                            </span>
                            <button onClick={() => setInterventionToast(null)} className="text-gray-600 hover:text-gray-400">
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div
                            className="text-sm leading-relaxed text-gray-300"
                            dangerouslySetInnerHTML={{
                                __html: interventionToast.content
                                    .replace(/\*\*(.+?)\*\*/g, '<strong class="text-gray-100">$1</strong>')
                                    .replace(/\n/g, '<br />')
                            }}
                        />
                        <div className="mt-3 flex items-center gap-2">
                            <button
                                onClick={() => {
                                    router.post(route('psych.feedback'), { id: interventionToast.id, was_helpful: true }, { preserveState: true, preserveScroll: true });
                                    setInterventionToast(null);
                                }}
                                className="rounded-md bg-gray-800 px-2.5 py-1 text-[10px] text-gray-400 hover:bg-gray-700 hover:text-green-400 transition-colors"
                            >
                                Helpful
                            </button>
                            <button
                                onClick={() => {
                                    router.post(route('psych.feedback'), { id: interventionToast.id, was_helpful: false }, { preserveState: true, preserveScroll: true });
                                    setInterventionToast(null);
                                }}
                                className="rounded-md bg-gray-800 px-2.5 py-1 text-[10px] text-gray-400 hover:bg-gray-700 hover:text-gray-300 transition-colors"
                            >
                                Not helpful
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function KanbanColumn({ column, lane, laneTickets, onCardClick }: {
    column: Column;
    lane: Lane;
    laneTickets: Ticket[];
    onCardClick: (t: Ticket) => void;
}) {
    return (
        <div className="flex w-72 flex-shrink-0 flex-col rounded-xl border border-gray-800 bg-gray-900">
            <div className="flex items-center justify-between border-b border-gray-800 px-4 py-3">
                <h3 className="text-sm font-medium text-gray-300">{column.label}</h3>
                <span className="rounded-full bg-gray-800 px-2 py-0.5 text-xs text-gray-500">
                    {laneTickets.length}
                </span>
            </div>
            <Droppable droppableId={`${column.key}-${lane}`}>
                {(provided, snapshot) => (
                    <div
                        ref={provided.innerRef}
                        {...provided.droppableProps}
                        className={`min-h-[80px] flex-1 space-y-2 p-3 transition-colors ${
                            snapshot.isDraggingOver ? 'bg-gray-800/30' : ''
                        }`}
                    >
                        {laneTickets.map((ticket, index) => (
                            <KanbanCard
                                key={ticket.id}
                                ticket={ticket}
                                index={index}
                                onClick={() => onCardClick(ticket)}
                            />
                        ))}
                        {provided.placeholder}
                        {laneTickets.length === 0 && !snapshot.isDraggingOver && (
                            <p className="py-4 text-center text-[10px] text-gray-700">—</p>
                        )}
                    </div>
                )}
            </Droppable>
        </div>
    );
}

function LaneHeader({ lane, count, filters, setFilters, availableTags }: {
    lane: Lane;
    count: number;
    filters: LaneFilters;
    setFilters: (f: LaneFilters) => void;
    availableTags: string[];
}) {
    const isHuman = lane === 'human';
    const accent = isHuman ? 'border-indigo-700/40 bg-indigo-900/10' : 'border-purple-800/40 bg-purple-900/10';
    const label = isHuman ? 'text-indigo-300' : 'text-purple-300';

    const toggleTag = (tag: string) => {
        const next = filters.tags.includes(tag)
            ? filters.tags.filter((t) => t !== tag)
            : [...filters.tags, tag];
        setFilters({ ...filters, tags: next });
    };

    const clear = () => setFilters(emptyFilters);
    const hasFilters = filters.priority !== 'all' || filters.tags.length > 0;

    return (
        <div className={`mb-3 flex flex-wrap items-center gap-3 rounded-lg border ${accent} px-3 py-2`}>
            <span className={`text-xs font-medium uppercase tracking-wider ${label}`}>
                {LANE_LABELS[lane]}
            </span>
            <span className="text-[10px] text-gray-500">{count} {count === 1 ? 'Ticket' : 'Tickets'}</span>

            <div className="flex items-center gap-1.5">
                <span className="text-[10px] text-gray-600">Prio:</span>
                <select
                    value={filters.priority}
                    onChange={(e) => setFilters({ ...filters, priority: e.target.value })}
                    className="rounded border-gray-700 bg-gray-950 py-0.5 pl-2 pr-7 text-[10px] text-gray-300 focus:border-indigo-500 focus:ring-0"
                >
                    <option value="all">alle</option>
                    <option value="critical">critical</option>
                    <option value="high">high</option>
                    <option value="medium">medium</option>
                    <option value="low">low</option>
                </select>
            </div>

            {availableTags.length > 0 && (
                <div className="flex flex-wrap items-center gap-1">
                    <span className="text-[10px] text-gray-600">Tags:</span>
                    {availableTags.slice(0, 12).map((tag) => {
                        const active = filters.tags.includes(tag);
                        return (
                            <button
                                key={tag}
                                onClick={() => toggleTag(tag)}
                                className={`rounded px-1.5 py-0.5 text-[10px] transition-colors ${
                                    active
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-gray-800 text-gray-400 hover:bg-gray-700'
                                }`}
                            >
                                {active ? '×' : '+'} {tag}
                            </button>
                        );
                    })}
                </div>
            )}

            {hasFilters && (
                <button
                    onClick={clear}
                    className="ml-auto text-[10px] text-gray-500 hover:text-gray-300"
                >
                    Reset
                </button>
            )}
        </div>
    );
}

function KanbanCard({ ticket, index, onClick }: { ticket: Ticket; index: number; onClick: () => void }) {
    const priorityClass = priorityColors[ticket.priority ?? 'medium'] ?? priorityColors.medium;
    const linkCount = ticket.context_links?.length ?? 0;

    return (
        <Draggable draggableId={String(ticket.id)} index={index}>
            {(provided, snapshot) => (
                <div
                    ref={provided.innerRef}
                    {...provided.draggableProps}
                    {...provided.dragHandleProps}
                    onClick={onClick}
                    className={`cursor-pointer rounded-lg border border-gray-700 border-l-4 ${priorityClass} bg-gray-800 p-3 transition-shadow ${
                        snapshot.isDragging ? 'shadow-lg shadow-black/50' : 'hover:border-gray-600'
                    }`}
                >
                    <h4 className="text-sm font-medium text-gray-200">{ticket.title}</h4>
                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                        {ticket.type && (
                            <span className="rounded bg-gray-700 px-1.5 py-0.5 text-[10px] text-gray-400">
                                {ticket.type}
                            </span>
                        )}
                        {ticket.assigned_to && (
                            <span className={`rounded px-1.5 py-0.5 text-[10px] ${
                                ticket.assigned_to === 'agent'
                                    ? 'bg-purple-900/50 text-purple-400'
                                    : 'bg-gray-700 text-gray-400'
                            }`}>
                                {ticket.assigned_to}
                            </span>
                        )}
                        {ticket.due_date && (
                            <span className="rounded bg-gray-700 px-1.5 py-0.5 text-[10px] text-gray-400">
                                {formatDateDe(ticket.due_date)}
                            </span>
                        )}
                        {linkCount > 0 && (
                            <span className="rounded bg-indigo-900/30 px-1.5 py-0.5 text-[10px] text-indigo-400">
                                {linkCount} linked
                            </span>
                        )}
                    </div>
                    {ticket.tags && Array.isArray(ticket.tags) && ticket.tags.length > 0 && (
                        <div className="mt-1.5 flex flex-wrap gap-1">
                            {ticket.tags.slice(0, 3).map((tag) => (
                                <span key={tag} className="text-[10px] text-gray-500">#{tag}</span>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </Draggable>
    );
}

// --- Ticket Detail Modal ---

const AVAILABLE_MODELS = [
    { value: '', label: 'Default' },
    { value: 'claude-opus-4-7', label: 'Opus 4.7' },
    { value: 'claude-sonnet-4-6', label: 'Sonnet 4.6' },
    { value: 'claude-haiku-4-5-20251001', label: 'Haiku 4.5' },
];

function TicketDetailModal({ ticket, onClose }: { ticket: Ticket; onClose: () => void }) {
    const [contextLinks, setContextLinks] = useState<ContextLink[]>([]);
    const [dependencies, setDependencies] = useState<DependencyTicket[]>([]);
    const [loading, setLoading] = useState(true);
    const [availableSkills, setAvailableSkills] = useState<{ value: string; label: string }[]>([{ value: '', label: 'Auto-detect' }]);
    const [model, setModel] = useState<string>(ticket.model ?? '');
    const [agentSkill, setAgentSkill] = useState<string>(ticket.agent_skill ?? '');
    const dependsOnIds = ticket.depends_on ?? [];

    const fetchTicketData = useCallback(() => {
        fetch(route('kanban.show', { id: ticket.id }), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => res.json())
            .then((data) => {
                setContextLinks(data.contextLinks ?? []);
                setDependencies(data.dependencies ?? []);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [ticket.id]);

    useEffect(() => {
        setLoading(true);
        fetchTicketData();
        fetch(route('skills.list'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((skills: { name: string; display_name: string; source: string }[]) => {
                setAvailableSkills([
                    { value: '', label: 'Auto-detect' },
                    ...skills.map((s) => ({ value: s.name, label: `${s.display_name}${s.source === 'file' ? ' (file)' : ''}` })),
                ]);
            })
            .catch(() => {});
    }, [fetchTicketData]);

    const handleAddLink = (notePath: string) => {
        router.post(route('kanban.link'), {
            ticket_id: ticket.id,
            link_path: notePath,
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: fetchTicketData,
        });
    };

    const handleRemoveLink = (notePath: string) => {
        router.delete(route('kanban.unlink'), {
            data: { ticket_id: ticket.id, link_path: notePath },
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setContextLinks((prev) => prev.filter((l) => l.relative_path !== notePath));
            },
        });
    };

    const handleMetaChange = (field: string, value: string | string[] | number[] | null) => {
        router.patch(route('kanban.meta'), {
            id: ticket.id,
            [field]: value,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleAddDependency = (dep: TicketSearchResult) => {
        const newDeps = [...dependsOnIds, dep.id];
        router.patch(route('kanban.meta'), {
            id: ticket.id,
            depends_on: newDeps,
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setDependencies((prev) => [...prev, { id: dep.id, title: dep.title, status: dep.status }]);
            },
        });
    };

    const handleRemoveDependency = (depId: number) => {
        const newDeps = dependsOnIds.filter((id) => id !== depId);
        router.patch(route('kanban.meta'), {
            id: ticket.id,
            depends_on: newDeps,
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setDependencies((prev) => prev.filter((d) => d.id !== depId));
            },
        });
    };

    const isAgentTicket = ticket.assigned_to === 'agent';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
            <div
                className="mx-4 max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-gray-700 bg-gray-900 shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-start justify-between border-b border-gray-800 p-6">
                    <div className="min-w-0 flex-1">
                        <h2 className="text-lg font-semibold text-gray-100">{ticket.title}</h2>
                        <p className="mt-1 text-xs text-gray-500">Ticket #{ticket.id}</p>
                    </div>
                    <button onClick={onClose} className="ml-4 text-gray-500 hover:text-gray-300">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="space-y-6 p-6">
                    <div className="flex flex-wrap gap-2">
                        {ticket.priority && (
                            <span className={`rounded-md px-2 py-1 text-xs font-medium ${
                                ticket.priority === 'critical' ? 'bg-red-900/50 text-red-400' :
                                ticket.priority === 'high' ? 'bg-orange-900/50 text-orange-400' :
                                ticket.priority === 'medium' ? 'bg-blue-900/50 text-blue-400' :
                                'bg-gray-800 text-gray-400'
                            }`}>
                                {ticket.priority}
                            </span>
                        )}
                        {ticket.type && (
                            <span className="rounded-md bg-gray-800 px-2 py-1 text-xs text-gray-400">{ticket.type}</span>
                        )}
                        {ticket.assigned_to && (
                            <span className={`rounded-md px-2 py-1 text-xs ${
                                ticket.assigned_to === 'agent' ? 'bg-purple-900/50 text-purple-400' : 'bg-gray-800 text-gray-400'
                            }`}>
                                {ticket.assigned_to}
                            </span>
                        )}
                        {ticket.due_date && (
                            <span className="rounded-md bg-gray-800 px-2 py-1 text-xs text-gray-400">{formatDateDe(ticket.due_date)}</span>
                        )}
                    </div>

                    {ticket.description && (
                        <p className="whitespace-pre-wrap text-sm text-gray-400">{ticket.description}</p>
                    )}

                    {isAgentTicket && (
                        <div className="rounded-lg border border-gray-800 bg-gray-800/30 p-4">
                            <h3 className="mb-3 text-sm font-medium text-purple-400">Agent Settings</h3>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-xs text-gray-500">Model</label>
                                    <select
                                        value={model}
                                        onChange={(e) => {
                                            setModel(e.target.value);
                                            handleMetaChange('model', e.target.value || null);
                                        }}
                                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                                    >
                                        {AVAILABLE_MODELS.map((m) => (
                                            <option key={m.value} value={m.value}>{m.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs text-gray-500">Skill</label>
                                    <select
                                        value={agentSkill}
                                        onChange={(e) => {
                                            setAgentSkill(e.target.value);
                                            handleMetaChange('agent_skill', e.target.value || null);
                                        }}
                                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                                    >
                                        {availableSkills.map((s) => (
                                            <option key={s.value} value={s.value}>{s.label}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                        </div>
                    )}

                    {isAgentTicket && (
                        <div>
                            <h3 className="mb-3 text-sm font-medium text-gray-300">
                                Depends On
                                <span className="ml-2 text-xs font-normal text-gray-500">
                                    Agent waits until these tickets are done
                                </span>
                            </h3>

                            {dependencies.length > 0 && (
                                <div className="mb-3 space-y-2">
                                    {dependencies.map((dep) => (
                                        <div
                                            key={dep.id}
                                            className="flex items-center justify-between rounded-lg border border-amber-900/30 bg-amber-900/10 px-3 py-2"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm text-gray-200">{dep.title}</p>
                                                <p className="text-[10px] text-gray-500">#{dep.id} · {dep.status}</p>
                                            </div>
                                            <button
                                                onClick={() => handleRemoveDependency(dep.id)}
                                                className="ml-3 flex-shrink-0 text-gray-500 hover:text-red-400"
                                            >
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <TicketSearch
                                onSelect={handleAddDependency}
                                excludeIds={[ticket.id, ...dependencies.map((d) => d.id)]}
                                placeholder="Search tickets to add dependency..."
                            />
                        </div>
                    )}

                    <div>
                        <h3 className="mb-3 text-sm font-medium text-gray-300">
                            Context Links
                            <span className="ml-2 text-xs font-normal text-gray-500">
                                Linked vault notes provide context for agent runs
                            </span>
                        </h3>

                        {loading ? (
                            <p className="text-xs text-gray-500">Loading...</p>
                        ) : (
                            <>
                                {contextLinks.length > 0 && (
                                    <div className="mb-3 space-y-2">
                                        {contextLinks.map((link) => (
                                            <div
                                                key={link.relative_path}
                                                className="flex items-center justify-between rounded-lg border border-gray-800 bg-gray-800/50 px-3 py-2"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm text-gray-200">{link.title}</p>
                                                    <p className="text-[10px] text-gray-500">{link.vault_folder ?? link.relative_path}</p>
                                                </div>
                                                <button
                                                    onClick={() => handleRemoveLink(link.relative_path)}
                                                    className="ml-3 flex-shrink-0 text-gray-500 hover:text-red-400"
                                                    title="Remove link"
                                                >
                                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <VaultLinkSearch
                                    onSelect={handleAddLink}
                                    excludePaths={contextLinks.map((l) => l.relative_path)}
                                />
                            </>
                        )}
                    </div>

                    <div className="border-t border-gray-800 pt-4">
                        <button
                            onClick={() => {
                                if (!confirm('Ticket wirklich löschen?')) return;
                                router.delete(route('kanban.destroy'), {
                                    data: { id: ticket.id },
                                    onSuccess: () => onClose(),
                                });
                            }}
                            className="rounded-lg bg-red-900/20 px-3 py-1.5 text-xs text-red-400 hover:bg-red-900/40 transition-colors"
                        >
                            Ticket löschen
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// --- Vault search for context linking ---

function VaultLinkSearch({ onSelect, excludePaths, placeholder }: { onSelect: (path: string) => void; excludePaths: string[]; placeholder?: string }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<VaultSearchResult[]>([]);
    const [showResults, setShowResults] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout>>();
    const wrapperRef = useRef<HTMLDivElement>(null);

    const search = useCallback((q: string) => {
        if (q.length < 2) {
            setResults([]);
            return;
        }

        fetch(route('vault.search') + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => res.json())
            .then((data) => {
                const filtered = data.filter(
                    (n: VaultSearchResult) => !excludePaths.includes(n.relative_path)
                );
                setResults(filtered.slice(0, 10));
            });
    }, [excludePaths]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value;
        setQuery(val);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => search(val), 250);
    };

    const handleSelect = (result: VaultSearchResult) => {
        onSelect(result.relative_path);
        setQuery('');
        setResults([]);
        setShowResults(false);
    };

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
                setShowResults(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    return (
        <div ref={wrapperRef} className="relative">
            <div className="relative">
                <svg className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <input
                    type="text"
                    value={query}
                    onChange={handleChange}
                    onFocus={() => setShowResults(true)}
                    placeholder={placeholder ?? "Search vault notes to link..."}
                    className="w-full rounded-lg border border-gray-700 bg-gray-800 py-2 pl-10 pr-4 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                />
            </div>

            {showResults && results.length > 0 && (
                <div className="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-gray-700 bg-gray-800 shadow-xl">
                    {results.map((result) => (
                        <button
                            key={result.relative_path}
                            onClick={() => handleSelect(result)}
                            className="flex w-full items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-gray-700"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm text-gray-200">{result.title}</p>
                                <p className="truncate text-[10px] text-gray-500">{result.vault_folder}</p>
                            </div>
                        </button>
                    ))}
                </div>
            )}

            {showResults && query.length >= 2 && results.length === 0 && (
                <div className="absolute z-10 mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 p-3 text-center shadow-xl">
                    <p className="text-xs text-gray-500">No matching notes found</p>
                </div>
            )}
        </div>
    );
}

// --- Ticket search for dependencies ---

function TicketSearch({ onSelect, excludeIds, placeholder }: { onSelect: (t: TicketSearchResult) => void; excludeIds: number[]; placeholder?: string }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<TicketSearchResult[]>([]);
    const [showResults, setShowResults] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout>>();
    const wrapperRef = useRef<HTMLDivElement>(null);

    const search = useCallback((q: string) => {
        if (q.length < 2) { setResults([]); return; }
        fetch(route('kanban.search') + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((data: TicketSearchResult[]) => {
                setResults(data.filter((t) => !excludeIds.includes(t.id)).slice(0, 10));
            });
    }, [excludeIds]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value;
        setQuery(val);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => search(val), 250);
    };

    const handleSelect = (result: TicketSearchResult) => {
        onSelect(result);
        setQuery('');
        setResults([]);
        setShowResults(false);
    };

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
                setShowResults(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    return (
        <div ref={wrapperRef} className="relative">
            <input
                type="text"
                value={query}
                onChange={handleChange}
                onFocus={() => setShowResults(true)}
                placeholder={placeholder ?? "Search tickets..."}
                className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
            />
            {showResults && results.length > 0 && (
                <div className="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-gray-700 bg-gray-800 shadow-xl">
                    {results.map((result) => (
                        <button
                            key={result.id}
                            onClick={() => handleSelect(result)}
                            className="flex w-full flex-col px-3 py-2 text-left transition-colors hover:bg-gray-700"
                        >
                            <span className="truncate text-sm text-gray-200">{result.title}</span>
                            <span className="text-[10px] text-gray-500">#{result.id} · {result.status}</span>
                        </button>
                    ))}
                </div>
            )}
            {showResults && query.length >= 2 && results.length === 0 && (
                <div className="absolute z-10 mt-1 w-full rounded-lg border border-gray-700 bg-gray-800 p-3 text-center shadow-xl">
                    <p className="text-xs text-gray-500">No matching tickets</p>
                </div>
            )}
        </div>
    );
}

// --- New Ticket Form ---

function NewTicketForm({ onClose }: { onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        type: 'task',
        priority: 'medium',
        status: 'backlog',
        assigned_to: 'human',
        description: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('kanban.store'), {
            onSuccess: () => onClose(),
        });
    };

    return (
        <div className="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-6">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-sm font-medium text-gray-200">New Ticket</h3>
                <button onClick={onClose} className="text-gray-500 hover:text-gray-300">
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form onSubmit={handleSubmit} className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <input
                        type="text"
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="Ticket title..."
                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                        autoFocus
                    />
                    {errors.title && <p className="mt-1 text-xs text-red-400">{errors.title}</p>}
                </div>
                <select
                    value={data.type}
                    onChange={(e) => setData('type', e.target.value)}
                    className="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                >
                    <option value="task">Task</option>
                    <option value="bug">Bug</option>
                    <option value="feature">Feature</option>
                    <option value="research">Research</option>
                    <option value="writing">Writing</option>
                </select>
                <select
                    value={data.priority}
                    onChange={(e) => setData('priority', e.target.value)}
                    className="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                >
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
                <select
                    value={data.assigned_to}
                    onChange={(e) => {
                        setData('assigned_to', e.target.value);
                        if (e.target.value === 'human' && data.status === 'ready_for_agent') {
                            setData('status', 'todo');
                        }
                    }}
                    className="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                >
                    <option value="human">Human</option>
                    <option value="agent">Agent</option>
                </select>
                <select
                    value={data.status}
                    onChange={(e) => setData('status', e.target.value)}
                    className="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
                >
                    <option value="backlog">Backlog</option>
                    <option value="todo">Todo</option>
                    {data.assigned_to === 'agent' && <option value="ready_for_agent">Ready for Agent</option>}
                    <option value="in_progress">In Progress</option>
                </select>
                <div className="sm:col-span-2">
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="Description (optional)..."
                        rows={3}
                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </div>
                <div className="sm:col-span-2 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg bg-gray-800 px-4 py-2 text-xs text-gray-300 hover:bg-gray-700"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-xs text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Create Ticket
                    </button>
                </div>
            </form>
        </div>
    );
}
