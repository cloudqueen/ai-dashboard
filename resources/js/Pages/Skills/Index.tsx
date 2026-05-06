import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Skill {
    name: string;
    display_name: string;
    description: string;
    source: 'vault' | 'database';
    model: string | null;
    references: { path: string; name: string }[];
}

interface Props {
    skills: Skill[];
}

export default function SkillsIndex({ skills }: Props) {
    const [showCreate, setShowCreate] = useState(false);
    const [selectedSkill, setSelectedSkill] = useState<Skill | null>(null);
    const [skillDetail, setSkillDetail] = useState<{ system_prompt: string; references: { name: string; content: string }[] } | null>(null);

    const loadDetail = async (skill: Skill) => {
        setSelectedSkill(skill);
        setSkillDetail(null);
        try {
            const res = await fetch(route('skills.show', { name: skill.name }), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (res.ok) {
                setSkillDetail(await res.json());
            }
        } catch { /* ignore */ }
    };

    const vaultSkills = skills.filter((s) => s.source === 'vault');
    const dbSkills = skills.filter((s) => s.source === 'database');

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-200">Skills</h2>
                    <button
                        onClick={() => setShowCreate(true)}
                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500"
                    >
                        + Neuer Skill
                    </button>
                </div>
            }
        >
            <Head title="Skills" />

            {showCreate && <CreateSkillForm onClose={() => setShowCreate(false)} />}

            <div className="flex gap-6">
                {/* Skills list */}
                <div className="flex-1">
                    {/* Vault skills */}
                    {vaultSkills.length > 0 && (
                        <div className="mb-6">
                            <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Vault Skills</h3>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {vaultSkills.map((skill) => (
                                    <SkillCard
                                        key={skill.name}
                                        skill={skill}
                                        isSelected={selectedSkill?.name === skill.name}
                                        onClick={() => loadDetail(skill)}
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {/* DB skills */}
                    {dbSkills.length > 0 && (
                        <div>
                            <h3 className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Standard Skills</h3>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {dbSkills.map((skill) => (
                                    <SkillCard
                                        key={skill.name}
                                        skill={skill}
                                        isSelected={selectedSkill?.name === skill.name}
                                        onClick={() => loadDetail(skill)}
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {skills.length === 0 && (
                        <div className="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
                            <p className="text-sm text-gray-500">
                                Noch keine Skills. Erstelle einen Vault-Skill oder führe den AgentSkillSeeder aus.
                            </p>
                        </div>
                    )}
                </div>

                {/* Detail panel */}
                {selectedSkill && (
                    <div className="hidden w-96 flex-shrink-0 lg:block">
                        <div className="sticky top-4 rounded-xl border border-gray-800 bg-gray-900 p-5">
                            <div className="mb-4 flex items-center justify-between">
                                <h3 className="text-sm font-medium text-gray-200">{selectedSkill.display_name}</h3>
                                <button onClick={() => setSelectedSkill(null)} className="text-gray-500 hover:text-gray-300">
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <div className="mb-3 flex flex-wrap gap-1.5">
                                <span className={`rounded-md px-2 py-0.5 text-[10px] ${
                                    selectedSkill.source === 'vault'
                                        ? 'bg-teal-900/30 text-teal-400'
                                        : 'bg-gray-800 text-gray-400'
                                }`}>
                                    {selectedSkill.source}
                                </span>
                                {selectedSkill.model && (
                                    <span className="rounded-md bg-purple-900/30 px-2 py-0.5 text-[10px] text-purple-400">
                                        {selectedSkill.model}
                                    </span>
                                )}
                            </div>

                            {selectedSkill.description && (
                                <p className="mb-4 text-xs text-gray-400">{selectedSkill.description}</p>
                            )}

                            {/* References */}
                            {selectedSkill.references.length > 0 && (
                                <div className="mb-4">
                                    <p className="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">Referenzen</p>
                                    <div className="space-y-1">
                                        {selectedSkill.references.map((ref) => (
                                            <div key={ref.path} className="flex items-center gap-2 rounded-lg bg-gray-800/50 px-2 py-1.5">
                                                <svg className="h-3.5 w-3.5 flex-shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                </svg>
                                                <span className="truncate text-[11px] text-gray-400">{ref.name}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* System prompt preview */}
                            {skillDetail && (
                                <div>
                                    <p className="mb-2 text-[10px] font-medium uppercase tracking-wider text-gray-500">System Prompt</p>
                                    <pre className="max-h-64 overflow-y-auto rounded-lg bg-gray-800 p-3 text-[11px] leading-relaxed text-gray-300 whitespace-pre-wrap">
                                        {skillDetail.system_prompt}
                                    </pre>
                                </div>
                            )}

                            {!skillDetail && selectedSkill && (
                                <p className="text-xs text-gray-600">Laden...</p>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function SkillCard({ skill, isSelected, onClick }: { skill: Skill; isSelected: boolean; onClick: () => void }) {
    return (
        <button
            onClick={onClick}
            className={`w-full rounded-xl border p-4 text-left transition-all ${
                isSelected
                    ? 'border-indigo-500 bg-gray-800'
                    : 'border-gray-800 bg-gray-900 hover:border-gray-700'
            }`}
        >
            <div className="flex items-start justify-between">
                <h4 className="text-sm font-medium text-gray-200">{skill.display_name}</h4>
                <span className={`rounded px-1.5 py-0.5 text-[9px] ${
                    skill.source === 'vault'
                        ? 'bg-teal-900/30 text-teal-400'
                        : 'bg-gray-800 text-gray-500'
                }`}>
                    {skill.source === 'vault' ? 'vault' : 'db'}
                </span>
            </div>
            {skill.description && (
                <p className="mt-1 text-xs text-gray-500 line-clamp-2">{skill.description}</p>
            )}
            <div className="mt-2 flex items-center gap-2">
                {skill.model && (
                    <span className="text-[10px] text-purple-400">{skill.model}</span>
                )}
                {skill.references.length > 0 && (
                    <span className="text-[10px] text-gray-600">{skill.references.length} refs</span>
                )}
            </div>
        </button>
    );
}

function CreateSkillForm({ onClose }: { onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        body: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('skills.store'), { onSuccess: () => onClose() });
    };

    return (
        <div className="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-6">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-sm font-medium text-gray-200">Neuer Vault Skill</h3>
                <button onClick={onClose} className="text-gray-500 hover:text-gray-300">
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="mb-1 block text-xs text-gray-500">Name (kebab-case)</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))}
                        placeholder="z.B. aviation-research"
                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                        autoFocus
                    />
                    {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
                </div>
                <div>
                    <label className="mb-1 block text-xs text-gray-500">Beschreibung</label>
                    <input
                        type="text"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="Was kann dieser Skill?"
                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-xs text-gray-500">System Prompt (optional — kann später in SKILL.md bearbeitet werden)</label>
                    <textarea
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        placeholder="Du bist Experte für..."
                        rows={4}
                        className="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-lg bg-gray-800 px-4 py-2 text-xs text-gray-300 hover:bg-gray-700">
                        Abbrechen
                    </button>
                    <button type="submit" disabled={processing || !data.name} className="rounded-lg bg-indigo-600 px-4 py-2 text-xs text-white hover:bg-indigo-500 disabled:opacity-50">
                        Skill erstellen
                    </button>
                </div>
            </form>
            <p className="mt-3 text-[10px] text-gray-600">
                Erstellt einen Ordner <code className="text-gray-500">skills/{data.name || '...'}/SKILL.md</code> im Vault. Du kannst dort Referenzdateien hinzufügen.
            </p>
        </div>
    );
}
