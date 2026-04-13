import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { DragDropContext, Droppable, Draggable, DropResult } from '@hello-pangea/dnd';
import { useState } from 'react';

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

export default function KanbanIndex({ columns, filterOptions, filters, vaultConfigured }: Props) {
    const [localColumns, setLocalColumns] = useState(columns);
    const [showNewTicket, setShowNewTicket] = useState(false);

    const handleDragEnd = (result: DropResult) => {
        const { source, destination, draggableId } = result;

        if (!destination || (source.droppableId === destination.droppableId && source.index === destination.index)) {
            return;
        }

        // Optimistic update
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

        // Persist
        router.patch(route('kanban.move'), {
            path: draggableId,
            status: destination.droppableId,
        }, {
            preserveState: true,
            preserveScroll: true,
            onError: () => setLocalColumns(columns), // revert on error
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
                            <KanbanColumn key={statusKey} column={col} />
                        );
                    })}
                </div>
            </DragDropContext>
        </AuthenticatedLayout>
    );
}

function KanbanColumn({ column }: { column: Column }) {
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
                            <KanbanCard key={ticket.relative_path} ticket={ticket} index={index} />
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

function KanbanCard({ ticket, index }: { ticket: Ticket; index: number }) {
    const priorityClass = priorityColors[ticket.priority ?? 'medium'] ?? priorityColors.medium;

    return (
        <Draggable draggableId={ticket.relative_path} index={index}>
            {(provided, snapshot) => (
                <div
                    ref={provided.innerRef}
                    {...provided.draggableProps}
                    {...provided.dragHandleProps}
                    className={`rounded-lg border border-gray-700 border-l-4 ${priorityClass} bg-gray-800 p-3 transition-shadow ${
                        snapshot.isDragging ? 'shadow-lg shadow-black/50' : ''
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
                                {ticket.due_date}
                            </span>
                        )}
                    </div>
                    {ticket.tags && ticket.tags.length > 0 && (
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
