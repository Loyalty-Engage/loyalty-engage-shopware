import Plugin from 'src/plugin-system/plugin.class';

export default class NativeLoyaltyShop extends Plugin {
    init() {
        this.busy = false;
        this.buttons = [...this.el.querySelectorAll('[data-loyalty-redeem]')];
        this.buttons.forEach(button => button.addEventListener('click', () => this.redeem(button)));
        this.loadState();
    }

    async loadState() {
        try {
            const response = await fetch(this.el.dataset.stateUrl, {
                credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error(this.el.dataset.errorMessage);
            const state = await response.json();
            this.state = state;
            if (state.loggedIn && state.enabled) {
                this.el.querySelector('[data-loyalty-balance]').textContent =
                    `${this.el.dataset.balanceMessage} ${state.availableCoins} ${this.el.dataset.pointsName}`;
            }
            this.buttons.forEach(button => {
                button.disabled = this.busy || !state.loggedIn || !state.enabled || button.dataset.available !== '1' || Number(button.dataset.price) > state.availableCoins;
                button.title = Number(button.dataset.price) > state.availableCoins ? this.el.dataset.insufficientMessage : '';
            });
        } catch (error) {
            this.message(this.el.dataset.errorMessage, false);
        }
    }

    message(text, success) {
        const message = this.el.querySelector('[data-loyalty-message]');
        message.className = `alert ${success ? 'alert-success' : 'alert-danger'}`;
        message.textContent = text;
    }

    async redeem(button) {
        if (this.busy || button.disabled) return;
        this.busy = true;
        this.buttons.forEach(item => { item.disabled = true; });
        // Reuse the same ID after an uncertain response instead of charging twice.
        if (!button.dataset.requestId) {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            button.dataset.requestId = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
        }
        try {
            const response = await fetch(button.dataset.type === 'discount_code' ? this.el.dataset.discountUrl : this.el.dataset.cartUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ sku: button.dataset.sku, requestId: button.dataset.requestId }),
            });
            const data = await response.json();
            this.message(data.message || this.el.dataset.errorMessage, response.ok && data.success);
            if (data.success) {
                button.dataset.completed = '1';
                // Server-synced balances may lag; update this page immediately as well.
                this.state.availableCoins = Math.max(0, this.state.availableCoins - Number(button.dataset.price));
                this.el.querySelector('[data-loyalty-balance]').textContent = `${this.el.dataset.balanceMessage} ${this.state.availableCoins} ${this.el.dataset.pointsName}`;
            }
        } catch (error) {
            this.message(this.el.dataset.errorMessage, false);
        } finally {
            this.busy = false;
            this.buttons.forEach(item => {
                item.disabled = item.dataset.completed === '1' || item.dataset.available !== '1' || Number(item.dataset.price) > this.state.availableCoins;
            });
        }
    }
}
