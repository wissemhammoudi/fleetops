import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { buildServiceStopsFromPayload, describeRoutePoint } from '../../utils/route-visualization';
import { colorForId } from '../../utils/route-colors';

export default class ModalsOrderChangeDestinationComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked selectedOption;

    get order() {
        return this.args.options?.order;
    }

    get waypoints() {
        const waypoints = this.order?.payload?.waypoints;
        if (typeof waypoints?.toArray === 'function') {
            return waypoints.toArray();
        }

        return Array.isArray(waypoints) ? waypoints : Array.from(waypoints ?? []);
    }

    get routeColor() {
        return colorForId(this.order?.public_id ?? this.order?.id ?? 'order-route');
    }

    get serviceStops() {
        return buildServiceStopsFromPayload(this.order?.payload);
    }

    get currentWaypointId() {
        const activeStop = this.order?.tracker_data?.active_stop;
        const payload = this.order?.payload;

        return payload?.meta?.service_stop?.key ?? activeStop?.uuid ?? activeStop?.public_id ?? payload?.current_waypoint_uuid ?? payload?.current_waypoint;
    }

    get waypointOptions() {
        return this.serviceStops.map((stop, index) => ({
            waypoint: stop.place,
            stop,
            index,
            id: stop.stopKey ?? this.waypointId(stop.place),
            isCurrent: this.isCurrentWaypoint(stop),
            isSelected: this.isSelectedStop(stop),
            label: describeRoutePoint(stop, this.routeColor).label,
            title: describeRoutePoint(stop, this.routeColor).title,
            tracking: this.trackingForStop(stop),
        }));
    }

    waypointId(waypoint) {
        return waypoint?.id ?? waypoint?.public_id ?? waypoint?.uuid;
    }

    isCurrentWaypoint(stop) {
        if (this.order?.payload?.meta?.service_stop?.key) {
            return stop.stopKey === this.currentWaypointId;
        }
        const waypoint = stop.place;
        const matchingStops = this.serviceStops.filter(({ place }) => [place?.id, place?.uuid, place?.public_id].includes(this.currentWaypointId));
        return matchingStops.length === 1 && [waypoint?.id, waypoint?.uuid, waypoint?.public_id].includes(this.currentWaypointId);
    }

    isSelectedStop(stop) {
        return this.selectedOption?.id === (stop.stopKey ?? this.waypointId(stop.place));
    }

    trackingForStop(stop) {
        return stop?.tracking ?? stop?.trackingNumberUuid ?? stop?.place?.tracking ?? stop?.place?.tracking_number ?? this.order?.tracking ?? this.order?.tracking_number;
    }

    @action selectOption(option) {
        if (!option?.isCurrent) {
            this.selectedOption = option;
        }
    }

    @task *confirmSelection(modal) {
        const option = this.selectedOption;
        const waypoint = option?.waypoint;
        const waypointId = option?.id;
        if (!waypointId) {
            modal.stopLoading();
            return this.notifications.warning('Select a destination first.');
        }

        modal.startLoading();

        try {
            const order = yield this.fetch.patch(`orders/set-destination/${this.order.id}/${encodeURIComponent(waypointId)}`);
            this.notifications.success(`Current destination changed to ${waypoint.name ?? waypoint.address ?? waypointId}.`);

            if (typeof this.args.options?.onChange === 'function') {
                yield this.args.options.onChange(order, waypoint);
            }

            modal.done();
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            modal.stopLoading();
        }
    }
}
