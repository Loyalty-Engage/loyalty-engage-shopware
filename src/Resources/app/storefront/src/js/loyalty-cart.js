import { COOKIE_CONFIGURATION_UPDATE } from 'src/plugin/cookie/cookie-configuration.plugin';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';
import Plugin from 'src/plugin-system/plugin.class';

export default class LoyaltyCartPlugin extends Plugin {
    static options = {
        addToCartButtonSelector: '.loyalty-add-to-cart-button',
        claimDiscountButtonSelector: '.loyalty-claim-discount-button',
        productIdAttribute: 'data-product-id',
        discountAttribute: 'data-discount',
    };

    init() {
        console.log('[LoyaltyCartPlugin] INIT ✅');

        this._httpClient = new HttpClient();
        this._registerEvents();

        // Expose externally if needed
        window.addProductToLoyaltyCart = this._addProductToLoyaltyCartExternal.bind(this);
        window.claimDiscountAfterAddToLoyaltyCart = this._claimDiscountAfterAddToLoyaltyCartExternal.bind(this);
    }

    _registerEvents() {
        console.log('[LoyaltyCartPlugin] _registerEvents() called');

        document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, this._onCookieConfigurationUpdate.bind(this));

        // Regular add to cart buttons
        const addButtons = DomAccess.querySelectorAll(document, this.options.addToCartButtonSelector, false);
        if (addButtons.length) {
            addButtons.forEach((button) => {
                console.log('[LoyaltyCartPlugin] Binding click event to add button:', button);
                button.setAttribute('type', 'button');
                button.addEventListener('click', this._onAddToCartButtonClick.bind(this));
            });
        } else {
            console.warn('[LoyaltyCartPlugin] No add buttons found');
        }

        // Discount claim buttons
        const discountButtons = DomAccess.querySelectorAll(document, this.options.claimDiscountButtonSelector, false);
        if (discountButtons.length) {
            discountButtons.forEach((button) => {
                console.log('[LoyaltyCartPlugin] Binding click event to discount button:', button);
                button.setAttribute('type', 'button');
                button.addEventListener('click', this._onClaimDiscountButtonClick.bind(this));
            });
        } else {
            console.warn('[LoyaltyCartPlugin] No discount buttons found');
        }
    }

    _onCookieConfigurationUpdate() {
        this.init(); // Rebind events if cookie settings change
    }

    _onAddToCartButtonClick(event) {
        event.preventDefault();
        console.log('[LoyaltyCartPlugin] Button clicked');

        const button = event.currentTarget;
        const productId = DomAccess.getAttribute(button, this.options.productIdAttribute);
        console.log('[LoyaltyCartPlugin] Product ID:', productId);

        // Check if customer is logged in (Store API will handle auth via session)
        const customerEmail = window.loyaltyCustomerEmail;

        if (!customerEmail) {
            console.warn('[LoyaltyCartPlugin] Customer not logged in, redirecting to login...');
            window.location.href = '/account/login';
            return;
        }

        console.log('[LoyaltyCartPlugin] Customer logged in, adding product...');
        this._addProductToLoyaltyCart(productId, button);
    }

    _addProductToLoyaltyCart(productId, button) {
        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';

        // Use Store API endpoint - authentication is handled via customer session
        this._httpClient.post(
            '/store-api/loyalty/cart/add',
            JSON.stringify({ productId }),
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { success: false, message: 'Invalid response from server' };
                }
                
                button.disabled = false;
                button.innerHTML = originalText;

                if (result.success) {
                    this._createNotification('success', 'Success', result.message);
                    window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to add product');
                }
            },
            'application/json',
            true // contentType header
        );
    }

    _createNotification(type, title, message) {
        const event = new CustomEvent('showNotification', {
            detail: { type, title, message }
        });
        document.dispatchEvent(event);
    }

    _onClaimDiscountButtonClick(event) {
        event.preventDefault();
        console.log('[LoyaltyCartPlugin] Discount button clicked');

        const button = event.currentTarget;
        const productId = DomAccess.getAttribute(button, this.options.productIdAttribute);
        const discount = parseFloat(DomAccess.getAttribute(button, this.options.discountAttribute) || '0.1');
        
        console.log('[LoyaltyCartPlugin] Product ID:', productId, 'Discount:', discount);

        const customerEmail = window.loyaltyCustomerEmail;

        if (!customerEmail) {
            console.warn('[LoyaltyCartPlugin] Customer not logged in, redirecting to login...');
            window.location.href = '/account/login';
            return;
        }

        console.log('[LoyaltyCartPlugin] Customer logged in, claiming discount...');
        this._claimDiscountAfterAddToLoyaltyCart(productId, discount, button);
    }

    _claimDiscountAfterAddToLoyaltyCart(productId, discount, button) {
        if (button) {
            button.disabled = true;
            button.originalText = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
        }

        // Use Store API endpoint - authentication is handled via customer session
        this._httpClient.post(
            '/store-api/loyalty/cart/claim-discount',
            JSON.stringify({ productId, discount }),
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { success: false, message: 'Invalid response from server' };
                }
                
                if (button) {
                    button.disabled = false;
                    button.innerHTML = button.originalText;
                }

                if (result.success) {
                    this._createNotification('success', 'Success', result.message);
                    window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to claim discount');
                }
            },
            'application/json',
            true
        );
    }

    _claimDiscountAfterAddToLoyaltyCartExternal(productId, discount, callback) {
        const email = window.loyaltyCustomerEmail;

        if (!email) {
            window.location.href = '/account/login';
            return;
        }

        // Use Store API endpoint
        this._httpClient.post(
            '/store-api/loyalty/cart/claim-discount',
            JSON.stringify({ productId, discount: discount || 0.1 }),
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { success: false, message: 'Invalid response from server' };
                }

                if (result.success) {
                    this._createNotification('success', 'Success', result.message);
                    if (typeof callback === 'function') callback(true, result.message);
                    else window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to claim discount');
                    if (typeof callback === 'function') callback(false, result.message);
                }
            },
            'application/json',
            true
        );
    }

    _addProductToLoyaltyCartExternal(productId, callback) {
        const email = window.loyaltyCustomerEmail;

        if (!email) {
            window.location.href = '/account/login';
            return;
        }

        // Use Store API endpoint
        this._httpClient.post(
            '/store-api/loyalty/cart/add',
            JSON.stringify({ productId }),
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { success: false, message: 'Invalid response from server' };
                }

                if (result.success) {
                    this._createNotification('success', 'Success', result.message);
                    if (typeof callback === 'function') callback(true, result.message);
                    else window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to add product');
                    if (typeof callback === 'function') callback(false, result.message);
                }
            },
            'application/json',
            true
        );
    }
}
