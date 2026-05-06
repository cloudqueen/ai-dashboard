import { useEffect, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';

interface DashboardEvent {
    id: number;
    type: string;
    payload: Record<string, unknown>;
    at: string;
}

/**
 * Subscribe to real-time dashboard events via SSE.
 * Automatically refreshes Inertia page data when events arrive.
 */
export function useDashboardEvents(onEvent?: (event: DashboardEvent) => void) {
    const lastIdRef = useRef(0);
    const eventSourceRef = useRef<EventSource | null>(null);

    const connect = useCallback(() => {
        const url = route('events.stream') + '?last_id=' + lastIdRef.current;
        const es = new EventSource(url);
        eventSourceRef.current = es;

        const handleEvent = (e: MessageEvent) => {
            try {
                const event: DashboardEvent = JSON.parse(e.data);
                lastIdRef.current = event.id;
                onEvent?.(event);

                // Refresh Inertia page data
                router.reload({ preserveScroll: true, preserveState: true });
            } catch { /* ignore malformed */ }
        };

        // Listen for all event types
        es.addEventListener('agent_run_completed', handleEvent);
        es.addEventListener('ticket_moved', handleEvent);
        es.addEventListener('checkin_completed', handleEvent);
        es.addEventListener('ticket_created', handleEvent);

        es.onerror = () => {
            es.close();
            // Reconnect after 5s
            setTimeout(connect, 5000);
        };
    }, [onEvent]);

    useEffect(() => {
        connect();
        return () => {
            eventSourceRef.current?.close();
        };
    }, [connect]);
}
