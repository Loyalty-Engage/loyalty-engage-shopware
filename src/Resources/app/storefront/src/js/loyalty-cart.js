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
        document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, this._onCookieConfigurationUpdate.bind(this));

        // Regular add to cart buttons
        const addButtons = DomAccess.querySelectorAll(document, this.options.addToCartButtonSelector, false);
        if (addButtons.length) {
            addButtons.forEach((button) => {
                button.setAttribute('type', 'button');
                button.addEventListener('click', this._onAddToCartButtonClick.bind(this));
            });
        }

        // Discount claim buttons
        const discountButtons = DomAccess.querySelectorAll(document, this.options.claimDiscountButtonSelector, false);
        if (discountButtons.length) {
            discountButtons.forEach((button) => {
                button.setAttribute('type', 'button');
                button.addEventListener('click', this._onClaimDiscountButtonClick.bind(this));
            });
        }

        // Redeem points buttons
        const redeemButtons = DomAccess.querySelectorAll(document, this.options.redeemPointsButtonSelector, false);
        if (redeemButtons.length) {
            redeemButtons.forEach((button) => {
                button.setAttribute('type', 'button');
                button.addEventListener('click', this._onRedeemPointsButtonClick.bind(this));
            });
        }

        // Remove points discount buttons
        const removeDiscountButtons = DomAccess.querySelectorAll(document, this.options.removePointsDiscountButtonSelector, false);
        if (removeDiscountButtons.length) {
            removeDiscountButtons.forEach((button) => {
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

        const button = event.currentTarget;
        const productId = DomAccess.getAttribute(button, this.options.productIdAttribute);

        const customerEmail = window.loyaltyCustomerEmail;

        if (!customerEmail) {
            window.location.href = '/account/login';
            return;
        }

        this._addProductToLoyaltyCart(productId, button);
    }

    _addProductToLoyaltyCart(productId, button) {
        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';

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
            true
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

        const button = event.currentTarget;
        const productId = DomAccess.getAttribute(button, this.options.productIdAttribute);
        const discount = parseFloat(DomAccess.getAttribute(button, this.options.discountAttribute) || '0.1');

        const customerEmail = window.loyaltyCustomerEmail;

        if (!customerEmail) {
            window.location.href = '/account/login';
            return;
        }

        this._claimDiscountAfterAddToLoyaltyCart(productId, discount, button);
    }

    _claimDiscountAfterAddToLoyaltyCart(productId, discount, button) {
        if (button) {
            button.disabled = true;
            button.originalText = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
        }

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

    _onRedeemPointsButtonClick(event) {
        event.preventDefault();

        const button = event.currentTarget;
        
        let points = parseInt(DomAccess.getAttribute(button, this.options.pointsAttribute) || '0', 10);
        
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
            window.location.href = '/account/login';
            return;
        }

        this._redeemPoints(points, button);
    }

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

    _onRemovePointsDiscountButtonClick(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const customerEmail = window.loyaltyCustomerEmail;

        if (!customerEmail) {
            window.location.href = '/account/login';
            return;
        }

        this._removePointsDiscount(button);
    }

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
