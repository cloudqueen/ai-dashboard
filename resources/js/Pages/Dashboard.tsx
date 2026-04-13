import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Dashboard() {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-200">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                {/* Kanban Summary */}
                <DashboardCard title="Kanban" description="Task overview">
                    <div className="space-y-2 text-sm text-gray-400">
                        <p>No tickets yet. Connect your vault to get started.</p>
                    </div>
                </DashboardCard>

                {/* Agent Status */}
                <DashboardCard title="Agents" description="AI agent status">
                    <div className="flex items-center gap-2">
                        <span className="inline-block h-2 w-2 rounded-full bg-gray-600" />
                        <span className="text-sm text-gray-400">No agents running</span>
                    </div>
                </DashboardCard>

                {/* Vault Status */}
                <DashboardCard title="Vault" description="Memory system">
                    <div className="flex items-center gap-2">
                        <span className="inline-block h-2 w-2 rounded-full bg-yellow-500" />
                        <span className="text-sm text-gray-400">Not configured</span>
                    </div>
                </DashboardCard>

                {/* Upcoming Due Dates */}
                <DashboardCard title="Due Soon" description="Upcoming deadlines">
                    <p className="text-sm text-gray-500">No upcoming tasks</p>
                </DashboardCard>

                {/* Recent Activity */}
                <DashboardCard title="Activity" description="Recent events">
                    <p className="text-sm text-gray-500">No recent activity</p>
                </DashboardCard>

                {/* Quick Capture */}
                <DashboardCard title="Quick Capture" description="Add to inbox">
                    <p className="text-sm text-gray-500">Connect vault to enable quick capture</p>
                </DashboardCard>
            </div>
        </AuthenticatedLayout>
    );
}

function DashboardCard({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-gray-800 bg-gray-900 p-6">
            <div className="mb-4">
                <h3 className="text-sm font-medium text-gray-200">{title}</h3>
                <p className="text-xs text-gray-500">{description}</p>
            </div>
            {children}
        </div>
    );
}
