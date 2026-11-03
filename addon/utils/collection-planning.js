import toCalendarDate from './to-calendar-date';

export function isCollectionOrder(order) {
    const workflow = order?.meta?.collection_workflow;
    return Boolean(workflow?.version);
}

export function collectionEvents(rounds, permissions, timezone, intl) {
    const events = [];
    for (const round of rounds) {
        const start = toCalendarDate(round.scheduled_at, timezone);
        const editable = round.status === 'draft' && Boolean(permissions.plan) && !round.routing?.plan_uuid;
        events.push({
            id: `pickup:${round.uuid}`,
            resourceId: round.driver_uuid ?? 'collection-unassigned',
            title: intl.t('planning.pickup-event', { orders: round.order_count, parcels: round.parcel_count }),
            start,
            end: round.routing?.ends_at ? toCalendarDate(round.routing.ends_at, timezone) : new Date(start.getTime() + 60 * 60 * 1000),
            editable,
            durationEditable: false,
            backgroundColor: '#0f766e',
            extendedProps: { collection: true, roundUuid: round.uuid, kind: 'pickup', status: round.status },
        });
        for (const order of round.depot_orders ?? []) {
            const date = order.delivery?.manifest?.delivery_date ?? order.delivery_date;
            if (!date) continue;
            // Delivery dates are custody-derived calendar dates, not UTC instants.
            const [year, month, day] = date.split('-').map(Number);
            const routing = order.delivery?.manifest?.routing;
            const deliveryStart = routing?.departure_at ? toCalendarDate(routing.departure_at, timezone) : new Date(year, month - 1, day);
            events.push({
                id: `delivery:${order.order_uuid}`,
                resourceId: order.delivery?.driver_uuid ?? 'collection-unassigned',
                title: intl.t('planning.delivery-event', { order: order.order_id }),
                start: deliveryStart,
                end: routing?.ends_at ? toCalendarDate(routing.ends_at, timezone) : new Date(year, month - 1, day + 1),
                allDay: !routing,
                editable:
                    !routing && order.delivery?.status === 'staging' && Boolean(permissions['assign-delivery']) && !(order.delivery.manifest?.units ?? []).some((unit) => unit.staged_at),
                durationEditable: false,
                backgroundColor: '#7c3aed',
                extendedProps: { collection: true, roundUuid: round.uuid, orderUuid: order.order_uuid, kind: 'delivery', status: order.delivery?.status ?? order.state },
            });
        }
    }
    return events;
}

export function calendarDate(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
