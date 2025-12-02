# Installation Guide

## Prerequisites

- Shopware 6.4.0 or higher
- PHP 7.4 or higher
- Valid LoyaltyEngage API credentials

## Installation Methods

### Method 1: Upload via Shopware Admin (Recommended)

1. Download the latest `LoyaltyEngage.zip` from the [Releases page](https://github.com/Loyalty-Engage/loyalty-engage-shopware/releases)
2. Log in to your Shopware Admin Panel
3. Navigate to **Extensions > My Extensions**
4. Click **Upload extension**
5. Select the downloaded `LoyaltyEngage.zip` file
6. Click **Install** and then **Activate**

### Method 2: Manual Installation via Command Line

1. Navigate to your Shopware installation directory:
```bash
cd /path/to/shopware
```

2. Clone the repository into the plugins directory:
```bash
cd custom/plugins/
git clone https://github.com/Loyalty-Engage/loyalty-engage-shopware.git LoyaltyEngage
cd ../../
```

3. Install and activate the plugin:
```bash
bin/console plugin:refresh
bin/console plugin:install --activate LoyaltyEngage
bin/console cache:clear
```

### Method 3: Composer Installation (Coming Soon)

```bash
composer require loyalty-engage/shopware-plugin
bin/console plugin:refresh
bin/console plugin:install --activate LoyaltyEngage
bin/console cache:clear
```

## Configuration

After installation, configure the plugin:

1. Navigate to **Settings > System > Plugins > LoyaltyEngage > Configuration**
2. Enter your LoyaltyEngage API credentials:
   - **API URL**: Your LoyaltyEngage API endpoint (default: `https://app.loyaltyengage.tech`)
   - **Tenant ID**: Your unique tenant identifier
   - **Bearer Token**: Your API authentication token
3. Configure additional settings:
   - **Cart Expiry Time**: Time in minutes before loyalty carts expire (default: 30)
   - **Loyalty Order Retrieve Limit**: Maximum retry attempts for order placement (default: 3)
   - **Enable Logging**: Enable detailed logging for debugging (recommended for development)
   - **Enable Purchase Export**: Send purchase events to LoyaltyEngage
   - **Enable Return Export**: Send return events to LoyaltyEngage
4. Click **Save**

## Verification

To verify the installation:

1. Check that the plugin appears in **Extensions > My Extensions** with status "Active"
2. Test the API connection by adding a product to the loyalty cart
3. Check the logs at `var/log/dev.log` for any errors

## Troubleshooting

### Plugin Not Found Error

If you see "The plugin is not a valid Shopware 6 plugin":
- Ensure the ZIP file contains the `LoyaltyEngage` folder at the root level
- The folder structure should be: `LoyaltyEngage/composer.json`, `LoyaltyEngage/src/`, etc.

### API Connection Issues

If the API connection fails:
1. Verify your API credentials in the plugin configuration
2. Check that your server can reach `https://app.loyaltyengage.tech`
3. Enable logging and check `var/log/dev.log` for detailed error messages

### Cache Issues

If changes don't appear:
```bash
bin/console cache:clear
```

## Uninstallation

To uninstall the plugin:

1. Via Admin Panel:
   - Go to **Extensions > My Extensions**
   - Find "LoyaltyEngage"
   - Click **Deactivate** then **Uninstall**

2. Via Command Line:
```bash
bin/console plugin:deactivate LoyaltyEngage
bin/console plugin:uninstall LoyaltyEngage
bin/console cache:clear
```

## Support

For support, please:
- Check the [documentation](https://github.com/Loyalty-Engage/loyalty-engage-shopware/wiki)
- Open an issue on [GitHub](https://github.com/Loyalty-Engage/loyalty-engage-shopware/issues)
- Contact: support@loyaltyengage.tech
