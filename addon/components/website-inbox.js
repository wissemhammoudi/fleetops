import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

const API = { namespace: 'int/v1' };

export default class WebsiteInboxComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked data;
    @tracked detail;
    @tracked type = '';
    @tracked statusFilter = '';
    @tracked search = '';
    @tracked page = 1;
    @tracked loading = false;
    @tracked busy = false;
    @tracked error = '';
    @tracked body = '';
    @tracked status = '';
    @tracked assignee = '';
    requestId = null;
    loadVersion = 0;

    get permissions() {
        return this.data?.permissions?.[this.detail?.submission.type] ?? {};
    }

    get types() {
        return [
            { value: 'contact', label: 'Contact enquiries' },
            { value: 'driver', label: 'Driver applications' },
        ].filter((type) => this.data?.permissions?.[type.value]?.list);
    }

    get newestHistory() {
        return (this.detail?.messages_page ?? 1) <= 1;
    }

    get oldestHistory() {
        return (this.detail?.messages_page ?? 1) >= (this.detail?.messages_last_page ?? 1);
    }

    get fields() {
        return Object.entries(this.detail?.submission.payload ?? {})
            .filter(([key]) => !['privacy_consent', 'website'].includes(key))
            .map(([key, value]) => ({ label: key.replaceAll('_', ' '), value: Array.isArray(value) ? value.join(', ') : String(value ?? '') }));
    }

    get rows() {
        return (this.data?.submissions ?? []).map((row) => ({
            ...row,
            typeLabel: row.type === 'driver' ? 'Driver application' : 'Contact enquiry',
            canView: Boolean(this.data.permissions?.[row.type]?.view),
        }));
    }

    get firstPage() {
        return this.page <= 1;
    }

    get lastPage() {
        return this.page >= (this.data?.last_page ?? 1);
    }

    @action field(name, event) {
        this[name] = event.target.value;
    }

    @action editBody(event) {
        this.body = event.target.value;
        this.requestId = null;
    }

    @action async load() {
        const version = ++this.loadVersion;
        this.loading = true;
        this.error = '';
        try {
            const result = await this.fetch.get('website-inbox', { page: this.page, type: this.type, status: this.statusFilter, search: this.search }, API);
            if (!Array.isArray(result.submissions) || !result.permissions) throw new Error('The website inbox response was invalid.');
            if (version !== this.loadVersion || this.isDestroying || this.isDestroyed) return;
            this.data = result;
        } catch (error) {
            if (version !== this.loadVersion || this.isDestroying || this.isDestroyed) return;
            this.error = 'Could not load the website inbox. Check your permissions and company configuration, then retry.';
            this.notifications.serverError(error);
        } finally {
            if (version === this.loadVersion && !this.isDestroying && !this.isDestroyed) this.loading = false;
        }
    }

    @action filter() {
        if (this.body) {
            this.notifications.warning('Send or clear your draft before changing filters.');
            return;
        }
        this.page = 1;
        this.detail = null;
        return this.load();
    }

    @action changePage(delta) {
        if (this.loading || this.busy) return;
        if (this.body) {
            this.notifications.warning('Send or clear your draft before changing pages.');
            return;
        }
        this.page += delta;
        this.detail = null;
        return this.load();
    }

    @action open(row) {
        if (this.body && row.uuid !== this.detail?.submission.uuid) {
            this.notifications.warning('Send or clear your draft before opening another conversation.');
            return;
        }
        return this.loadConversation(row, 1);
    }

    @action history(delta) {
        return this.loadConversation(this.detail.submission, this.detail.messages_page + delta);
    }

    async loadConversation(row, messagesPage) {
        if (this.busy) return;
        this.busy = true;
        this.error = '';
        try {
            const result = await this.fetch.get(`website-inbox/${encodeURIComponent(row.uuid)}`, { messages_page: messagesPage }, API);
            if (!result.submission || !Array.isArray(result.messages)) throw new Error('The conversation response was invalid.');
            this.detail = result;
            this.status = result.submission.status;
            this.assignee = result.submission.assigned_to_uuid ?? '';
        } catch (error) {
            this.error = 'Could not open this conversation. Refresh and retry.';
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    @action close() {
        this.detail = null;
        this.body = '';
        this.requestId = null;
    }

    @action async update() {
        if (this.busy || !this.detail) return;
        this.busy = true;
        this.error = '';
        try {
            this.detail = await this.fetch.put(
                `website-inbox/${encodeURIComponent(this.detail.submission.uuid)}`,
                {
                    status: this.status,
                    assigned_to_uuid: this.assignee || null,
                    expected_version: this.detail.submission.inbox_version,
                },
                API
            );
            this.notifications.success('Submission updated.');
            await this.load();
        } catch (error) {
            this.error = 'Update failed. Refresh the conversation before retrying; another staff member or incoming email may have changed it.';
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    @action async reply() {
        if (this.busy || !this.detail) return;
        if (this.body.trim().length < 2) {
            this.notifications.warning('Enter a reply of at least two characters.');
            return;
        }
        this.busy = true;
        this.error = '';
        this.requestId ??= crypto.randomUUID();
        try {
            await this.fetch.post(
                `website-inbox/${encodeURIComponent(this.detail.submission.uuid)}/reply`,
                {
                    body: this.body,
                    request_id: this.requestId,
                },
                API
            );
            this.body = '';
            this.requestId = null;
            this.notifications.success('Reply accepted by the mail transport. Final delivery is not guaranteed.');
        } catch (error) {
            this.error = 'Reply delivery was not confirmed. Review the conversation and mailbox before sending again to avoid duplicates.';
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
        const error = this.error;
        const body = this.body;
        const requestId = this.requestId;
        await this.open(this.detail.submission);
        this.error = error || this.error;
        this.body = body;
        this.requestId = requestId;
    }
}
