# Customer Loyalty API Documentation

This document describes the API endpoints for managing customer loyalty data in the LoyaltyEngage plugin.

## Overview

The Customer Loyalty API allows you to update and retrieve loyalty information for customers based on their email address. The plugin automatically creates custom fields on the customer entity when installed.

## Custom Fields

The following custom fields are created on the customer entity:

| Field Name | Type | Description |
|------------|------|-------------|
| `le_current_tier` | text | Current loyalty tier (e.g., "Tier 5") |
| `le_points` | integer | Total loyalty points |
| `le_available_coins` | integer | Available coins/credits |
| `le_next_tier` | text | Next tier name (nullable) |
| `le_points_to_next_tier` | integer | Points needed to reach next tier |

## Authentication

All API endpoints require authentication using Shopware's API authentication system. You need to:

1. Create an integration in Shopware Admin (Settings → System → Integrations)
2. Use the Access Key ID and Secret Access Key to obtain an OAuth token
3. Include the token in the Authorization header

### Getting an Access Token

```bash
curl -X POST http://your-shop.com/api/oauth/token \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "YOUR_ACCESS_KEY_ID",
    "client_secret": "YOUR_SECRET_ACCESS_KEY",
    "grant_type": "client_credentials"
  }'
```

Response:
```json
{
  "token_type": "Bearer",
  "expires_in": 600,
  "access_token": "YOUR_ACCESS_TOKEN"
}
```

## API Endpoints

### 1. Update Customer Loyalty Data

Update loyalty information for a customer by email address.

**Endpoint:** `POST /api/_action/loyalty-engage/customer/update`

**Headers:**
```
Content-Type: application/json
Authorization: Bearer YOUR_ACCESS_TOKEN
```

**Request Body:**
```json
{
  "email": "customer@example.com",
  "le_current_tier": "Tier 5",
  "le_points": 96,
  "le_available_coins": 2000,
  "le_next_tier": "Tier 6",
  "le_points_to_next_tier": 104
}
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `email` | string | Yes | Customer email address |
| `le_current_tier` | string | No | Current loyalty tier |
| `le_points` | integer | No | Total loyalty points |
| `le_available_coins` | integer | No | Available coins |
| `le_next_tier` | string | No | Next tier name (can be null) |
| `le_points_to_next_tier` | integer | No | Points to next tier |

**Success Response (200 OK):**
```json
{
  "success": true,
  "customerId": "018c2e4f9d7a7f8e9b5c3d2e1f0a9b8c",
  "message": "Customer loyalty data updated successfully"
}
```

**Error Response (400 Bad Request):**
```json
{
  "success": false,
  "message": "Customer not found with email: customer@example.com"
}
```

**Example cURL:**
```bash
curl -X POST http://your-shop.com/api/_action/loyalty-engage/customer/update \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -d '{
    "email": "customer@example.com",
    "le_current_tier": "Tier 5",
    "le_points": 96,
    "le_available_coins": 2000,
    "le_next_tier": null,
    "le_points_to_next_tier": 0
  }'
```

**Example PHP:**
```php
<?php
$data = [
    'email' => 'customer@example.com',
    'le_current_tier' => 'Tier 5',
    'le_points' => 96,
    'le_available_coins' => 2000,
    'le_next_tier' => null,
    'le_points_to_next_tier' => 0
];

$ch = curl_init('http://your-shop.com/api/_action/loyalty-engage/customer/update');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_ACCESS_TOKEN'
]);

$response = curl_exec($ch);
$result = json_decode($response, true);
curl_close($ch);

if ($result['success']) {
    echo "Customer updated: " . $result['customerId'];
} else {
    echo "Error: " . $result['message'];
}
```

---

### 2. Get Customer Loyalty Data

Retrieve loyalty information for a customer by email address.

**Endpoint:** `POST /api/_action/loyalty-engage/customer/get`

**Headers:**
```
Content-Type: application/json
Authorization: Bearer YOUR_ACCESS_TOKEN
```

**Request Body:**
```json
{
  "email": "customer@example.com"
}
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `email` | string | Yes | Customer email address |

**Success Response (200 OK):**
```json
{
  "success": true,
  "customerId": "018c2e4f9d7a7f8e9b5c3d2e1f0a9b8c",
  "email": "customer@example.com",
  "loyaltyData": {
    "le_current_tier": "Tier 5",
    "le_points": 96,
    "le_available_coins": 2000,
    "le_next_tier": null,
    "le_points_to_next_tier": 0
  }
}
```

**Error Response (404 Not Found):**
```json
{
  "success": false,
  "message": "Customer not found with email: customer@example.com"
}
```

**Example cURL:**
```bash
curl -X POST http://your-shop.com/api/_action/loyalty-engage/customer/get \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -d '{
    "email": "customer@example.com"
  }'
```

---

## Integration Examples

### Webhook Integration

You can set up a webhook in your loyalty system to automatically update Shopware when customer data changes:

```javascript
// Node.js example
const axios = require('axios');

async function updateCustomerLoyalty(customerData) {
  try {
    const response = await axios.post(
      'http://your-shop.com/api/_action/loyalty-engage/customer/update',
      {
        email: customerData.email,
        le_current_tier: customerData.tier,
        le_points: customerData.points,
        le_available_coins: customerData.coins,
        le_next_tier: customerData.nextTier,
        le_points_to_next_tier: customerData.pointsToNext
      },
      {
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${YOUR_ACCESS_TOKEN}`
        }
      }
    );
    
    console.log('Customer updated:', response.data);
  } catch (error) {
    console.error('Error updating customer:', error.response.data);
  }
}
```

### Batch Updates

For updating multiple customers, you can loop through your customer list:

```python
import requests

def batch_update_customers(customers, access_token):
    url = 'http://your-shop.com/api/_action/loyalty-engage/customer/update'
    headers = {
        'Content-Type': 'application/json',
        'Authorization': f'Bearer {access_token}'
    }
    
    results = []
    for customer in customers:
        data = {
            'email': customer['email'],
            'le_current_tier': customer['tier'],
            'le_points': customer['points'],
            'le_available_coins': customer['coins'],
            'le_next_tier': customer.get('next_tier'),
            'le_points_to_next_tier': customer.get('points_to_next', 0)
        }
        
        response = requests.post(url, json=data, headers=headers)
        results.append(response.json())
    
    return results
```

---

## Error Handling

### Common Error Codes

| Status Code | Description |
|-------------|-------------|
| 200 | Success |
| 400 | Bad Request - Invalid data or customer not found |
| 401 | Unauthorized - Invalid or missing access token |
| 404 | Not Found - Customer not found (GET endpoint) |
| 500 | Internal Server Error |

### Error Response Format

All error responses follow this format:

```json
{
  "success": false,
  "message": "Description of the error"
}
```

---

## Best Practices

1. **Cache Access Tokens**: OAuth tokens are valid for 600 seconds (10 minutes). Cache and reuse them to avoid unnecessary token requests.

2. **Handle Rate Limits**: Implement exponential backoff if you encounter rate limiting.

3. **Validate Email Addresses**: Ensure email addresses are valid before making API calls.

4. **Error Logging**: Log all API errors for debugging and monitoring.

5. **Batch Processing**: For bulk updates, implement batch processing with appropriate delays between requests.

6. **Idempotency**: The update endpoint is idempotent - calling it multiple times with the same data will produce the same result.

---

## Testing

### Test with Postman

1. Import the following collection:
   - Create a new POST request to `/api/oauth/token` to get your access token
   - Create a POST request to `/api/_action/loyalty-engage/customer/update`
   - Add the Authorization header with your token
   - Send test data

### Test with cURL

```bash
# Get token
TOKEN=$(curl -s -X POST http://localhost:8081/api/oauth/token \
  -H "Content-Type: application/json" \
  -d '{"client_id":"YOUR_ID","client_secret":"YOUR_SECRET","grant_type":"client_credentials"}' \
  | jq -r '.access_token')

# Update customer
curl -X POST http://localhost:8081/api/_action/loyalty-engage/customer/update \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "email": "test@example.com",
    "le_current_tier": "Gold",
    "le_points": 1500,
    "le_available_coins": 500
  }'
```

---

## Support

For issues or questions:
- GitHub: https://github.com/Loyalty-Engage/loyalty-engage-shopware
- Email: support@loyaltyengage.tech

## Changelog

### v1.1.0 (2025-12-03)
- Initial release of Customer Loyalty API
- Added custom fields for customer loyalty data
- Added update and get endpoints
