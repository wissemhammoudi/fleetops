import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsWebsiteInboxRoute extends Route {
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list contact-enquiry') && this.abilities.cannot('fleet-ops list driver-application')) {
            this.notifications.warning('You do not have permission to access the website inbox.');
            return this.hostRouter.transitionTo('console.home');
        }
    }
}
