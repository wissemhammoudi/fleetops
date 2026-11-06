import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { waypointIconHtml } from '../utils/route-colors';
import ensureLeafletDrawEditNamespace from '../utils/leaflet-draw-namespace-guard';

const PICKUP = '#22C55E';
const DROPOFF = '#EF4444';
const DEPOT = '#7C3AED';
const TUNIS = { lat: 36.8, lng: 10.18 };

function located(place) {
    return Number.isFinite(place?.location?.lat) && Number.isFinite(place?.location?.lng);
}

/**
 * Orchestrator map shared by the planning workspace and the route planner.
 * Matches the legacy workbench: P/D order markers before a run, numbered
 * coloured stops along each route after a run.
 */
export default class PlanningMapComponent extends Component {
    @service mapSettings;

    constructor() {
        super(...arguments);
        // leaflet-draw's marker init hook reads L.Edit.Marker without checking L.Edit.
        ensureLeafletDrawEditNamespace();
    }

    get routes() {
        return this.args.routes ?? [];
    }

    get driver() {
        return located(this.args.driver) ? this.args.driver : null;
    }

    @action fitRoute({ target: map }) {
        if (!this.args.fitRoute) return;
        const points = this.routes.flatMap((route) => [
            ...(route.coordinates ?? []),
            ...[route.origin, ...(route.stops ?? []), route.destination].filter(located).map((place) => [place.location.lat, place.location.lng]),
        ]);
        if (this.driver) points.push([this.driver.location.lat, this.driver.location.lng]);
        if (points.length) map.fitBounds(points, { padding: [24, 24], maxZoom: 15 });
    }

    get markers() {
        if (this.routes.length) return [];
        const markers = [];
        for (const order of this.args.orders ?? []) {
            const dimmed = this.args.highlightSelected && !order.selected;
            if (located(order.pickup)) {
                markers.push({ key: `${order.uuid}-p`, label: 'P', color: PICKUP, order, place: order.pickup, dimmed });
            }
            if (located(order.dropoff) && order.dropoff.uuid !== order.pickup?.uuid) {
                markers.push({ key: `${order.uuid}-d`, label: 'D', color: DROPOFF, order, place: order.dropoff, dimmed });
            }
        }
        return markers;
    }

    get depot() {
        return located(this.args.depot) ? this.args.depot : null;
    }

    get center() {
        const first = this.routes[0]?.origin?.location ?? this.routes[0]?.stops?.[0]?.location ?? this.driver?.location ?? this.depot?.location ?? this.markers[0]?.place.location;
        return first ?? TUNIS;
    }

    @action icon(label, color) {
        return waypointIconHtml(label, color);
    }

    get depotColor() {
        return DEPOT;
    }
}
