# Backend Task Store API

A Laravel 13 REST-only API built for an online store backend. The application handles phone-based authentication, product management, stock alert subscriptions, concurrency-safe order creation with idempotency protection, stateful order status transitions, background notifications, and query options for listing endpoints.

The application includes:
- Phone-based authentication, phone verification, and password reset workflows
- Admin product management with file uploads
- Queued notifications for product releases, restock alerts, and order updates
- Back-in-stock notification requests
- Atomic, concurrency-safe order placement with row-level product locking
- Validated order status workflows with historical transition audit logs
- Product and order filtering, sorting, and pagination
- Idempotent order placement via unique key headers
- Automated test coverage
- Deterministic demo database seeding
- A ready-to-import Postman Collection v2.1

---

## Technology Stack

- **PHP**: `^8.3`
- **Framework**: Laravel `^13.8`
- **Authentication**: Laravel Sanctum `^4.0`
- **Queue System**: Laravel Database Queue (`database` driver)
- **Testing Framework**: PHPUnit `^12.5.12`
- **Database Engine**:
  - SQLite (default for local development and testing)
  - MySQL or PostgreSQL (supported for production and row-lock concurrency testing)
- **API Documentation & Testing**: Postman Collection v2.1

---

## Features

### 1. Authentication
- User registration requires `name`, `phone`, `password`, and `password_confirmation`.
- User and admin login require `phone` and `password`, returning Sanctum Bearer tokens.
- Public registration creates regular users only (`role: user`). Admin accounts cannot be created via public endpoints.
- Unverified users receive phone verification codes logged during development.
- Password reset generates a reset code, and resetting a password revokes all active Sanctum tokens for security.

### 2. Product Management
- Authenticated users can list products and view individual product details.
- Admin users can create, update (using `PUT` or `PATCH`), and delete products.
- Product fields include `title`, `price`, `description`, `available_stock`, and `image`.
- Product creation accepts image file uploads (`jpg`, `jpeg`, `png`, `webp` < 2MB).
- `ProductResource` exposes a full public `image_url` while concealing internal `image_path` storage locations.
- Creating a product dispatches a queued event to notify users.

### 3. Back-in-Stock Notifications
- Users can request a notification for products with `available_stock = 0`.
- Requests for products currently in stock are rejected with HTTP 409.
- Requests are idempotent per user and product.
- A `Product` model observer detects restock events (`available_stock` transitioning from 0 to >0) and triggers background notifications to subscribed users.
- Subscriptions are removed upon successful notification delivery.
- Listener retries generate deterministic notification IDs to avoid duplicate user notifications.

### 4. Order Creation
- Users specify product IDs and requested quantities.
- Unit prices and line totals are strictly calculated from current database product records (client prices are ignored).
- Snapshots of `product_title` and `unit_price` are preserved in order item records.
- Order creation runs inside a database transaction (`DB::transaction(..., 3)`).
- Products are fetched and locked using `lockForUpdate()` in deterministic ascending ID order to prevent database deadlocks.
- If requested quantity exceeds `available_stock` for any item, the entire order is rejected (HTTP 409) with no partial stock deduction.
- System errors trigger a full transaction rollback.

### 5. Order Access
- Regular users can list and view only their own orders.
- Admin users can list all orders across the system.
- Supplying ownership parameters (such as `user_id`) in request queries cannot bypass access controls for regular users.
- Order item snapshots remain untouched if referenced products are modified or deleted later.

### 6. Order Status Management
Available order statuses: `pending`, `confirmed`, `processing`, `shipped`, `delivered`, `cancelled`.

Valid status transition workflow:
- `pending` -> `confirmed` or `cancelled`
- `confirmed` -> `processing` or `cancelled`
- `processing` -> `shipped` or `cancelled`
- `shipped` -> `delivered`
- `delivered` -> terminal status (no further changes allowed)
- `cancelled` -> terminal status (no further changes allowed)

Status transition rules:
- State transitions are recorded in `order_status_histories` storing `previous_status`, `new_status`, `changed_by_user_id`, and `changed_at`.
- Submitting the current status again succeeds as a no-op (HTTP 200), creates no history entry, and sends no notification.
- Invalid status jumps return HTTP 409 with details on allowed next statuses.

### 7. Notifications
- System events dispatch background jobs to the `notifications` queue.
- API requests complete immediately without waiting for notification delivery.
- Notification delivery failures do not roll back successful database operations.
- Notification listeners use deterministic notification IDs to enforce idempotency across retries.

---

## Bonus Features

### Product Listing Options (`GET /api/v1/products`)
Supported query parameters:
- `search`: Case-insensitive title and description search
- `min_price`: Minimum product price filter (inclusive)
- `max_price`: Maximum product price filter (inclusive)
- `stock_status`: Filter by `in_stock` or `out_of_stock`
- `sort_by`: Allowed values: `id`, `title`, `price`, `available_stock`, `created_at` (default: `created_at`)
- `sort_direction`: `asc` or `desc` (default: `desc`)
- `per_page`: Number of results per page (default: `15`, min: `1`, max: `100`)
- `page`: Page number (default: `1`)

Pagination links preserve active filter and sort query parameters via `withQueryString()`.

### Order Listing Options (`GET /api/v1/orders`)
Supported query parameters:
- `status`: Filter by order status (`pending`, `confirmed`, `processing`, `shipped`, `delivered`, `cancelled`)
- `min_total`: Minimum order `total_amount` (inclusive)
- `max_total`: Maximum order `total_amount` (inclusive)
- `created_from`: Start date in `Y-m-d` format (inclusive from `00:00:00`)
- `created_to`: End date in `Y-m-d` format (inclusive to `23:59:59`)
- `sort_by`: Allowed values: `id`, `status`, `total_amount`, `created_at`, `updated_at` (default: `id`)
- `sort_direction`: `asc` or `desc` (default: `desc`)
- `per_page`: Results per page (default: `15`, min: `1`, max: `100`)
- `page`: Page number (default: `1`)

Secondary sorting by `id` is automatically appended when `sort_by != 'id'` to maintain deterministic pagination. Ownership restrictions remain strictly enforced for non-admin users.

### Order Idempotency (`POST /api/v1/orders`)
To prevent duplicate orders from network retries, clients can supply an optional header:
`Idempotency-Key: <unique-string>`

Behavior:
- **First request** (New Key): Processes the order, saves the key and a SHA-256 request payload hash, and returns HTTP 201 with header `Idempotency-Replayed: false`.
- **Identical retry** (Same User + Same Key + Same Request): Returns the existing order (HTTP 200) with header `Idempotency-Replayed: true` without deducting stock again.
- **Payload conflict** (Same User + Same Key + Different Request): Rejects the request with HTTP 409 Conflict.
- **Independent users**: Keys are scoped per user (`user_id`, `idempotency_key` unique index), so different users may use identical key strings without collision.
- **No header**: Order creation operates standard non-idempotent behavior.

---

## API Versioning

All API routes are prefixed with `/api/v1`.

Base URL:
`http://127.0.0.1:8000/api/v1`

---

## System Requirements

- **PHP**: `^8.3` with `pdo`, `mbstring`, `openssl`, `ctype`, `json`, and `sqlite3` extensions
- **Composer**: `2.x`
- **Database**: SQLite (default), MySQL 8.0+, or PostgreSQL 15+

---

## Installation & Setup

1. **Clone the repository**:
   ```bash
   git clone <repository-url>
   cd backend-task
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install
   ```

3. **Create Environment Configuration File**:
   - Windows PowerShell:
     ```powershell
     Copy-Item .env.example .env
     ```
   - Linux / macOS:
     ```bash
     cp .env.example .env
     ```

4. **Generate Application Key**:
   ```bash
   php artisan key:generate
   ```

5. **Configure `.env` Settings**:
   Ensure basic environment settings are configured:
   ```env
   APP_NAME="Backend Task Store API"
   APP_ENV=local
   APP_DEBUG=false
   APP_URL=http://127.0.0.1:8000

   DB_CONNECTION=sqlite
   QUEUE_CONNECTION=database
   MAIL_MAILER=log
   ```
   *Note: Setting `APP_DEBUG=false` ensures error responses return clean JSON messages without exposing internal stack traces or file paths.*

6. **Create SQLite Database File**:
   - Windows PowerShell:
     ```powershell
     New-Item -ItemType File -Force database\database.sqlite
     ```
   - Linux / macOS:
     ```bash
     touch database/database.sqlite
     ```

7. **Run Database Migrations & Deterministic Seeders**:
   ```bash
   php artisan migrate:fresh --seed
   ```
   > [!WARNING]
   > `migrate:fresh` drops all existing tables and recreates the database structure.

8. **Create Public Storage Link**:
   ```bash
   php artisan storage:link
   ```

9. **Start the Laravel Local Development Server**:
   ```bash
   php artisan serve
   ```

10. **Start Queue Worker** (in a separate terminal window):
    ```bash
    php artisan queue:work --queue=notifications,default --tries=3
    ```

11. **Check Queue Failures** (if needed):
    ```bash
    php artisan queue:failed
    ```

---

## Demo Seed Data

Running `php artisan migrate:fresh --seed` creates deterministic test records for local development and Postman testing:

### User Accounts
- **Admin User** (ID 1): Phone `+201000000001` | Password `password` | Role `admin`
- **Primary User** (ID 2): Phone `+201000000002` | Password `password` | Role `user`
- **Unverified User** (ID 3): Phone `+201000000003` | Password `password` | Role `user` (Unverified)
- **Secondary User** (ID 4): Phone `+201000000004` | Password `password` | Role `user`
- **Reset User** (ID 5): Phone `+201000000005` | Password `password` | Role `user`

### Seeded Products & Orders
- **20 Products** (IDs 1–20): Includes Product ID 1 (*Wireless Headphones*, Stock: 25) and Product ID 3 (*USB-C Hub*, Stock: 0 - out of stock).
- **6 Orders** (IDs 1–6): Includes Order ID 1 (*pending* status belonging to User ID 2). All 6 order statuses are represented.

*Note: Seeders do not generate active Sanctum tokens or pending queue jobs.*

---

## Phone Verification & Password Reset Logs

Phone verification codes and password reset codes are written to the application log file during development:
`storage/logs/laravel.log`

To watch logs in real time:
- **Windows PowerShell**:
  ```powershell
  Get-Content storage\logs\laravel.log -Tail 100 -Wait
  ```
- **Linux / macOS**:
  ```bash
  tail -f storage/logs/laravel.log
  ```

---

## Postman Collection

A Postman collection is located at:
`docs/postman/Backend_Task_Store_API.postman_collection.json`

### Import Instructions
1. Open Postman.
2. Click **Import** -> **Files** -> Select `docs/postman/Backend_Task_Store_API.postman_collection.json`.

### Folder Organization
- **Authentication**: `Account`, `Profile`, `Phone Verification`, `Password Reset`
- **Products**: `Catalog`, `Stock Notifications`
- **Orders**: `Customer Orders`
- **Admin**: `Products`, `Orders`

### Collection Variables
The collection contains variables (`base_url`, `user_phone`, `admin_phone`, `user_token`, `admin_token`, `unverified_token`, `product_id`, `out_of_stock_product_id`, `created_product_id`, `order_id`, `idempotency_key`, `idempotent_order_id`, `verification_code`, `reset_code`).

- Login requests automatically capture and set `user_token`, `admin_token`, and `unverified_token`.
- `Admin / Products / Create Product` automatically sets `created_product_id`.
- `Orders / Create Order` automatically sets `order_id` and `idempotent_order_id`.
- For `Create Product`, manually attach a local image file in the `form-data` body under key `image`.

---

## API Endpoint Summary

| Method | Endpoint | Access Level | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/auth/register` | Public | Register a new user account |
| `POST` | `/api/v1/auth/login` | Public | Authenticate user or admin and return Bearer token |
| `GET` | `/api/v1/user` | Authenticated | Retrieve authenticated user profile |
| `POST` | `/api/v1/auth/phone-verification/send` | Authenticated (Unverified) | Dispatch phone verification code to log |
| `POST` | `/api/v1/auth/phone-verification/verify` | Authenticated (Unverified) | Verify phone number using code |
| `POST` | `/api/v1/auth/password/forgot` | Public | Request password reset code |
| `POST` | `/api/v1/auth/password/reset` | Public | Reset account password |
| `GET` | `/api/v1/products` | Authenticated | List products with filtering, sorting, & pagination |
| `GET` | `/api/v1/products/{product}` | Authenticated | View single product details |
| `POST` | `/api/v1/products/{product}/stock-notification-requests` | Authenticated | Subscribe to back-in-stock notification |
| `GET` | `/api/v1/orders` | Owner / Admin | List user orders (or all orders for Admin) |
| `POST` | `/api/v1/orders` | Authenticated | Create a new order (supports Idempotency-Key) |
| `GET` | `/api/v1/orders/{order}` | Owner / Admin | View order details |
| `POST` | `/api/v1/admin/products` | Admin | Create a new product with image |
| `PATCH` | `/api/v1/admin/products/{product}` | Admin | Update product fields (PATCH) |
| `PUT` | `/api/v1/admin/products/{product}` | Admin | Replace product fields (PUT) |
| `DELETE` | `/api/v1/admin/products/{product}` | Admin | Delete a product |
| `PATCH` | `/api/v1/admin/orders/{order}/status` | Admin | Update order status and log history |

---

## Error Handling Strategy

The API returns consistent HTTP status codes and structured JSON responses:

- **HTTP 401 Unauthorized**: Missing or invalid authentication token.
- **HTTP 403 Forbidden**: Insufficient role permissions or accessing resources owned by another user.
- **HTTP 404 Not Found**: Scoped JSON responses:
  - Missing product: `{"message": "Product not found."}`
  - Missing order: `{"message": "Order not found."}`
  - Missing general resource: `{"message": "Resource not found."}`
  - Invalid route: `{"message": "Endpoint not found."}`
- **HTTP 409 Conflict**: Business rule violations (insufficient stock, idempotency key mismatch, invalid status transition, stock alerts on in-stock items).
- **HTTP 422 Unprocessable Content**: Form validation failures returning field-specific error messages.

---

## Automated Testing

Run the full automated test suite:
```bash
php artisan test
```

Run specific test classes:
```bash
php artisan test --filter=RegisterTest
php artisan test --filter=ProductManagementTest
php artisan test --filter=ProductListingQueryTest
php artisan test --filter=OrderCreationTest
php artisan test --filter=OrderIdempotencyTest
php artisan test --filter=OrderListingQueryTest
php artisan test --filter=OrderStatusManagementTest
php artisan test --filter=Notification
```

### Test Suite Summary
- **Verified Result**: `200 passed, 1 skipped (1064 assertions)`
- **Concurrency Test Note**: `OrderCreationConcurrencyTest` is skipped on SQLite environments because SQLite file-locking does not support row-level `lockForUpdate()` concurrency testing. To execute the concurrency test, configure a MySQL or PostgreSQL connection and run:
  ```bash
  php artisan test --filter=OrderCreationConcurrencyTest
  ```

---

## Architecture & Design Patterns

- **Form Requests**: Encapsulate input validation and authorization logic (`RegisterRequest`, `LoginRequest`, `ListProductsRequest`, `StoreOrderRequest`, `ListOrdersRequest`, `UpdateOrderStatusRequest`).
- **Eloquent Scopes**: Query filtering and sorting logic defined on `Product` and `Order` models (`scopeFilter`, `scopeSortByOptions`, `scopeVisibleTo`).
- **API Resources & Collections**: Format clean JSON responses (`UserResource`, `ProductResource`, `ProductCollection`, `OrderResource`, `OrderCollection`).
- **Domain Services**: Business logic isolated in `OrderService` (atomic order creation) and `OrderStatusService` (status state transitions and history logging).
- **Model Observers**: `ProductObserver` handles stock increase detection to fire restock events automatically.
- **Events & Listeners**: Decoupled asynchronous tasks (`SendProductBackInStockNotifications`, `SendOrderStatusChangedNotification`, `SendNewProductNotification`).
- **Idempotency Protection**: Custom exception detection and request hashing (`OrderIdempotencyDuplicateKeyDetector`) backed by a composite database unique index (`user_id`, `idempotency_key`).

---

## Useful Command Reference

```bash
# Reset database and apply demo seed data
php artisan migrate:fresh --seed

# Link public storage directory
php artisan storage:link

# Start local server
php artisan serve

# Run background queue worker
php artisan queue:work --queue=notifications,default --tries=3

# View failed queue jobs
php artisan queue:failed

# List all registered v1 API routes
php artisan route:list --path=api/v1

# Run automated tests
php artisan test

# Clear application cache
php artisan config:clear
```
