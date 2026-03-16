import { COOKIE_CONFIGURATION_UPDATE } from 'src/plugin/cookie/cookie-configuration.plugin';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';
import Plugin from 'src/plugin-system/plugin.class';

export default class LoyaltyCartPlugin extends Plugin {
    static options = {
        addToCartButtonSelector: '.loyalty-add-to-cart-button',
        claimDiscountButtonSelector: '.loyalty-claim-discount-button',
        redeemPointsButtonSelector: '.loyalty-redeem-points-button',
        removePointsDiscountButtonSelector: '.loyalty-remove-points-discount-button',
        pointsInputSelector: '.loyalty-points-input',
        productIdAttribute: 'data-product-id',
        discountAttribute: 'data-discount',
        pointsAttribute: 'data-points',
    };

    init() {
        console.log('[LoyaltyCartPlugin] INIT ✅');

        this._httpClient = new HttpClient();
        this._registerEvents();

        // Expose externally if needed
        window.addProductToLoyaltyCart = this._addProductToLoyaltyCartExternal.bind(this);
        window.claimDiscountAfterAddToLoyaltyCart = this._claimDiscountAfterAddToLoyaltyCartExternal.bind(this);
        window.redeemLoyaltyPoints = this._redeemPointsExternal.bind(this);
        window.removeLoyaltyPointsDiscount = this._removePointsDiscountExternal.bind(this);
        window.getLoyaltyRedemptionInfo = this._getRedemptionInfoExternal.bind(this);
        window.previewLoyaltyPointsRedemption = this._previewRedemptionExternal.bind(this);
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

        // Redeem points buttons
        const redeemButtons = DomAccess.querySelectorAll(document, this.options.redeemPointsButtonSelector, false);
        if (redeemButtons.length) {
            redeemButtons.forEach((button) => {
                console.log('[LoyaltyCartPlugin] Binding click event to redeem points button:', button);
                button.setAttribute('type', 'button');
                button.addEventListener('click', this._onRedeemPointsButtonClick.bind(this));
            });
        }

        // Remove points discount buttons
        const removeDiscountButtons = DomAccess.querySelectorAll(document, this.options.removePointsDiscountButtonSelector, false);
        if (removeDiscountButtons.length) {
            removeDiscountButtons.forEach((button) => {
                console.log('[LoyaltyCartPlugin] Binding click event to remove discount button:', button);
                button.setAttribute('type', 'button');
                button.addEventListener('click', this._onRemovePointsDiscountButtonClick.bind(this));
            });
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

    // ==================== POINTS REDEMPTION METHODS ====================

    /**
     * Handle redeem points button click
     */
    _onRedeemPointsButtonClick(event) {
        event.preventDefault();
        console.log('[LoyaltyCartPlugin] Redeem points button clicked');

        const button = event.currentTarget;
        
        // Get points from data attribute or from input field
        let points = parseInt(DomAccess.getAttribute(button, this.options.pointsAttribute) || '0', 10);
        
        // If no points in data attribute, try to get from input field
        if (!points) {
            const inputSelector = button.dataset.pointsInput || this.options.pointsInputSelector;
            const pointsInput = document.querySelector(inputSelector);
            if (pointsInput) {
                points = parseInt(pointsInput.value || '0', 10);
            }
        }

        if (!points || points <= 0) {
            this._createNotification('warning', 'Warning', 'Please enter a valid number of points to redeem.');
            return;
        }

        const customerEmail = window.loyaltyCustomerEmail;

        if (!customerEmail) {
            console.warn('[LoyaltyCartPlugin] Customer not logged in, redirecting to login...');
            window.location.href = '/account/login';
            return;
        }

        console.log('[LoyaltyCartPlugin] Redeeming points:', points);
        this._redeemPoints(points, button);
    }

    /**
     * Redeem loyalty points for discount
     */
    _redeemPoints(points, button) {
        if (button) {
            button.disabled = true;
            button.originalText = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Redeeming...';
        }

        this._httpClient.post(
            '/store-api/loyalty/redeem-points',
            JSON.stringify({ points }),
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
                    const message = result.partial 
                        ? `Partial redemption: €${result.discountAmount} discount applied.`
                        : `Successfully redeemed ${result.pointsRedeemed} points for €${result.discountAmount} discount!`;
                    this._createNotification('success', 'Success', message);
                    window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to redeem points');
                }
            },
            'application/json',
            true
        );
    }

    /**
     * Handle remove points discount button click
     */
    _onRemovePointsDiscountButtonClick(event) {
        event.preventDefault();
        console.log('[LoyaltyCartPlugin] Remove points discount button clicked');

        const button = event.currentTarget;
        const customerEmail = window.loyaltyCustomerEmail;

        if (!customerEmail) {
            console.warn('[LoyaltyCartPlugin] Customer not logged in, redirecting to login...');
            window.location.href = '/account/login';
            return;
        }

        this._removePointsDiscount(button);
    }

    /**
     * Remove loyalty points discount from cart
     */
    _removePointsDiscount(button) {
        if (button) {
            button.disabled = true;
            button.originalText = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Removing...';
        }

        this._httpClient.delete(
            '/store-api/loyalty/redeem-points',
            null,
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
                    this._createNotification('danger', 'Error', result.message || 'Failed to remove discount');
                }
            },
            'application/json',
            true
        );
    }

    /**
     * Get redemption info (available points, limits, etc.)
     */
    _getRedemptionInfo(callback) {
        this._httpClient.get(
            '/store-api/loyalty/redeem-points/info',
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { enabled: false, message: 'Invalid response from server' };
                }
                
                if (typeof callback === 'function') {
                    callback(result);
                }
            },
            'application/json',
            true
        );
    }

    /**
     * Preview points redemption (calculate discount without redeeming)
     */
    _previewRedemption(points, callback) {
        this._httpClient.post(
            '/store-api/loyalty/redeem-points/preview',
            JSON.stringify({ points }),
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { success: false, message: 'Invalid response from server' };
                }
                
                if (typeof callback === 'function') {
                    callback(result);
                }
            },
            'application/json',
            true
        );
    }

    // ==================== EXTERNAL API METHODS ====================

    _claimDiscountAfterAddToLoyaltyCartExternal(productId, discount, callback) {
        const email = window.loyaltyCustomerEmail;

        if (!email) {
            window.location.href = '/account/login';
            return;
        }

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
                    if (typeof callback === 'function') callback(true, result);
                    else window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to claim discount');
                    if (typeof callback === 'function') callback(false, result);
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
                    if (typeof callback === 'function') callback(true, result);
                    else window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to add product');
                    if (typeof callback === 'function') callback(false, result);
                }
            },
            'application/json',
            true
        );
    }

    /**
     * External API: Redeem loyalty points for discount
     * Usage: window.redeemLoyaltyPoints(10, (success, result) => { ... });
     */
    _redeemPointsExternal(points, callback) {
        const email = window.loyaltyCustomerEmail;

        if (!email) {
            window.location.href = '/account/login';
            return;
        }

        if (!points || points <= 0) {
            if (typeof callback === 'function') {
                callback(false, { success: false, message: 'Points must be a positive number' });
            }
            return;
        }

        this._httpClient.post(
            '/store-api/loyalty/redeem-points',
            JSON.stringify({ points }),
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { success: false, message: 'Invalid response from server' };
                }

                if (result.success) {
                    this._createNotification('success', 'Success', result.message);
                    if (typeof callback === 'function') callback(true, result);
                    else window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to redeem points');
                    if (typeof callback === 'function') callback(false, result);
                }
            },
            'application/json',
            true
        );
    }

    /**
     * External API: Remove loyalty points discount from cart
     * Usage: window.removeLoyaltyPointsDiscount((success, result) => { ... });
     */
    _removePointsDiscountExternal(callback) {
        const email = window.loyaltyCustomerEmail;

        if (!email) {
            window.location.href = '/account/login';
            return;
        }

        this._httpClient.delete(
            '/store-api/loyalty/redeem-points',
            null,
            (response) => {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    result = { success: false, message: 'Invalid response from server' };
                }

                if (result.success) {
                    this._createNotification('success', 'Success', result.message);
                    if (typeof callback === 'function') callback(true, result);
                    else window.location.reload();
                } else {
                    this._createNotification('danger', 'Error', result.message || 'Failed to remove discount');
                    if (typeof callback === 'function') callback(false, result);
                }
            },
            'application/json',
            true
        );
    }

    /**
     * External API: Get redemption info
     * Usage: window.getLoyaltyRedemptionInfo((result) => { ... });
     */
    _getRedemptionInfoExternal(callback) {
        const email = window.loyaltyCustomerEmail;

        if (!email) {
            if (typeof callback === 'function') {
                callback({ enabled: false, message: 'Customer not logged in' });
            }
            return;
        }

        this._getRedemptionInfo(callback);
    }

    /**
     * External API: Preview points redemption
     * Usage: window.previewLoyaltyPointsRedemption(10, (result) => { ... });
     */
    _previewRedemptionExternal(points, callback) {
        const email = window.loyaltyCustomerEmail;

        if (!email) {
            if (typeof callback === 'function') {
                callback({ success: false, canRedeem: false, message: 'Customer not logged in' });
            }
            return;
        }

        if (!points || points <= 0) {
            if (typeof callback === 'function') {
                callback({ success: false, canRedeem: false, message: 'Points must be a positive number' });
            }
            return;
        }

        this._previewRedemption(points, callback);
    }
}
