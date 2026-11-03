import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * OperationsSchedulerIndexRoute
 *
 * Responsibilities:
 *   1. Access control — redirect if the user cannot list orders.
 *   2. Initial data loading — fetch active orders and drivers in parallel.
 *   3. Socket subscription lifecycle — delegate to the controller.
 *
 * All post-load logic (filtering, event mapping, conflict detection) lives
 * in the controller and the SchedulingService.
 */
export default class OperationsSchedulerIndexRoute extends Route {
    @service store;
    @service abilities;
    @service hostRouter;
    @service collectionPlanning;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list order') && !this.collectionPlanning.canRead) {
            return this.hostRouter.transitionTo('console.fleet-ops.index');
        }
    }

    async model() {
        if (this.collectionPlanning.canRead) await this.collectionPlanning.load();
        const canListOrders = !this.abilities.cannot('fleet-ops list order');
        // Load active orders (created, dispatched, active) alongside drivers.
        // The broader status scope ensures dispatchers see each driver's full
        // day — not just pre-dispatch orders — enabling accurate conflict
        // detection and capacity visualisation.
        const [orders, drivers] = await Promise.all([
            canListOrders
                ? this.store.query('order', {
                      status: ['created', 'dispatched', 'active'],
                      with: ['payload', 'driverAssigned.vehicle'],
                      limit: 500,
                  })
                : [],
            !this.abilities.cannot('fleet-ops list driver')
                ? this.store.query('driver', {
                      with: ['currentShift'],
                      limit: 200,
                  })
                : this.collectionPlanning.drivers.map((driver) => ({ ...driver, id: driver.uuid })),
        ]);

        return { orders, drivers };
    }

    setupController(controller, model) {
        super.setupController(controller, model);

        // Set drivers on the controller so the resource timeline can render them.
        // Orders are read reactively from the Ember Data store via computed getters,
        // so we do not need to set them explicitly here.
        controller.set('drivers', model.drivers.toArray ? model.drivers.toArray() : model.drivers);
        controller.collectionView = this.collectionPlanning.canRead ? 'pickups' : null;

        // Initialise viewDate to the current UTC instant.
        // @event-calendar/core applies timezoneOffsetMins internally so the
        // calendar opens on the correct company-local day automatically.
        controller.set('viewDate', new Date());

        // Open SocketCluster subscriptions for real-time calendar updates.
        if (!this.abilities.cannot('fleet-ops list order')) controller.subscribeToRealTimeUpdates();
    }

    resetController(controller) {
        // Close all socket channels when the dispatcher navigates away
        // to prevent memory leaks and stale event handlers.
        controller.unsubscribeFromRealTimeUpdates();
    }
}
