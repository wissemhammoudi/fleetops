import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { API } from '../../../../utils/website-request';

export default class ManagementWebsiteRequestsIndexDetailsRoute extends Route {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service universe;

    @action error(error) {
        this.notifications.serverError(error);
        this.hostRouter.transitionTo('console.fleet-ops.management.website-requests.index');
    }

    async model({ id }) {
        const result = await this.fetch.get(`website-inbox/${encodeURIComponent(id)}`, {}, API);
        if (!result?.submission || !Array.isArray(result.messages) || !Array.isArray(result.statuses)) throw new Error('The request response was invalid.');
        // Opening the request marks its bell notifications read on the server, so the tray refetches.
        this.universe.trigger('notifications.read');
        return result;
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.reset(model);
    }
}
