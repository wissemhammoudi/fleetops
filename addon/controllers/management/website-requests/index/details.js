import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { API, formatDate, permissionFor, submissionFields, typeLabel } from '../../../../utils/website-request';

export default class ManagementWebsiteRequestsIndexDetailsController extends Controller {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service websiteRequestCounts;

    tabs = [
        { id: 'details', label: 'Details' },
        { id: 'history', label: 'History' },
    ];
    @tracked status = '';
    @tracked assignee = '';
    @tracked busy = false;
    @tracked error = '';

    get actionButtons() {
        return [{ icon: 'refresh', helpText: 'Refresh request', fn: () => this.reload() }];
    }

    get submission() {
        return this.model?.submission ?? {};
    }

    get typeLabel() {
        return typeLabel(this.submission.type);
    }

    get receivedAt() {
        return formatDate(this.submission.created_at);
    }

    get fields() {
        return submissionFields(this.submission.payload);
    }

    get canUpdate() {
        return !this.abilities.cannot(permissionFor(this.submission.type, 'update'));
    }

    get isDriver() {
        return this.submission.type === 'driver';
    }

    get legacyStatus() {
        return this.submission.status === 'waiting_for_reply';
    }

    get unchanged() {
        return this.status === this.submission.status && (this.assignee || null) === (this.submission.assigned_to_uuid ?? null);
    }

    get history() {
        return (this.model?.messages ?? []).map((message) => ({
            ...message,
            when: formatDate(message.created_at),
            who: message.actor_name ?? (message.actor_uuid ? 'Former staff member' : 'Website visitor'),
        }));
    }

    get newestHistory() {
        return (this.model?.messages_page ?? 1) <= 1;
    }

    get oldestHistory() {
        return (this.model?.messages_page ?? 1) >= (this.model?.messages_last_page ?? 1);
    }

    reset(model) {
        this.status = model?.submission?.status ?? '';
        this.assignee = model?.submission?.assigned_to_uuid ?? '';
        this.error = '';
        this.busy = false;
    }

    @action field(name, event) {
        this[name] = event.target.value;
    }

    @action close() {
        return this.hostRouter.transitionTo('console.fleet-ops.management.website-requests.index');
    }

    @action async save() {
        if (this.busy || !this.canUpdate || this.unchanged) return;
        this.busy = true;
        this.error = '';
        try {
            const result = await this.fetch.put(
                `website-inbox/${encodeURIComponent(this.submission.uuid)}`,
                { status: this.status, assigned_to_uuid: this.assignee || null, expected_version: this.submission.inbox_version },
                API
            );
            this.model = result;
            this.reset(result);
            this.notifications.success('Request updated.');
            this.websiteRequestCounts.refresh();
            this.hostRouter.refresh('console.fleet-ops.management.website-requests.index');
        } catch (error) {
            this.error = 'Update failed. Refresh the request before retrying; another staff member may have changed it.';
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    @action async reload(messagesPage = this.model?.messages_page ?? 1) {
        if (this.busy) return;
        this.busy = true;
        this.error = '';
        try {
            const result = await this.fetch.get(`website-inbox/${encodeURIComponent(this.submission.uuid)}`, { messages_page: messagesPage }, API);
            if (!result?.submission || !Array.isArray(result.messages)) throw new Error('The request response was invalid.');
            this.model = result;
            this.reset(result);
        } catch (error) {
            this.error = 'Could not refresh this request. Try again.';
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    @action historyPage(delta) {
        return this.reload((this.model?.messages_page ?? 1) + delta);
    }
}
