import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface VaultNote {
    id: number;
    relative_path: string;
    title: string;
    vault_folder: string;
    type: string | null;
    status: string | null;
    priority: string | null;
    tags: string[] | null;
    body_preview: string | null;
    vault_modified_at: string;
}

interface Props {
    notes: {
        data: VaultNote[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
    };
    folders: string[];
    filters: {
        search: string | null;
        folder: string | null;
    };
    vaultConfigured: boolean;
}

export default function VaultIndex({ notes, folders, filters, vaultConfigured }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('vault'), { search: search || undefined, folder: filters.folder || undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const selectFolder = (folder: string | null) => {
        router.get(route('vault'), { search: filters.search || undefined, folder: folder || undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    if (!vaultConfigured) {
        return (
            <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-200">Vault</h2>}>
                <Head title="Vault" />
                <div className="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
                    <svg className="mx-auto h-12 w-12 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth={1} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375" />
                    </svg>
                    <h3 className="mt-4 text-sm font-medium text-gray-300">No vault connected</h3>
                    <p className="mt-2 text-sm text-gray-500">Set VAULT_PATH in your .env file and run <code className="text-gray-400">php artisan vault:index</code></p>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-200">Vault</h2>}>
            <Head title="Vault" />

            <div className="flex gap-6">
                {/* Folder sidebar */}
                <div className="hidden w-56 flex-shrink-0 lg:block">
                    <div className="rounded-xl border border-gray-800 bg-gray-900 p-3">
                        <h3 className="mb-2 px-2 text-xs font-medium uppercase tracking-wider text-gray-500">Folders</h3>
                        <button
                            onClick={() => selectFolder(null)}
                            className={`w-full rounded-lg px-3 py-1.5 text-left text-sm transition-colors ${
                                !filters.folder ? 'bg-gray-800 text-indigo-400' : 'text-gray-400 hover:bg-gray-800/50'
                            }`}
                        >
                            All Notes
                        </button>
                        {folders.map((folder) => (
                            <button
                                key={folder}
                                onClick={() => selectFolder(folder)}
                                className={`w-full rounded-lg px-3 py-1.5 text-left text-sm transition-colors ${
                                    filters.folder === folder ? 'bg-gray-800 text-indigo-400' : 'text-gray-400 hover:bg-gray-800/50'
                                }`}
                            >
                                {folder === '.' ? 'Root' : folder}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Main content */}
                <div className="flex-1">
                    {/* Search bar */}
                    <form onSubmit={handleSearch} className="mb-4">
                        <div className="relative">
                            <svg className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search notes..."
                                className="w-full rounded-xl border border-gray-800 bg-gray-900 py-2.5 pl-10 pr-4 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            />
                        </div>
                    </form>

                    {/* Notes list */}
                    {notes.data.length === 0 ? (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-8 text-center">
                            <p className="text-sm text-gray-500">
                                {filters.search ? 'No notes match your search.' : 'No notes found. Run vault:index to populate.'}
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {notes.data.map((note) => (
                                <Link
                                    key={note.id}
                                    href={route('vault.show', { path: note.relative_path })}
                                    className="block rounded-xl border border-gray-800 bg-gray-900 p-4 transition-colors hover:border-gray-700"
                                >
                                    <div className="flex items-start justify-between">
                                        <div className="min-w-0 flex-1">
                                            <h3 className="text-sm font-medium text-gray-200 truncate">{note.title}</h3>
                                            <p className="mt-0.5 text-xs text-gray-500">{note.relative_path}</p>
                                        </div>
                                        <div className="ml-3 flex items-center gap-2">
                                            {note.type && (
                                                <span className="rounded-md bg-gray-800 px-2 py-0.5 text-xs text-gray-400">
                                                    {note.type}
                                                </span>
                                            )}
                                            {note.status && (
                                                <span className="rounded-md bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">
                                                    {note.status}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    {note.body_preview && (
                                        <p className="mt-2 text-xs text-gray-500 line-clamp-2">{note.body_preview}</p>
                                    )}
                                    {note.tags && note.tags.length > 0 && (
                                        <div className="mt-2 flex gap-1">
                                            {note.tags.map((tag) => (
                                                <span key={tag} className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] text-gray-500">
                                                    #{tag}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}

                    {/* Pagination */}
                    {notes.last_page > 1 && (
                        <div className="mt-4 flex justify-center gap-1">
                            {notes.links.map((link, i) => (
                                <Link
                                    key={i}
                                    href={link.url ?? '#'}
                                    className={`rounded-lg px-3 py-1.5 text-xs ${
                                        link.active
                                            ? 'bg-indigo-600 text-white'
                                            : link.url
                                              ? 'text-gray-400 hover:bg-gray-800'
                                              : 'text-gray-600 cursor-not-allowed'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
