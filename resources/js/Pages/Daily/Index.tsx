import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';

interface TicketSuggestion {
    title: string;
    type: string;
    priority: string;
    emotional_charge: string;
    due: string | null;
    created?: boolean;
}

interface Message {
    role: 'user' | 'assistant';
    content: string;
    at: string;
    options?: string[];
    tickets?: TicketSuggestion[];
}

interface Checkin {
    id: number;
    date: string;
    mood: string | null;
    energy: number | null;
    messages: Message[];
    plan: { task: string; priority: string }[] | null;
    motto_goal: string | null;
    summary: string | null;
    vault_note_path: string | null;
    completed_at: string | null;
}

interface Props {
    checkin: Checkin;
}

const MOODS = [
    { value: 'great', emoji: '😊', label: 'Super' },
    { value: 'good', emoji: '🙂', label: 'Gut' },
    { value: 'okay', emoji: '😐', label: 'Geht so' },
    { value: 'tired', emoji: '😴', label: 'Müde' },
    { value: 'stressed', emoji: '😤', label: 'Gestresst' },
    { value: 'anxious', emoji: '😰', label: 'Unruhig' },
    { value: 'bad', emoji: '😞', label: 'Schlecht' },
    { value: 'motivated', emoji: '🔥', label: 'Motiviert' },
];

function getQuickReplies(checkin: Checkin): string[] {
    // Get options from the last assistant message
    const msgs = checkin.messages;
    for (let i = msgs.length - 1; i >= 0; i--) {
        if (msgs[i].role === 'assistant' && msgs[i].options && msgs[i].options!.length > 0) {
            return msgs[i].options!;
        }
    }
    return [];
}

export default function DailyIndex({ checkin: initialCheckin }: Props) {
    const [checkin, setCheckin] = useState(initialCheckin);
    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const [finishing, setFinishing] = useState(false);
    const [selectedEnergy, setSelectedEnergy] = useState(3);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);

    const isCompleted = checkin.completed_at !== null;
    const needsMood = !checkin.mood && !isCompleted;
    const quickReplies = !needsMood && !isCompleted && !sending ? getQuickReplies(checkin) : [];

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [checkin.messages]);

    useEffect(() => {
        if (!sending && !isCompleted && !needsMood) {
            inputRef.current?.focus();
        }
    }, [sending, isCompleted, needsMood]);

    const csrfHeaders = () => ({
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    });

    const submitMood = async (mood: string) => {
        setSending(true);

        const moodObj = MOODS.find((m) => m.value === mood);
        const label = moodObj ? `${moodObj.emoji} ${moodObj.label}` : mood;

        // Optimistic
        setCheckin((prev) => ({
            ...prev,
            mood,
            energy: selectedEnergy,
            messages: [
                ...prev.messages,
                { role: 'user' as const, content: `Stimmung: ${label}, Energie: ${selectedEnergy}/5`, at: new Date().toISOString() },
            ],
        }));

        try {
            const res = await fetch(route('daily.mood'), {
                method: 'POST',
                headers: csrfHeaders(),
                body: JSON.stringify({ mood, energy: selectedEnergy }),
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            if (data.checkin) setCheckin(data.checkin);
        } catch (err) {
            console.error('Mood submit failed:', err);
        } finally {
            setSending(false);
        }
    };

    const sendMessage = async (text?: string) => {
        const msg = (text ?? input).trim();
        if (!msg || sending || isCompleted) return;

        // Add user message + empty assistant placeholder
        setCheckin((prev) => ({
            ...prev,
            messages: [
                ...prev.messages,
                { role: 'user' as const, content: msg, at: new Date().toISOString() },
                { role: 'assistant' as const, content: '', at: new Date().toISOString() },
            ],
        }));
        setInput('');
        setSending(true);

        try {
            const res = await fetch(route('daily.stream'), {
                method: 'POST',
                headers: csrfHeaders(),
                body: JSON.stringify({ message: msg }),
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const reader = res.body?.getReader();
            const decoder = new TextDecoder();

            if (!reader) throw new Error('No reader');

            let buffer = '';
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() ?? '';

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    const json = line.slice(6);
                    try {
                        const event = JSON.parse(json);

                        if (event.type === 'text') {
                            // Append chunk to last assistant message
                            setCheckin((prev) => {
                                const msgs = [...prev.messages];
                                const last = msgs[msgs.length - 1];
                                if (last?.role === 'assistant') {
                                    msgs[msgs.length - 1] = { ...last, content: last.content + event.content };
                                }
                                return { ...prev, messages: msgs };
                            });
                        }

                        if (event.type === 'done' && event.checkin) {
                            setCheckin(event.checkin);
                        }
                    } catch { /* skip malformed */ }
                }
            }
        } catch (err) {
            console.error('Daily stream failed:', err);
            // Fallback to sync endpoint
            try {
                const res = await fetch(route('daily.message'), {
                    method: 'POST',
                    headers: csrfHeaders(),
                    body: JSON.stringify({ message: msg }),
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.checkin) setCheckin(data.checkin);
                }
            } catch {
                // Remove both optimistic messages
                setCheckin((prev) => ({
                    ...prev,
                    messages: prev.messages.slice(0, -2),
                }));
            }
        } finally {
            setSending(false);
        }
    };

    const finishCheckin = async () => {
        if (finishing || isCompleted) return;
        setFinishing(true);

        try {
            const res = await fetch(route('daily.finish'), {
                method: 'POST',
                headers: csrfHeaders(),
            });

            const data = await res.json();
            if (data.checkin) setCheckin(data.checkin);
        } finally {
            setFinishing(false);
        }
    };

    const createTicket = async (ticket: TicketSuggestion) => {
        try {
            const res = await fetch(route('daily.ticket'), {
                method: 'POST',
                headers: csrfHeaders(),
                body: JSON.stringify(ticket),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
        } catch (err) {
            console.error('Ticket creation failed:', err);
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-xl font-semibold leading-tight text-gray-200">Daily Check-in</h2>
                        <p className="text-xs text-gray-500 mt-0.5">
                            {new Date(checkin.date).toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        {checkin.mood && (
                            <span className="rounded-md bg-gray-800 px-2 py-1 text-xs text-gray-400">
                                {MOODS.find((m) => m.value === checkin.mood)?.emoji} {MOODS.find((m) => m.value === checkin.mood)?.label ?? checkin.mood}
                            </span>
                        )}
                        {checkin.energy && <EnergyIndicator level={checkin.energy} />}
                        {isCompleted && checkin.vault_note_path && (
                            <Link
                                href={route('vault.show', { path: checkin.vault_note_path })}
                                className="rounded-lg bg-gray-800 px-3 py-1.5 text-xs text-indigo-400 hover:bg-gray-700"
                            >
                                Notiz anzeigen
                            </Link>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Daily Check-in" />

            <div className="mx-auto flex h-[calc(100vh-10rem)] max-w-3xl flex-col">
                {/* Motto goal banner */}
                {checkin.motto_goal && (
                    <div className="mb-4 rounded-xl border border-teal-800/50 bg-teal-900/10 px-4 py-3 text-center">
                        <p className="text-xs text-teal-400 uppercase tracking-wider mb-1">Motto-Ziel</p>
                        <p className="text-sm text-gray-200 italic">{checkin.motto_goal}</p>
                    </div>
                )}

                {/* Messages area */}
                <div className="flex-1 overflow-y-auto rounded-xl border border-gray-800 bg-gray-900 p-4">
                    <div className="space-y-4">
                        {checkin.messages.map((msg, i) => (
                            <ChatMessage key={i} message={msg} onCreateTicket={createTicket} />
                        ))}

                        {sending && (
                            <div className="flex justify-start">
                                <div className="max-w-[80%] rounded-2xl rounded-bl-sm bg-gray-800 px-4 py-3">
                                    <div className="flex gap-1">
                                        <span className="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style={{ animationDelay: '0ms' }} />
                                        <span className="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style={{ animationDelay: '150ms' }} />
                                        <span className="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style={{ animationDelay: '300ms' }} />
                                    </div>
                                </div>
                            </div>
                        )}

                        <div ref={messagesEndRef} />
                    </div>
                </div>

                {/* Mood picker */}
                {needsMood && !sending && (
                    <div className="mt-4 rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <p className="text-sm text-gray-300 mb-3">Wie fühlst du dich heute?</p>
                        <div className="grid grid-cols-4 gap-2 mb-4">
                            {MOODS.map((mood) => (
                                <button
                                    key={mood.value}
                                    onClick={() => submitMood(mood.value)}
                                    className="flex flex-col items-center gap-1 rounded-xl border border-gray-700 bg-gray-800 px-3 py-3 transition-all hover:border-indigo-500 hover:bg-gray-700 active:scale-95"
                                >
                                    <span className="text-2xl">{mood.emoji}</span>
                                    <span className="text-[10px] text-gray-400">{mood.label}</span>
                                </button>
                            ))}
                        </div>
                        <div>
                            <p className="text-xs text-gray-500 mb-2">Energie-Level</p>
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-gray-500">Leer</span>
                                <div className="flex flex-1 justify-center gap-1.5">
                                    {[1, 2, 3, 4, 5].map((level) => (
                                        <button
                                            key={level}
                                            onClick={() => setSelectedEnergy(level)}
                                            className={`h-8 w-8 rounded-full border-2 text-sm font-medium transition-all ${
                                                selectedEnergy >= level
                                                    ? level >= 4 ? 'border-green-500 bg-green-500/20 text-green-400'
                                                    : level >= 2 ? 'border-amber-500 bg-amber-500/20 text-amber-400'
                                                    : 'border-red-500 bg-red-500/20 text-red-400'
                                                    : 'border-gray-700 text-gray-600'
                                            }`}
                                        >
                                            {level}
                                        </button>
                                    ))}
                                </div>
                                <span className="text-xs text-gray-500">Voll</span>
                            </div>
                        </div>
                    </div>
                )}

                {/* Completed state */}
                {isCompleted && (
                    <div className="mt-4 rounded-xl border border-green-800/50 bg-green-900/10 p-4 text-center">
                        <p className="text-sm text-green-400">Check-in abgeschlossen</p>
                        {checkin.vault_note_path && (
                            <p className="mt-1 text-xs text-gray-500">
                                Gespeichert als {checkin.vault_note_path}
                            </p>
                        )}
                    </div>
                )}

                {/* Plan summary if completed */}
                {isCompleted && checkin.plan && checkin.plan.length > 0 && (
                    <div className="mt-3 rounded-xl border border-gray-800 bg-gray-900 p-4">
                        <h3 className="mb-2 text-xs font-medium text-gray-400 uppercase tracking-wider">Tagesplan</h3>
                        <div className="space-y-1.5">
                            {checkin.plan.map((item, i) => (
                                <div key={i} className="flex items-center gap-2 text-sm">
                                    <span className={`h-2 w-2 rounded-full flex-shrink-0 ${
                                        item.priority === 'high' ? 'bg-red-400' :
                                        item.priority === 'medium' ? 'bg-amber-400' : 'bg-gray-500'
                                    }`} />
                                    <span className="text-gray-300">{item.task}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Quick replies + Input area */}
                {!isCompleted && !needsMood && (
                    <div className="mt-4 space-y-3">
                        {/* Quick reply buttons */}
                        {quickReplies.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                {quickReplies.map((reply) => (
                                    <button
                                        key={reply}
                                        onClick={() => sendMessage(reply)}
                                        disabled={sending}
                                        className="rounded-full border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs text-gray-300 transition-all hover:border-indigo-500 hover:text-indigo-400 disabled:opacity-30 active:scale-95"
                                    >
                                        {reply}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Text input + finish button */}
                        <div className="flex gap-3">
                            <div className="relative flex-1">
                                <textarea
                                    ref={inputRef}
                                    value={input}
                                    onChange={(e) => setInput(e.target.value)}
                                    onKeyDown={handleKeyDown}
                                    placeholder="Oder schreib etwas..."
                                    rows={1}
                                    disabled={sending}
                                    className="w-full resize-none rounded-xl border border-gray-700 bg-gray-800 px-4 py-3 pr-12 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none disabled:opacity-50"
                                    style={{ minHeight: '48px', maxHeight: '120px' }}
                                    onInput={(e) => {
                                        const target = e.target as HTMLTextAreaElement;
                                        target.style.height = 'auto';
                                        target.style.height = Math.min(target.scrollHeight, 120) + 'px';
                                    }}
                                />
                                <button
                                    onClick={() => sendMessage()}
                                    disabled={!input.trim() || sending}
                                    className="absolute bottom-2 right-2 rounded-lg bg-indigo-600 p-1.5 text-white hover:bg-indigo-500 disabled:opacity-30 disabled:hover:bg-indigo-600"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                    </svg>
                                </button>
                            </div>
                            <button
                                onClick={finishCheckin}
                                disabled={finishing || checkin.messages.length < 4}
                                className="flex-shrink-0 rounded-xl bg-green-700 px-4 py-3 text-xs font-medium text-white hover:bg-green-600 disabled:opacity-30 disabled:hover:bg-green-700"
                                title={checkin.messages.length < 4 ? 'Führe erst ein kurzes Gespräch' : 'Check-in abschließen'}
                            >
                                {finishing ? (
                                    <span className="flex items-center gap-2">
                                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                        </svg>
                                        Speichern...
                                    </span>
                                ) : (
                                    'Abschließen'
                                )}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function ChatMessage({ message, onCreateTicket }: { message: Message; onCreateTicket?: (ticket: TicketSuggestion) => void }) {
    const isUser = message.role === 'user';

    return (
        <div className={`flex flex-col ${isUser ? 'items-end' : 'items-start'}`}>
            <div
                className={`max-w-[80%] rounded-2xl px-4 py-3 ${
                    isUser
                        ? 'rounded-br-sm bg-indigo-600 text-white'
                        : 'rounded-bl-sm bg-gray-800 text-gray-200'
                }`}
            >
                {!isUser && (
                    <p className="mb-1 text-[10px] font-medium text-teal-400 uppercase tracking-wider">Coach</p>
                )}
                <div
                    className="text-sm leading-relaxed whitespace-pre-wrap"
                    dangerouslySetInnerHTML={{
                        __html: message.content
                            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                            .replace(/\n/g, '<br />')
                    }}
                />
                <p className={`mt-1 text-[10px] ${isUser ? 'text-indigo-300' : 'text-gray-600'}`}>
                    {new Date(message.at).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}
                </p>
            </div>

            {/* Ticket suggestions */}
            {message.tickets && message.tickets.length > 0 && (
                <div className="mt-2 max-w-[80%] space-y-2">
                    {message.tickets.map((ticket, i) => (
                        <TicketCard key={i} ticket={ticket} onCreate={onCreateTicket} />
                    ))}
                </div>
            )}
        </div>
    );
}

const priorityColors: Record<string, string> = {
    critical: 'border-red-500 text-red-400',
    high: 'border-orange-500 text-orange-400',
    medium: 'border-blue-500 text-blue-400',
    low: 'border-gray-600 text-gray-400',
};

const chargeLabels: Record<string, string> = {
    high: '🔴 Hohe Ladung',
    medium: '🟡 Mittlere Ladung',
    low: '',
};

// Track which tickets have been created (by title) to prevent duplicates across re-renders
const createdTicketTitles = new Set<string>();

function TicketCard({ ticket, onCreate }: { ticket: TicketSuggestion; onCreate?: (t: TicketSuggestion) => void }) {
    const alreadyCreated = ticket.created || createdTicketTitles.has(ticket.title);
    const [created, setCreated] = useState(alreadyCreated);
    const [creating, setCreating] = useState(false);
    const pColor = priorityColors[ticket.priority] ?? priorityColors.medium;

    const handleCreate = async () => {
        if (created || creating || !onCreate || createdTicketTitles.has(ticket.title)) return;
        setCreating(true);
        createdTicketTitles.add(ticket.title);
        await onCreate(ticket);
        setCreated(true);
        setCreating(false);
    };

    const formatDue = (due: string | null) => {
        if (!due) return null;
        try {
            const d = new Date(due);
            return d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' })
                + (due.includes(':') ? ', ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '');
        } catch { return due; }
    };

    return (
        <div className={`flex items-center gap-3 rounded-xl border-l-4 ${pColor.split(' ')[0]} border border-gray-700 bg-gray-800/80 px-4 py-3`}>
            <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-200 truncate">{ticket.title}</p>
                <div className="mt-1 flex items-center gap-2">
                    <span className="text-[10px] text-gray-500">{ticket.type}</span>
                    <span className={`text-[10px] ${pColor.split(' ')[1]}`}>{ticket.priority}</span>
                    {chargeLabels[ticket.emotional_charge] && (
                        <span className="text-[10px] text-gray-500">{chargeLabels[ticket.emotional_charge]}</span>
                    )}
                    {ticket.due && (
                        <span className="text-[10px] text-gray-500">Fällig: {formatDue(ticket.due)}</span>
                    )}
                </div>
            </div>
            {created ? (
                <span className="flex-shrink-0 rounded-lg bg-green-900/30 px-3 py-1.5 text-[10px] text-green-400">
                    Angelegt
                </span>
            ) : (
                <button
                    onClick={handleCreate}
                    disabled={creating}
                    className="flex-shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs text-white hover:bg-indigo-500 disabled:opacity-50 active:scale-95 transition-all"
                >
                    {creating ? '...' : 'Ticket anlegen'}
                </button>
            )}
        </div>
    );
}

function EnergyIndicator({ level }: { level: number }) {
    return (
        <div className="flex items-center gap-1">
            <span className="text-[10px] text-gray-500 mr-1">Energie</span>
            {[1, 2, 3, 4, 5].map((i) => (
                <div
                    key={i}
                    className={`h-2 w-2 rounded-full ${
                        i <= level
                            ? level >= 4 ? 'bg-green-400' : level >= 2 ? 'bg-amber-400' : 'bg-red-400'
                            : 'bg-gray-700'
                    }`}
                />
            ))}
        </div>
    );
}
