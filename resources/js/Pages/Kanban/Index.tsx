import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { DragDropContext, Droppable, Draggable, DropResult } from '@hello-pangea/dnd';
import { useState, useEffect, useRef, useCallback } from 'react';

interface Ticket {
    id: number;
    relative_path: string;
    title: string;
    type: string | null;
    status: string;
    priority: string | null;
    assigned_to: string | null;
    tags: string[] | null;
    due_date: string | null;
    body_preview: string | null;
    frontmatter: Record<string, unknown> | null;
}

interface ContextLink {
    relative_path: string;
    title: string;
    vault_folder: string;
}

interface VaultSearchResult {
    id: number;
    relative_path: string;
    title: string;
    vault_folder: string;
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
    vaultConfigured: boolean;
}

const priorityColors: Record<string, string> = {
    critical: 'border-l-red-500',
    high: 'border-l-orange-500',
    medium: 'border-l-blue-500',
    low: 'border-l-gray-600',
};

const statusOrder = ['backlog', 'todo', 'ready_for_agent', 'in_progress', 'review', 'done'];

function formatDateDe(dateStr: string): string {
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        const hasTime = dateStr.includes('T') || dateStr.includes(':');
        const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' };
        if (hasTime) { opts.hour = '2-digit'; opts.minute = '2-digit'; }
        return d.toLocaleDateString('de-DE', opts);
    } catch { return dateStr; }
}

export default function KanbanIndex({ columns, filterOptions, filters, vaultConfigured }: Props) {
    const [localColumns, setLocalColumns] = useState(columns);
    const [showNewTicket, setShowNewTicket] = useState(false);
    const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null);
    const [interventionToast, setInterventionToast] = useState<{ id: number; framework: string; intervention_type: string; content: string } | null>(null);
    const flash = usePage().props.flash as Record<string, unknown> | undefined;

    // Sync localColumns when server-side columns change (after link/unlink)
    useEffect(() => {
        setLocalColumns(columns);
    }, [columns]);

    // Show intervention toast from flash data
    useEffect(() => {
        if (flash?.intervention) {
            setInterventionToast(flash.intervention as typeof interventionToast);
        }
    }, [flash]);

    const handleDragEnd = (result: DropResult) => {
        const { source, destination, draggableId } = result;

        if (!destination || (source.droppableId === destination.droppableId && source.index === destination.index)) {
            return;
        }

        const newColumns = { ...localColumns };
        const sourceCol = { ...newColumns[source.droppableId] };
        const destCol = source.droppableId === destination.droppableId
            ? sourceCol
            : { ...newColumns[destination.droppableId] };

        const sourceTickets = [...sourceCol.tickets];
        const [movedTicket] = sourceTickets.splice(source.index, 1);
        movedTicket.status = destination.droppableId;

        if (source.droppableId === destination.droppableId) {
            sourceTickets.splice(destination.index, 0, movedTicket);
            sourceCol.tickets = sourceTickets;
            newColumns[source.droppableId] = sourceCol;
        } else {
            const destTickets = [...destCol.tickets];
            destTickets.splice(destination.index, 0, movedTicket);
            sourceCol.tickets = sourceTickets;
            destCol.tickets = destTickets;
            newColumns[source.droppableId] = sourceCol;
            newColumns[destination.droppableId] = destCol;
        }

        setLocalColumns(newColumns);

        router.patch(route('kanban.move'), {
            path: draggableId,
            status: destination.droppableId,
        }, {
            preserveState: true,
            preserveScroll: true,
            onError: () => setLocalColumns(columns),
        });
    };

    if (!vaultConfigured) {
        return (
            <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-200">Kanban Board</h2>}>
                <Head title="Kanban" />
                <div className="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
                    <p className="text-sm text-gray-500">Connect your vault in Settings to use the Kanban board.</p>
                </div>
            </AuthenticatedLayout>
        );
    }

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
                <div className="flex gap-4 overflow-x-auto pb-4">
                    {statusOrder.map((statusKey) => {
                        const col = localColumns[statusKey];
                        if (!col) return null;
                        return (
                            <KanbanColumn
                                key={statusKey}
                                column={col}
                                onCardClick={setSelectedTicket}
                            />
                        );
                    })}
                </div>
            </DragDropContext>

            {selectedTicket && (
                <TicketDetailModal
                    ticket={selectedTicket}
                    onClose={() => setSelectedTicket(null)}
                />
            )}

            {/* Psychology intervention toast */}
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
                            <button
                                onClick={() => setInterventionToast(null)}
                                className="text-gray-600 hover:text-gray-400"
                            >
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

function KanbanColumn({ column, onCardClick }: { column: Column; onCardClick: (t: Ticket) => void }) {
    return (
        <div className="flex w-72 flex-shrink-0 flex-col rounded-xl border border-gray-800 bg-gray-900">
            <div className="flex items-center justify-between border-b border-gray-800 px-4 py-3">
                <h3 className="text-sm font-medium text-gray-300">{column.label}</h3>
                <span className="rounded-full bg-gray-800 px-2 py-0.5 text-xs text-gray-500">
                    {column.tickets.length}
                </span>
            </div>
            <Droppable droppableId={column.key}>
                {(provided, snapshot) => (
                    <div
                        ref={provided.innerRef}
                        {...provided.droppableProps}
                        className={`min-h-[100px] flex-1 space-y-2 p-3 transition-colors ${
                            snapshot.isDraggingOver ? 'bg-gray-800/30' : ''
                        }`}
                    >
                        {column.tickets.map((ticket, index) => (
                            <KanbanCard
                                key={ticket.relative_path}
                                ticket={ticket}
                                index={index}
                                onClick={() => onCardClick(ticket)}
                            />
                        ))}
                        {provided.placeholder}
                        {column.tickets.length === 0 && !snapshot.isDraggingOver && (
                            <p className="py-6 text-center text-xs text-gray-600">No tickets</p>
                        )}
                    </div>
                )}
            </Droppable>
        </div>
    );
}

function KanbanCard({ ticket, index, onClick }: { ticket: Ticket; index: number; onClick: () => void }) {
    const priorityClass = priorityColors[ticket.priority ?? 'medium'] ?? priorityColors.medium;
    const contextLinks = (ticket.frontmatter?.context_links as string[] | undefined) ?? [];

    return (
        <Draggable draggableId={ticket.relative_path} index={index}>
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
                        {contextLinks.length > 0 && (
                            <span className="rounded bg-indigo-900/30 px-1.5 py-0.5 text-[10px] text-indigo-400">
                                {contextLinks.length} linked
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
    { value: 'claude-opus-4-6', label: 'Opus 4.6' },
    { value: 'claude-sonnet-4-6', label: 'Sonnet 4.6' },
    { value: 'claude-haiku-4-5-20251001', label: 'Haiku 4.5' },
];

function TicketDetailModal({ ticket, onClose }: { ticket: Ticket; onClose: () => void }) {
    const [contextLinks, setContextLinks] = useState<ContextLink[]>([]);
    const [dependencies, setDependencies] = useState<ContextLink[]>([]);
    const [loading, setLoading] = useState(true);
    const [availableSkills, setAvailableSkills] = useState<{ value: string; label: string }[]>([{ value: '', label: 'Auto-detect' }]);
    const [model, setModel] = useState<string>((ticket.frontmatter?.model as string) ?? '');
    const [agentSkill, setAgentSkill] = useState<string>((ticket.frontmatter?.agent_skill as string) ?? '');
    const dependsOnPaths = (ticket.frontmatter?.depends_on as string[]) ?? [];

    const fetchTicketData = useCallback(() => {
        fetch(route('kanban.show', { path: ticket.relative_path }), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => res.json())
            .then((data) => {
                setContextLinks(data.contextLinks ?? []);
                // Resolve dependency titles
                const deps = (data.ticket?.frontmatter?.depends_on ?? []) as string[];
                if (deps.length > 0) {
                    // Fetch dependency info from the ticket data we already have
                    Promise.all(
                        deps.map((p: string) =>
                            fetch(route('kanban.show', { path: p }), {
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            })
                                .then((r) => r.json())
                                .then((d) => ({
                                    relative_path: d.ticket.relative_path,
                                    title: d.ticket.title,
                                    vault_folder: d.ticket.vault_folder,
                                }))
                                .catch(() => null)
                        )
                    ).then((results) => setDependencies(results.filter(Boolean) as ContextLink[]));
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [ticket.relative_path]);

    useEffect(() => {
        setLoading(true);
        fetchTicketData();
        // Load available skills for dropdown
        fetch(route('skills.list'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((skills: { name: string; display_name: string; source: string }[]) => {
                setAvailableSkills([
                    { value: '', label: 'Auto-detect' },
                    ...skills.map((s) => ({ value: s.name, label: `${s.display_name}${s.source === 'vault' ? ' (vault)' : ''}` })),
                ]);
            })
            .catch(() => {});
    }, [fetchTicketData]);

    const handleAddLink = (notePath: string) => {
        router.post(route('kanban.link'), {
            ticket_path: ticket.relative_path,
            link_path: notePath,
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: fetchTicketData,
        });
    };

    const handleRemoveLink = (notePath: string) => {
        router.delete(route('kanban.unlink'), {
            data: {
                ticket_path: ticket.relative_path,
                link_path: notePath,
            },
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setContextLinks((prev) => prev.filter((l) => l.relative_path !== notePath));
            },
        });
    };

    const handleMetaChange = (field: string, value: string | string[]) => {
        router.patch(route('kanban.meta'), {
            path: ticket.relative_path,
            [field]: value || null,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleAddDependency = (notePath: string) => {
        const newDeps = [...dependsOnPaths, notePath];
        router.patch(route('kanban.meta'), {
            path: ticket.relative_path,
            depends_on: newDeps,
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                // Fetch the new dep's info
                fetch(route('kanban.show', { path: notePath }), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then((r) => r.json())
                    .then((d) => {
                        setDependencies((prev) => [...prev, {
                            relative_path: d.ticket.relative_path,
                            title: d.ticket.title,
                            vault_folder: d.ticket.vault_folder,
                        }]);
                    });
            },
        });
    };

    const handleRemoveDependency = (notePath: string) => {
        const newDeps = dependsOnPaths.filter((p) => p !== notePath);
        router.patch(route('kanban.meta'), {
            path: ticket.relative_path,
            depends_on: newDeps,
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setDependencies((prev) => prev.filter((d) => d.relative_path !== notePath));
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
                {/* Header */}
                <div className="flex items-start justify-between border-b border-gray-800 p-6">
                    <div className="min-w-0 flex-1">
                        <h2 className="text-lg font-semibold text-gray-100">{ticket.title}</h2>
                        <p className="mt-1 text-xs text-gray-500">{ticket.relative_path}</p>
                    </div>
                    <button onClick={onClose} className="ml-4 text-gray-500 hover:text-gray-300">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Body */}
                <div className="space-y-6 p-6">
                    {/* Ticket metadata badges */}
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

                    {ticket.body_preview && (
                        <p className="text-sm text-gray-400">{ticket.body_preview}</p>
                    )}

                    {/* Agent settings */}
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
                                            handleMetaChange('model', e.target.value);
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
                                            handleMetaChange('agent_skill', e.target.value);
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

                    {/* Dependencies */}
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
                                            key={dep.relative_path}
                                            className="flex items-center justify-between rounded-lg border border-amber-900/30 bg-amber-900/10 px-3 py-2"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm text-gray-200">{dep.title}</p>
                                                <p className="text-[10px] text-gray-500">{dep.relative_path}</p>
                                            </div>
                                            <button
                                                onClick={() => handleRemoveDependency(dep.relative_path)}
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

                            <VaultLinkSearch
                                onSelect={handleAddDependency}
                                excludePaths={[
                                    ticket.relative_path,
                                    ...dependencies.map((d) => d.relative_path),
                                ]}
                                placeholder="Search tickets to add dependency..."
                            />
                        </div>
                    )}

                    {/* Context Links */}
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
                                                    <p className="text-[10px] text-gray-500">{link.vault_folder}</p>
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
                                    excludePaths={[
                                        ticket.relative_path,
                                        ...contextLinks.map((l) => l.relative_path),
                                    ]}
                                />
                            </>
                        )}
                    </div>

                    {/* Delete */}
                    <div className="border-t border-gray-800 pt-4">
                        <button
                            onClick={() => {
                                if (!confirm('Ticket wirklich löschen?')) return;
                                router.delete(route('kanban.destroy'), {
                                    data: { path: ticket.relative_path },
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

// --- Vault search for linking ---

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

    // Close on click outside
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
                            <svg className="h-4 w-4 flex-shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm text-gray-200">{result.title}</p>
                                <p className="truncate text-[10px] text-gray-500">{result.vault_folder}/{result.title}</p>
                            </div>
                            <svg className="h-4 w-4 flex-shrink-0 text-indigo-400" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
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

// --- New Ticket Form ---

function NewTicketForm({ onClose }: { onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        type: 'task',
        priority: 'medium',
        status: 'backlog',
        assigned_to: 'human',
        description: '',
        folder: 'inbox',
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
                    onChange={(e) => setData('assigned_to', e.target.value)}
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
                    <option value="ready_for_agent">Ready for Agent</option>
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
