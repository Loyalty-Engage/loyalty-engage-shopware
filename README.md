# LoyaltyEngage Plugin for Shopware 6

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Shopware Version](https://img.shields.io/badge/Shopware-6.4%2B-blue.svg)](https://www.shopware.com/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)

Integrate your Shopware 6 store with the LoyaltyEngage loyalty system, enabling customers to redeem loyalty points for products, claim discounts, and automatically track purchase and return events.

## 🚀 Features

- **🎁 Points Redemption in Checkout** (NEW in v1.3.0): 
  - Customers can redeem loyalty points for discounts directly in the checkout
  - Configurable points-to-euro conversion rate
  - Minimum/maximum points limits per order
  - Maximum discount percentage of cart total
  - Beautiful UI block in cart page showing available points
  - Quick-select buttons for common redemption amounts
- **Loyalty Cart Management**: Customers can add products to their cart using loyalty points
- **Discount Claiming**: Automatic discount application after adding products to loyalty cart
- **Event Tracking**: 
  - Automatic purchase event tracking when orders are completed
  - Automatic return event tracking when orders are returned
  - Free product purchase and removal tracking
- **Cart Expiry**: Configurable automatic expiration of loyalty carts
- **Order Placement**: Automated order placement in the LoyaltyEngage system
- **API Integration**: Full integration with LoyaltyEngage API
- **Async Processing**: Message queue support for reliable event processing
- **Rule Builder Conditions**: Use customer tier, points, and coins in Shopware's rule builder

## 📋 Requirements

- Shopware 6.4.0 or higher
- PHP 7.4 or higher
- Valid LoyaltyEngage API credentials

## 📦 Installation

For detailed installation instructions, see [INSTALLATION.md](INSTALLATION.md).

### Quick Start

**Option 1: Download from Releases (Recommended)**
1. Download the latest `LoyaltyEngage.zip` from [Releases](https://github.com/Loyalty-Engage/loyalty-engage-shopware/releases)
2. Upload via Shopware Admin → **Extensions > My Extensions > Upload extension**
3. Install and activate the plugin

**Option 2: Clone from GitHub**
```bash
cd custom/plugins/
git clone https://github.com/Loyalty-Engage/loyalty-engage-shopware.git LoyaltyEngage
cd ../../
bin/console plugin:refresh
bin/console plugin:install --activate LoyaltyEngage
bin/console cache:clear
```

## ⚙️ Configuration

1. Navigate to **Settings > System > Plugins > LoyaltyEngage > Configuration**
2. Enter your LoyaltyEngage API credentials:
   - API URL (default: `https://app.loyaltyengage.tech`)
   - Client ID (Tenant ID)
   - Client Secret (Bearer Token)
3. Configure general settings:
   - Cart expiry time (hours)
   - Order retry limit
   - Enable logging (recommended for development)
   - Enable purchase/return event export

### Points Redemption Settings (v1.3.0+)

4. Configure Points Redemption:
   - **Enable Points Redemption**: Toggle to show/hide the redemption block in checkout
   - **Discount Product SKU**: The SKU of the €1 discount product in LoyaltyEngage (required)
   - **Points per Euro**: How many points equal €1 discount (default: 1)
   - **Minimum Points to Redeem**: Minimum points required per redemption (default: 1)
   - **Maximum Points per Order**: Maximum points that can be redeemed per order (0 = unlimited)
   - **Maximum Discount Percentage**: Maximum % of cart total that can be paid with points (0 = unlimited)

> **Important**: You must create a discount product in LoyaltyEngage with a value of €1. When customers redeem points, the plugin purchases this product multiple times (e.g., 10 times for €10 discount).

## 📖 Usage

### For Customers

**Redeeming Products with Points**
- Browse products and click "Redeem with Points" on product pages
- Products are added to a special loyalty cart
- Complete checkout to redeem items

**Claiming Discounts**
- Click "Claim Discount" on eligible products
- Discount is automatically applied to cart
- Complete checkout to use discount

### For Store Administrators

**Order Processing**
1. Orders with loyalty items are automatically tracked
2. When order status changes to "done", purchase events are sent to LoyaltyEngage
3. If orders are returned, return events are automatically sent

**Monitoring**
- Enable logging in plugin configuration
- Check logs at `var/log/dev.log` for detailed information
- Review scheduled tasks for cart expiry and order placement

## 🔧 Development

### Project Structure

```
LoyaltyEngage/
├── composer.json
├── src/
│   ├── LoyaltyEngage.php          # Main plugin class
│   ├── Api/                        # API interfaces
│   ├── Controller/                 # API controllers
│   ├── Message/                    # Async message handlers
│   ├── Resources/                  # Configuration, views, assets
│   ├── Scheduled/                  # Cron tasks
│   ├── Service/                    # Business logic
│   └── Subscriber/                 # Event subscribers
└── README.md
```

### Building Assets

If you modify frontend assets:

```bash
cd src/Resources/app/storefront
npm install
npm run build
```

## 🐛 Troubleshooting

See [INSTALLATION.md](INSTALLATION.md#troubleshooting) for common issues and solutions.

**Common Issues:**
- Plugin not found error → Check ZIP structure
- API connection fails → Verify credentials and network access
- Events not tracking → Enable logging and check configuration

## 📚 Documentation

- [Installation Guide](INSTALLATION.md)
- [Debugging Guide](DEBUGGING.md)
- [Test Instructions](TEST-INSTRUCTIONS.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 💬 Support

- **Email**: support@loyaltyengage.tech
- **Website**: [https://loyaltyengage.com](https://loyaltyengage.com)
- **Issues**: [GitHub Issues](https://github.com/Loyalty-Engage/loyalty-engage-shopware/issues)

## 🔗 Related Projects

- [LoyaltyEngage Magento Plugin](https://github.com/Loyalty-Engage/LoyaltyEngageMagento)

---

Made with ❤️ by LoyaltyEngage
