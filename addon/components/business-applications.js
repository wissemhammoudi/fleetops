import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BusinessApplicationsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked applications = [];
    @tracked application;
    @tracked documents = [];
    @tracked reviewed = [];
    @tracked note = '';
    @tracked page = 1;
    @tracked lastPage = 1;
    @tracked error;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get fields() {
        return Object.entries(this.application?.profile ?? {}).map(([name, value]) => ({ name: name.replaceAll('_', ' '), value: String(value ?? '') }));
    }

    @task({ restartable: true }) *load() {
        this.error = null;
        try {
            const result = yield this.fetch.get('business-applications', { page: this.page }, { namespace: 'int/v1' });
            this.applications = result.applications;
            this.lastPage = result.last_page;
        } catch (error) {
            this.error = error.message;
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *show(id) {
        this.application = null;
        this.reviewed = [];
        this.note = '';
        try {
            const result = yield this.fetch.get(`business-applications/${id}`, {}, { namespace: 'int/v1' });
            this.application = result.application;
            this.documents = result.documents;
        } catch (error) {
            this.error = error.message;
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *download(document) {
        try {
            const result = yield this.fetch.get(`business-applications/${this.application.uuid}/documents/${document.uuid}`, {}, { namespace: 'int/v1' });
            const bytes = Uint8Array.from(atob(result.content), (char) => char.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], { type: result.mime }));
            const anchor = window.document.createElement('a');
            anchor.href = url;
            anchor.download = result.filename;
            anchor.click();
            URL.revokeObjectURL(url);
            this.reviewed = [...this.reviewed, document.uuid];
        } catch (error) {
            this.error = error.message;
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *decide(decision) {
        try {
            yield this.fetch.post(
                `business-applications/${this.application.uuid}/decision`,
                {
                    revision: this.application.revision,
                    decision,
                    note: this.note,
                    reviewed_documents: this.reviewed,
                },
                { namespace: 'int/v1' }
            );
            this.application = null;
            yield this.load.perform();
        } catch (error) {
            this.error = error.message;
            this.notifications.serverError(error);
        }
    }

    @action previous() {
        if (this.page > 1) {
            this.page--;
            this.load.perform();
        }
    }

    @action next() {
        if (this.page < this.lastPage) {
            this.page++;
            this.load.perform();
        }
    }
}
