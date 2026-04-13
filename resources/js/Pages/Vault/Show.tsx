import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    note: {
        id: number;
        relative_path: string;
        title: string;
        vault_folder: string;
        type: string | null;
        status: string | null;
        priority: string | null;
        tags: string[] | null;
        due_date: string | null;
    };
    content: string;
    frontmatter: Record<string, unknown>;
    wikilinks: { target: string; display: string }[];
}

export default function VaultShow({ note, content, frontmatter, wikilinks }: Props) {
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState(content);

    const handleSave = () => {
        router.patch(route('vault.update'), {
            path: note.relative_path,
            frontmatter: JSON.stringify(frontmatter),
            body,
        } as Record<string, string>, {
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link href={route('vault')} className="text-gray-500 hover:text-gray-300">
                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                            </svg>
                        </Link>
                        <h2 className="text-xl font-semibold leading-tight text-gray-200">
                            {note.title}
                        </h2>
                    </div>
                    <div className="flex items-center gap-2">
                        {!editing ? (
                            <button
                                onClick={() => setEditing(true)}
                                className="rounded-lg bg-gray-800 px-3 py-1.5 text-xs text-gray-300 hover:bg-gray-700"
                            >
                                Edit
                            </button>
                        ) : (
                            <>
                                <button
                                    onClick={() => { setEditing(false); setBody(content); }}
                                    className="rounded-lg bg-gray-800 px-3 py-1.5 text-xs text-gray-300 hover:bg-gray-700"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={handleSave}
                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs text-white hover:bg-indigo-500"
                                >
                                    Save
                                </button>
                            </>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={note.title} />

            <div className="grid gap-6 lg:grid-cols-4">
                {/* Main content */}
                <div className="lg:col-span-3">
                    <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
                        {editing ? (
                            <textarea
                                value={body}
                                onChange={(e) => setBody(e.target.value)}
                                className="min-h-[500px] w-full rounded-lg border border-gray-700 bg-gray-800 p-4 font-mono text-sm text-gray-200 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            />
                        ) : (
                            <div className="prose prose-invert max-w-none text-sm">
                                <pre className="whitespace-pre-wrap font-sans text-gray-300">{content}</pre>
                            </div>
                        )}
                    </div>
                </div>

                {/* Sidebar */}
                <div className="space-y-4">
                    {/* Metadata */}
                    <div className="rounded-xl border border-gray-800 bg-gray-900 p-4">
                        <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Properties</h3>
                        <dl className="space-y-2 text-sm">
                            <div>
                                <dt className="text-xs text-gray-500">Path</dt>
                                <dd className="text-gray-400 break-all">{note.relative_path}</dd>
                            </div>
                            {note.type && (
                                <div>
                                    <dt className="text-xs text-gray-500">Type</dt>
                                    <dd><span className="rounded bg-gray-800 px-2 py-0.5 text-xs text-gray-300">{note.type}</span></dd>
                                </div>
                            )}
                            {note.status && (
                                <div>
                                    <dt className="text-xs text-gray-500">Status</dt>
                                    <dd><span className="rounded bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">{note.status}</span></dd>
                                </div>
                            )}
                            {note.priority && (
                                <div>
                                    <dt className="text-xs text-gray-500">Priority</dt>
                                    <dd><span className="rounded bg-gray-800 px-2 py-0.5 text-xs text-gray-300">{note.priority}</span></dd>
                                </div>
                            )}
                            {note.due_date && (
                                <div>
                                    <dt className="text-xs text-gray-500">Due Date</dt>
                                    <dd className="text-gray-400">{note.due_date}</dd>
                                </div>
                            )}
                        </dl>
                    </div>

                    {/* Tags */}
                    {note.tags && note.tags.length > 0 && (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-4">
                            <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Tags</h3>
                            <div className="flex flex-wrap gap-1">
                                {note.tags.map((tag) => (
                                    <span key={tag} className="rounded-md bg-gray-800 px-2 py-0.5 text-xs text-gray-400">
                                        #{tag}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Wikilinks */}
                    {wikilinks.length > 0 && (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-4">
                            <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Links</h3>
                            <ul className="space-y-1">
                                {wikilinks.map((link, i) => (
                                    <li key={i} className="text-xs text-indigo-400">
                                        [[{link.display}]]
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {/* Frontmatter (raw) */}
                    {Object.keys(frontmatter).length > 0 && (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-4">
                            <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Frontmatter</h3>
                            <pre className="text-xs text-gray-500 overflow-x-auto">
                                {JSON.stringify(frontmatter, null, 2)}
                            </pre>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
