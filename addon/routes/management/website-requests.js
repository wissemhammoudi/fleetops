import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementWebsiteRequestsRoute extends Route {
    @service hostRouter;
    @service notifications;
    @service websiteRequestCounts;

    beforeModel() {
        if (!this.websiteRequestCounts.canList) {
            this.notifications.warning('You do not have permission to access website requests.');
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }
}
