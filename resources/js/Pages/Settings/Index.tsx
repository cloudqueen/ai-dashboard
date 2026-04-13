import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function SettingsIndex() {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-200">
                    Settings
                </h2>
            }
        >
            <Head title="Settings" />

            <div className="space-y-6">
                {/* Vault Settings */}
                <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
                    <h3 className="text-sm font-medium text-gray-200">Vault</h3>
                    <p className="mt-1 text-xs text-gray-500">Configure your Obsidian vault connection</p>
                    <div className="mt-4 space-y-3">
                        <div>
                            <label className="block text-xs font-medium text-gray-400">Vault Path</label>
                            <p className="mt-1 text-sm text-gray-300">Configured via VAULT_PATH in .env</p>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-400">Sync Status</label>
                            <span className="mt-1 inline-flex items-center gap-1.5 text-sm text-yellow-400">
                                <span className="h-2 w-2 rounded-full bg-yellow-400" />
                                Not configured
                            </span>
                        </div>
                    </div>
                </div>

                {/* Agent Settings */}
                <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
                    <h3 className="text-sm font-medium text-gray-200">Agents</h3>
                    <p className="mt-1 text-xs text-gray-500">AI agent execution settings</p>
                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <label className="block text-xs font-medium text-gray-400">Max Concurrent</label>
                            <p className="mt-1 text-sm text-gray-300">2</p>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-400">Heartbeat</label>
                            <p className="mt-1 text-sm text-gray-300">Every 3 minutes</p>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-400">Timeout</label>
                            <p className="mt-1 text-sm text-gray-300">30 minutes</p>
                        </div>
                    </div>
                </div>

                {/* Psychology Settings */}
                <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
                    <h3 className="text-sm font-medium text-gray-200">Psychology</h3>
                    <p className="mt-1 text-xs text-gray-500">Procrastination defense &amp; self-regulation</p>
                    <div className="mt-4 space-y-2 text-sm text-gray-400">
                        <p>Kahneman System 1/2, Chimp Paradox, Strudelmodell (ZRM)</p>
                        <p className="text-xs text-gray-500">Coming in Phase 3</p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
