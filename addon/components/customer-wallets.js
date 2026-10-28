import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dropTask, task, timeout } from 'ember-concurrency';

const API = { namespace: 'cod/v1/admin' };

export default class CustomerWalletsComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service currentUser;
    @tracked data;
    @tracked payoutData;
    @tracked remittances = [];
    @tracked search = '';
    @tracked status = 'requested';
    @tracked walletPage = 1;
    @tracked payoutPage = 1;
    @tracked selected;
    @tracked edits = {};
    @tracked bankDetails = {};
    @tracked error = '';

    constructor() {
        super(...arguments);
        if (this.currentUser.isAdmin) {
            this.load.perform();
            this.watch.perform();
        }
    }

    money(amount, currency = 'TND') {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 3 }).format(amount / 1000);
    }

    get walletRows() {
        return (this.data?.wallets ?? []).map((row) => ({
            ...row.customer,
            available: this.money(row.wallet.available, row.wallet.currency),
            pending: this.money(row.wallet.pending, row.wallet.currency),
            reserved: this.money(row.wallet.reserved, row.wallet.currency),
            paidOut: this.money(row.wallet.paid_out, row.wallet.currency),
        }));
    }

    get selectedBalances() {
        if (!this.selected) return [];
        return ['available', 'pending', 'reserved', 'paid_out'].map((key) => ({
            label: key.replace('_', ' '),
            amount: this.money(this.selected.wallet[key], this.selected.wallet.currency),
        }));
    }

    get selectedActivity() {
        return (this.selected?.activity ?? []).map((entry) => ({
            ...entry,
            formattedAmount: this.money(entry.amount, entry.currency),
            entry: entry.meta?.entry,
        }));
    }

    get payoutRows() {
        return (this.payoutData?.payouts ?? []).map((payout) => ({
            ...payout,
            amountLabel: this.money(payout.amount),
            canApprove: payout.status === 'requested',
            canReject: ['requested', 'approved'].includes(payout.status),
            canPay: payout.status === 'approved',
            bank: this.bankDetails[payout.uuid],
            referenceInput: this.edits[payout.uuid]?.reference ?? payout.reference ?? '',
            noteInput: this.edits[payout.uuid]?.note ?? '',
        }));
    }

    get remittanceRows() {
        return this.remittances.map((row) => ({
            ...row,
            expectedLabel: this.money(row.expected),
            receivedLabel: this.money(row.received),
            receivedInput: this.edits[row.order_uuid]?.received ?? (row.expected / 1000).toFixed(3),
        }));
    }

    get firstWalletPage() {
        return this.walletPage <= 1;
    }
    get lastWalletPage() {
        return this.walletPage >= (this.data?.last_page ?? 1);
    }
    get firstPayoutPage() {
        return this.payoutPage <= 1;
    }
    get lastPayoutPage() {
        return this.payoutPage >= (this.payoutData?.last_page ?? 1);
    }
    get busy() {
        return this.load.isRunning || this.decide.isRunning || this.remit.isRunning;
    }

    @action edit(id, key, event) {
        this.edits = { ...this.edits, [id]: { ...this.edits[id], [key]: event.target.value } };
    }

    @action searchWallets() {
        this.walletPage = 1;
        this.load.perform();
    }

    @action changeStatus(event) {
        this.status = event.target.value;
        this.payoutPage = 1;
        this.bankDetails = {};
        this.load.perform();
    }

    @action page(section, delta) {
        if (section === 'wallet') this.walletPage += delta;
        else this.payoutPage += delta;
        this.load.perform();
    }

    @action closeCustomer() {
        this.selected = null;
    }

    @task *watch() {
        while (true) {
            yield timeout(30000);
            if (!document.hidden && !this.busy) yield this.load.perform();
        }
    }

    @dropTask *load() {
        this.error = '';
        try {
            const [wallets, payouts, cash] = yield Promise.all([
                this.fetch.get('wallets', { search: this.search, page: this.walletPage }, API),
                this.fetch.get('payouts', { status: this.status, page: this.payoutPage }, API),
                this.fetch.get('remittances/pending', {}, API),
            ]);
            this.data = wallets;
            this.payoutData = payouts;
            this.remittances = cash.pending;
            if (this.selected) yield this.openCustomer.perform(this.selected.customer.uuid);
        } catch (error) {
            this.error = 'Could not refresh wallets. Refresh successfully before making financial decisions.';
            this.notifications.serverError(error);
        }
    }

    @dropTask *openCustomer(id) {
        try {
            this.selected = yield this.fetch.get(`wallets/${encodeURIComponent(id)}`, {}, API);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @dropTask *showBank(payout) {
        try {
            const response = yield this.fetch.get(`payouts/${encodeURIComponent(payout.uuid)}`, {}, API);
            this.bankDetails = { ...this.bankDetails, [payout.uuid]: response.payout.rib ?? 'Cash at office - no RIB' };
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action confirmDecision(payout, decision) {
        const reference = payout.referenceInput.trim();
        if (decision === 'paid' && !reference) {
            this.notifications.warning('Enter a transfer or cash receipt reference before recording payment.');
            return;
        }
        this.modalsManager.confirm({
            title: decision === 'paid' ? 'Confirm payout was paid' : `${decision === 'approve' ? 'Approve' : 'Reject'} payout`,
            body: `${payout.customer_name ?? payout.merchant_uuid}: ${payout.amountLabel}. ${decision === 'paid' ? 'Only continue after the transfer or cash handover actually occurred. This records the payment; it does not send money.' : 'The customer will be notified of this decision.'}`,
            confirm: () => this.decide.perform(payout.uuid, decision, reference, payout.noteInput),
        });
    }

    @dropTask *decide(id, decision, reference, note) {
        try {
            yield this.fetch.post(`payouts/${encodeURIComponent(id)}/${decision}`, { reference: reference || null, note: note || null }, API);
            this.bankDetails = {};
            this.edits = {};
            this.notifications.success('Payout updated.');
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
            yield this.load.perform();
        }
    }

    @action confirmRemittance(row) {
        const input = row.receivedInput.trim().replace(',', '.');
        if (!/^\d+(\.\d{1,3})?$/.test(input)) {
            this.notifications.warning('Enter a cash total with at most three decimal places.');
            return;
        }
        const [whole, fraction = ''] = input.split('.');
        const received = Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
        if (!Number.isSafeInteger(received) || received < row.received || received > row.expected) {
            this.notifications.warning('The total cannot be below previously received cash or above the expected amount.');
            return;
        }
        this.modalsManager.confirm({
            title: 'Confirm driver cash received',
            body: `Order ${row.order}: total cash received ${this.money(received)}. Only confirm cash physically received. A partial receipt keeps the wallet funds pending.`,
            confirm: () => this.remit.perform(row.order_uuid, received),
        });
    }

    @dropTask *remit(order, received) {
        try {
            yield this.fetch.post('remittances', { items: [{ order_uuid: order, received }] }, API);
            this.edits = {};
            this.notifications.success('Cash receipt recorded.');
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
            yield this.load.perform();
        }
    }
}
