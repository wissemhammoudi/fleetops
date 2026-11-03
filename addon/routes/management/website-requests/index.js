import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { API, cleanParams, decorate } from '../../../utils/website-request';

export default class ManagementWebsiteRequestsIndexRoute extends Route {
    @service fetch;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        type: { refreshModel: true },
        status: { refreshModel: true },
        assigned_to: { refreshModel: true },
    };

    async model({ query, ...params }) {
        const result = await this.fetch.get('website-inbox', cleanParams({ ...params, search: query }), API);
        if (!Array.isArray(result?.submissions) || !result.permissions) throw new Error('The website requests response was invalid.');
        const rows = result.submissions.map((row) => decorate(row, result.permissions));
        rows.meta = result.meta;
        rows.assignees = result.assignees ?? [];
        rows.statuses = result.statuses ?? {};
        rows.permissions = result.permissions;
        return rows;
    }
}
