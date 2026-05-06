import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';

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
    folderCounts: Record<string, number>;
    filters: {
        search: string | null;
        folder: string | null;
    };
    vaultConfigured: boolean;
}

// --- Folder tree logic ---

interface TreeNode {
    name: string;
    path: string;
    count: number;
    children: TreeNode[];
}

function buildTree(folders: string[], counts: Record<string, number>): TreeNode[] {
    const root: TreeNode[] = [];

    for (const folder of folders) {
        if (folder === '.') continue;
        const parts = folder.split('/');
        let current = root;

        for (let i = 0; i < parts.length; i++) {
            const partPath = parts.slice(0, i + 1).join('/');
            let node = current.find((n) => n.path === partPath);
            if (!node) {
                node = { name: parts[i], path: partPath, count: counts[partPath] ?? 0, children: [] };
                current.push(node);
            }
            current = node.children;
        }
    }

    return root;
}

function FolderTree({
    nodes,
    selectedFolder,
    onSelect,
    depth = 0,
}: {
    nodes: TreeNode[];
    selectedFolder: string | null;
    onSelect: (folder: string | null) => void;
    depth?: number;
}) {
    return (
        <>
            {nodes.map((node) => (
                <FolderNode
                    key={node.path}
                    node={node}
                    selectedFolder={selectedFolder}
                    onSelect={onSelect}
                    depth={depth}
                />
            ))}
        </>
    );
}

function FolderNode({
    node,
    selectedFolder,
    onSelect,
    depth,
}: {
    node: TreeNode;
    selectedFolder: string | null;
    onSelect: (folder: string | null) => void;
    depth: number;
}) {
    const hasChildren = node.children.length > 0;
    const isSelected = selectedFolder === node.path;
    const isAncestor = selectedFolder?.startsWith(node.path + '/') ?? false;
    const [expanded, setExpanded] = useState(isAncestor || isSelected || depth === 0);

    return (
        <div>
            <div
                className={`group flex items-center rounded-lg transition-colors ${
                    isSelected ? 'bg-gray-800 text-indigo-400' : 'text-gray-400 hover:bg-gray-800/50'
                }`}
                style={{ paddingLeft: `${depth * 12 + 8}px` }}
            >
                {/* Expand/collapse toggle */}
                <button
                    onClick={() => hasChildren && setExpanded(!expanded)}
                    className={`flex h-6 w-5 flex-shrink-0 items-center justify-center ${
                        hasChildren ? 'text-gray-500 hover:text-gray-300' : 'text-transparent'
                    }`}
                >
                    <svg
                        className={`h-3 w-3 transition-transform ${expanded ? 'rotate-90' : ''}`}
                        fill="currentColor"
                        viewBox="0 0 20 20"
                    >
                        <path
                            fillRule="evenodd"
                            d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                            clipRule="evenodd"
                        />
                    </svg>
                </button>

                {/* Folder name */}
                <button
                    onClick={() => onSelect(node.path)}
                    className="flex flex-1 items-center gap-2 py-1.5 pr-2 text-left text-sm"
                >
                    <svg className="h-4 w-4 flex-shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                        {expanded && hasChildren ? (
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h1.372c.516 0 .966.351 1.091.852l.427 1.712a1.125 1.125 0 001.091.852h5.769a2.25 2.25 0 012.25 2.25v.894" />
                        ) : (
                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                        )}
                    </svg>
                    <span className="truncate">{node.name}</span>
                    {node.count > 0 && (
                        <span className="ml-auto text-[10px] text-gray-600">{node.count}</span>
                    )}
                </button>
            </div>

            {/* Children */}
            {expanded && hasChildren && (
                <FolderTree
                    nodes={node.children}
                    selectedFolder={selectedFolder}
                    onSelect={onSelect}
                    depth={depth + 1}
                />
            )}
        </div>
    );
}

// --- Main component ---

export default function VaultIndex({ notes, folders, folderCounts, filters, vaultConfigured }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const tree = useMemo(() => buildTree(folders, folderCounts), [folders, folderCounts]);
    const rootCount = folderCounts['.'] ?? 0;

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
                {/* Folder tree sidebar */}
                <div className="hidden w-60 flex-shrink-0 lg:block">
                    <div className="rounded-xl border border-gray-800 bg-gray-900 p-3">
                        <h3 className="mb-2 px-2 text-xs font-medium uppercase tracking-wider text-gray-500">Folders</h3>

                        {/* All Notes */}
                        <button
                            onClick={() => selectFolder(null)}
                            className={`flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm transition-colors ${
                                !filters.folder ? 'bg-gray-800 text-indigo-400' : 'text-gray-400 hover:bg-gray-800/50'
                            }`}
                        >
                            <svg className="h-4 w-4 flex-shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375" />
                            </svg>
                            All Notes
                        </button>

                        {/* Root notes */}
                        {rootCount > 0 && (
                            <button
                                onClick={() => selectFolder('.')}
                                className={`flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm transition-colors ${
                                    filters.folder === '.' ? 'bg-gray-800 text-indigo-400' : 'text-gray-400 hover:bg-gray-800/50'
                                }`}
                            >
                                <svg className="h-4 w-4 flex-shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                                Root
                                <span className="ml-auto text-[10px] text-gray-600">{rootCount}</span>
                            </button>
                        )}

                        {/* Folder tree */}
                        <div className="mt-1">
                            <FolderTree
                                nodes={tree}
                                selectedFolder={filters.folder}
                                onSelect={selectFolder}
                            />
                        </div>
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
                                    {note.tags && Array.isArray(note.tags) && note.tags.length > 0 && (
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
