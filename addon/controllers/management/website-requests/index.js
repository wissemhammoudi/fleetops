import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { TYPE_OPTIONS, assigneeOptions, statusOptions } from '../../../utils/website-request';

export default class ManagementWebsiteRequestsIndexController extends Controller {
    @service hostRouter;
    @service websiteRequestCounts;

    queryParams = ['page', 'limit', 'sort', 'query', 'type', 'status', 'assigned_to'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked query;
    @tracked type;
    @tracked status;
    @tracked assigned_to;
    @tracked table;

    get isFiltered() {
        return Boolean(this.query || this.type || this.status || this.assigned_to);
    }

    get columns() {
        return [
            {
                label: 'Reference',
                valuePath: 'reference',
                cellComponent: 'table/cell/anchor',
                action: this.view,
                resizable: true,
                sortable: true,
            },
            {
                label: 'Type',
                valuePath: 'typeLabel',
                sortParam: 'type',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/select',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: TYPE_OPTIONS,
                filterComponentPlaceholder: 'Select request type',
            },
            { label: 'Name', valuePath: 'name', resizable: true, sortable: true },
            { label: 'Phone', valuePath: 'phone', resizable: true },
            { label: 'Email', valuePath: 'email', resizable: true },
            {
                label: 'Status',
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/select',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: statusOptions(this.model?.statuses),
                filterComponentPlaceholder: 'Select status',
            },
            {
                label: 'Assigned to',
                valuePath: 'assigneeLabel',
                resizable: true,
                filterable: true,
                filterParam: 'assigned_to',
                filterComponent: 'filter/select',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: assigneeOptions(this.model?.assignees),
                filterComponentPlaceholder: 'Select staff member',
            },
            { label: 'Received', valuePath: 'receivedAt', sortParam: 'created_at', resizable: true, sortable: true },
        ];
    }

    get actionButtons() {
        return [{ icon: 'refresh', onClick: this.refresh, helpText: 'Refresh' }];
    }

    @task({ restartable: true }) *searchTask({ target: { value } }) {
        if (!value) {
            this.query = null;
            return;
        }
        yield timeout(250);
        if (this.page > 1) this.page = 1;
        this.query = value;
    }

    @action view(row) {
        if (!row?.canView) return;
        return this.hostRouter.transitionTo('console.fleet-ops.management.website-requests.index.details', row.uuid);
    }

    @action refresh() {
        this.websiteRequestCounts.refresh();
        return this.hostRouter.refresh();
    }
}
