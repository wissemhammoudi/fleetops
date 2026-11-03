import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsEmailCampaignsRoute extends Route {
    @service hostRouter;
    @service notifications;

    beforeModel() {
        this.notifications.warning('Email campaigns have been retired.');
        return this.hostRouter.transitionTo('console.home');
    }
}
