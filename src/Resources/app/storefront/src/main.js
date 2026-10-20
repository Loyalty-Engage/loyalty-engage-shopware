// Import your plugin
import LoyaltyCartPlugin from './js/loyalty-cart';
import './js/custom-header';
import NativeLoyaltyShop from './js/native-loyalty-shop';

const PluginManager = window.PluginManager;

// Register the plugin with its selector
PluginManager.register('LoyaltyCartPlugin', LoyaltyCartPlugin, '[data-loyalty-cart-plugin]');
PluginManager.register('NativeLoyaltyShop', NativeLoyaltyShop, '[data-native-loyalty-shop]');

// Ensure it initializes after the DOM is fully loaded
document.addEventListener('DOMContentLoaded', () => {
    PluginManager.initializePlugins();
});

// Optional: for hot reloading in dev mode
if (module.hot) {
    module.hot.accept();
}
