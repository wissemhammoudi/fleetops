import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsEmailCampaignsRoute extends Route {
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list email-campaign')) {
            this.notifications.warning('You do not have permission to access email campaigns.');
            return this.hostRouter.transitionTo('console.home');
        }
    }
}
