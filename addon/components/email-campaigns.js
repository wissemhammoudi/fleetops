import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

const API = { namespace: 'int/v1' };
const emptyVariants = () => ({ fr: { subject: '', body: '' }, en: { subject: '', body: '' }, ar: { subject: '', body: '' } });

export default class EmailCampaignsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked data;
    @tracked detail;
    @tracked busy = false;
    @tracked error = '';
    @tracked page = 1;
    @tracked editing = false;
    @tracked dirty = false;
    @tracked name = '';
    @tracked type = 'marketing';
    @tracked variants = emptyVariants();
    @tracked language = 'fr';
    @tracked preview = false;
    @tracked audience = [];
    @tracked selectedContacts = [];
    @tracked scheduleType = 'now';
    @tracked scheduledAt = '';
    @tracked recurrence = 'weekly';
    @tracked endsAt = '';
    @tracked maxOccurrences = '4';
    @tracked contacts = [];
    @tracked contactsPage = 1;
    @tracked contactsLastPage = 1;
    @tracked contactSearch = '';
    @tracked importText = '';
    @tracked consentSource = '';
    @tracked consentedAt = '';
    @tracked importAudience = 'imported';
    @tracked importLanguage = 'fr';
    @tracked importConsent = false;
    @tracked importOperational = false;
    @tracked importResult;
    @tracked suppressionEmail = '';
    @tracked suppressionReason = 'unsubscribe';

    get permissions() {
        return this.data?.permissions ?? {};
    }

    get currentVariant() {
        return this.variants[this.language];
    }

    get direction() {
        return this.language === 'ar' ? 'rtl' : 'ltr';
    }

    get audiences() {
        return [
            { value: 'customers', label: 'Customers' },
            { value: 'website', label: 'Website contacts' },
            { value: 'drivers', label: 'Drivers' },
            { value: 'imported', label: 'Imported opt-in list' },
            { value: 'manual', label: 'Manually registered contacts' },
        ].map((item) => ({ ...item, selected: this.audience.includes(item.value) }));
    }

    get contactRows() {
        return this.contacts.map((contact) => ({ ...contact, selected: this.selectedContacts.includes(contact.uuid) }));
    }

    get firstPage() {
        return this.page <= 1;
    }
    get lastPage() {
        return this.page >= (this.data?.last_page ?? 1);
    }
    get firstContactsPage() {
        return this.contactsPage <= 1;
    }
    get lastContactsPage() {
        return this.contactsPage >= this.contactsLastPage;
    }
    get isRecurring() {
        return this.scheduleType === 'recurring';
    }
    get needsDate() {
        return this.scheduleType !== 'now';
    }
    get hasSaved() {
        return Boolean(this.detail?.campaign?.uuid);
    }
    get canEdit() {
        return this.permissions.update || (!this.hasSaved && this.permissions.create);
    }
    get canSubmit() {
        return !this.dirty && this.detail?.campaign?.status === 'draft' && this.permissions.submit;
    }
    get canApprove() {
        return !this.dirty && this.detail?.campaign?.status === 'pending_approval' && this.permissions.approve;
    }
    get canSend() {
        return !this.dirty && ['approved', 'paused'].includes(this.detail?.campaign?.status) && this.permissions.send;
    }
    get canPause() {
        return !this.dirty && ['scheduled', 'sending'].includes(this.detail?.campaign?.status) && this.permissions.cancel;
    }
    get canCancel() {
        return !this.dirty && this.hasSaved && !['cancelled', 'completed'].includes(this.detail?.campaign?.status) && this.permissions.cancel;
    }
    get counts() {
        return Object.entries(this.detail?.counts ?? {}).map(([status, count]) => ({ status, count }));
    }

    @action field(name, event) {
        this[name] = event.target.value;
        if (['name', 'type', 'scheduleType', 'scheduledAt', 'recurrence', 'endsAt', 'maxOccurrences'].includes(name)) this.dirty = true;
    }

    @action checkbox(name, event) {
        this[name] = event.target.checked;
    }

    @action variant(field, event) {
        this.variants = { ...this.variants, [this.language]: { ...this.currentVariant, [field]: event.target.value } };
        this.dirty = true;
    }

    @action toggleAudience(value, event) {
        this.audience = event.target.checked ? [...this.audience, value] : this.audience.filter((item) => item !== value);
        this.dirty = true;
    }

    @action toggleContact(value, event) {
        this.selectedContacts = event.target.checked ? [...this.selectedContacts, value] : this.selectedContacts.filter((item) => item !== value);
        this.dirty = true;
    }

    @action togglePreview() {
        this.preview = !this.preview;
    }

    async perform(callback) {
        if (this.busy) return;
        this.busy = true;
        this.error = '';
        try {
            await callback();
        } catch (error) {
            this.error = 'The action failed. Check the server error below, refresh the saved campaign if it changed, and retry. Sending was not confirmed.';
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    async reload() {
        const result = await this.fetch.get('email-campaigns', { page: this.page }, API);
        if (!Array.isArray(result.campaigns) || !result.permissions) throw new Error('Invalid email campaign response.');
        this.data = result;
    }

    @action load() {
        return this.perform(() => this.reload());
    }

    @action changePage(delta) {
        this.page += delta;
        return this.load();
    }

    @action newCampaign() {
        if (this.dirty) {
            this.notifications.warning('Save or discard this draft before starting another campaign.');
            return;
        }
        this.detail = null;
        this.name = '';
        this.type = 'marketing';
        this.variants = emptyVariants();
        this.audience = [];
        this.selectedContacts = [];
        this.scheduleType = 'now';
        this.scheduledAt = '';
        this.recurrence = 'weekly';
        this.endsAt = '';
        this.maxOccurrences = '4';
        this.language = 'fr';
        this.preview = false;
        this.editing = true;
        this.dirty = false;
    }

    hydrate(result) {
        if (!result.campaign) throw new Error('Invalid campaign detail response.');
        const row = result.campaign;
        this.detail = result;
        this.name = row.name;
        this.type = row.type;
        this.variants = { ...emptyVariants(), ...row.variants };
        this.audience = row.audience ?? [];
        this.selectedContacts = row.contact_ids ?? [];
        this.scheduleType = row.schedule_type;
        this.scheduledAt = row.scheduled_at_local ?? '';
        this.recurrence = row.recurrence ?? 'weekly';
        this.endsAt = row.ends_at_local ?? '';
        this.maxOccurrences = String(row.max_occurrences ?? '');
        this.editing = true;
        this.dirty = false;
    }

    @action open(row) {
        if (this.dirty) {
            this.notifications.warning('Save or discard your draft before refreshing or opening another campaign.');
            return;
        }
        return this.perform(async () => this.hydrate(await this.fetch.get(`email-campaigns/${row.uuid}`, {}, API)));
    }

    @action discard() {
        this.editing = false;
        this.detail = null;
        this.dirty = false;
        this.preview = false;
    }

    @action save() {
        return this.perform(async () => {
            const variants = Object.fromEntries(Object.entries(this.variants).filter(([, value]) => value.subject.trim() || value.body.trim()));
            const payload = {
                name: this.name,
                type: this.type,
                variants,
                audience: this.audience,
                contact_ids: this.selectedContacts,
                schedule_type: this.scheduleType,
                scheduled_at: this.needsDate ? this.scheduledAt : null,
                recurrence: this.isRecurring ? this.recurrence : null,
                ends_at: this.isRecurring && this.endsAt ? this.endsAt : null,
                max_occurrences: this.isRecurring && this.maxOccurrences ? Number(this.maxOccurrences) : null,
            };
            const result = this.hasSaved
                ? await this.fetch.put(`email-campaigns/${this.detail.campaign.uuid}`, { ...payload, expected_version: this.detail.campaign.version }, API)
                : await this.fetch.post('email-campaigns', payload, API);
            this.hydrate(result);
            this.hydrate(await this.fetch.get(`email-campaigns/${result.campaign.uuid}`, {}, API));
            await this.reload();
            this.notifications.success('Draft saved. Changes require approval before sending.');
        });
    }

    @action transition(action) {
        return this.perform(async () => {
            const result = await this.fetch.post(`email-campaigns/${this.detail.campaign.uuid}/${action}`, { expected_version: this.detail.campaign.version }, API);
            this.hydrate(result);
            this.hydrate(await this.fetch.get(`email-campaigns/${result.campaign.uuid}`, {}, API));
            await this.reload();
            this.notifications.success(action === 'test' ? 'Test email requested for your own staff email address.' : 'Campaign action recorded.');
        });
    }

    @action loadContacts(delta = 0) {
        const target = delta === 0 ? 1 : this.contactsPage + delta;
        return this.perform(async () => {
            const result = await this.fetch.get('email-campaigns/contacts', { page: target, search: this.contactSearch }, API);
            if (!Array.isArray(result.contacts)) throw new Error('Invalid audience response.');
            this.contacts = result.contacts;
            this.contactsPage = result.page;
            this.contactsLastPage = result.last_page;
        });
    }

    @action importContacts() {
        return this.perform(async () => {
            const rows = this.importText.split(/\r?\n/).filter((line) => line.trim());
            if (!rows.length || rows.length > 500) throw new Error('Paste between 1 and 500 contacts.');
            const records = rows.map((line, index) => {
                const cells = line.split('\t');
                if (cells.length > 2) throw new Error(`Line ${index + 1}: use email, then an optional name separated by one tab.`);
                return {
                    email: cells[0].trim(),
                    name: (cells[1] ?? '').trim(),
                    locale: this.importLanguage,
                    audience: this.importAudience,
                    consented_at: this.importConsent ? this.consentedAt : null,
                    consent_source: this.importConsent ? this.consentSource : null,
                    operational: this.importOperational,
                };
            });
            this.importResult = await this.fetch.post('email-campaigns/contacts/import', { records }, API);
            this.notifications.success('Audience import processed. Review the results before scheduling.');
        });
    }

    @action suppress() {
        return this.perform(async () => {
            await this.fetch.post('email-campaigns/contacts/suppress', { email: this.suppressionEmail, reason: this.suppressionReason }, API);
            this.notifications.success('Suppression recorded. Future deliveries will recheck eligibility.');
        });
    }
}
