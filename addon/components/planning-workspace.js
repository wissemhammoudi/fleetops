import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class PlanningWorkspaceComponent extends Component {
    @service collectionPlanning;
    @service abilities;
    @service modalsManager;
    @service intl;
    @tracked section = null;

    get activeSection() {
        return this.section ?? (this.collectionPlanning.canRead ? 'pickups' : 'orders');
    }

    get canPlanOrders() {
        return !this.abilities.cannot('fleet-ops list order');
    }

    @action select(section) {
        if (section === this.activeSection) return;
        if (this.activeSection === 'orders') {
            this.modalsManager.confirm({
                title: this.intl.t('planning.switch-title'),
                body: this.intl.t('planning.switch-help'),
                confirm: () => {
                    this.section = section;
                },
            });
        } else {
            this.section = section;
        }
    }
}
