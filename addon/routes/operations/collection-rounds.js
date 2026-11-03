import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsCollectionRoundsRoute extends Route {
    @service abilities;
    @service notifications;
    @service hostRouter;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view pickup-round')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }
}
