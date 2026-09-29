# Hotel & Bar Unified Management Platform
## Complete System Analysis, PRD, SRD/SRS, Architecture, Database Blueprint and Master AI Build Prompt

**Document Version:** 1.0  
**Status:** Baseline Product Specification  
**Date:** 28 September 2026  
**Target Market:** Nigeria / NGN  
**Architecture:** Next.js + Laravel API + PostgreSQL  
**Deployment:** Railway  
**Source Control:** Git + GitHub  
**CI/CD:** GitHub Actions  

---

# 1. Executive Summary

The proposed product is a unified hotel and bar management platform for a hotel business that operates accommodation services and a bar.

The platform combines:

1. A futuristic public hotel website.
2. Online hotel room discovery and reservation.
3. Guest accounts and reservation management.
4. Deposit and full-payment workflows.
5. Hotel check-in/check-out.
6. Unified guest billing.
7. Hotel-service ordering and charging.
8. Bar/table management.
9. Tablet/mobile waiter ordering.
10. Bartender order processing.
11. Customer bill notifications and online payment.
12. Cash/card/manual settlement.
13. Staff management.
14. Flexible multi-role RBAC.
15. Staff shift scheduling.
16. Finance and payment records.
17. Inventory-ready bar architecture.
18. Reports and dashboards.
19. Audit trails.
20. Email and in-app notifications.
21. Automated testing and CI/CD.
22. Production deployment on Railway.

The hotel and bar are not separate applications. They are two operational domains inside one platform and share identity, billing, payments, finance, staff, notifications and audit infrastructure.

A critical business capability is the ability to associate bar/service charges with a hotel guest's room. A guest may either pay immediately or charge an eligible transaction to the room and settle the consolidated hotel bill later.

---

# 2. Product Vision

Build a modern, reliable hospitality operating system that allows the business to manage:

- rooms,
- reservations,
- guests,
- check-ins,
- check-outs,
- hotel services,
- bar orders,
- staff,
- shifts,
- bills,
- payments,
- financial records,
- inventory,
- notifications,
- reports,

from a single system.

The public-facing experience should feel premium and futuristic, while staff interfaces should prioritize speed, clarity, touch interaction and operational efficiency.

---

# 3. Recommended Technology Stack

## Backend

- Laravel 13
- PHP version compatible with Laravel 13
- PostgreSQL
- Laravel Sanctum
- Spatie Laravel Permission
- Laravel Notifications
- Laravel Queues
- Laravel Scheduler
- Laravel Events/Listeners
- Laravel API Resources
- Form Requests
- Policies
- Service layer
- PHPUnit/Pest as selected by project standards
- Optional Redis for queues/cache/realtime infrastructure

Laravel's official documentation supports Sanctum for SPA authentication and API tokens, while queues provide a unified background-job API. Laravel scheduling keeps recurring jobs in source control. 

## Frontend

- Next.js
- TypeScript
- App Router
- Responsive UI
- Server Components where appropriate
- Client Components for interactive workflows
- React Hook Form or equivalent
- Zod or equivalent validation
- TanStack Query or equivalent server-state management where appropriate
- Tailwind CSS or a similarly maintainable design system
- Accessible component library
- Charts for dashboards

## Database

- PostgreSQL
- UUID primary keys where appropriate
- Foreign keys
- Composite indexes
- Check constraints
- Unique constraints
- Decimal/numeric money columns
- Transactional financial operations

## Infrastructure

- Railway
- PostgreSQL service
- Laravel API service
- Laravel queue worker
- Laravel scheduler
- Next.js frontend
- Redis when enabled
- Object storage for media
- External payment gateway
- Email provider

Railway supports GitHub-based deployments, PostgreSQL, Redis, deployment health controls, pre-deploy commands and automatic deployments. 

---

# 4. High-Level Architecture

```text
                         INTERNET
                             |
             +---------------+---------------+
             |                               |
       Public Website                  Staff Interfaces
       Customer Portal                 Manager Dashboard
             |                         Reception
             |                         Waiter/POS
             |                         Bartender
             |                         Finance
             +---------------+---------------+
                             |
                        Next.js App
                             |
                         HTTPS / JSON
                             |
                    Laravel REST API
                             |
       +---------------------+----------------------+
       |                     |                      |
 Authentication          Domain Services       Integrations
       |                     |                      |
 Sanctum/RBAC        Hotel / Bar / Finance   Payments / Email
                     Reservations / Billing   Storage / Realtime
       |                     |
       +---------- PostgreSQL ---------------+
                             |
                     Queue / Scheduler
                             |
                  Notifications / Jobs
```

---

# 5. Architectural Principle: One System, Separate Domains

The system should not be implemented as unrelated hotel and bar applications.

Recommended domains:

```text
Identity & Access
Property Management
Hotel Operations
Reservations
Guests
Billing
Payments
Bar/POS
Inventory
Staff & Scheduling
Finance
Notifications
Reporting
Audit
System Configuration
```

Domains may have separate Laravel modules/services, but they share core infrastructure.

---

# 6. User Types

The system supports users with multiple roles.

A user does NOT have to have exactly one role.

Example:

```text
John Doe

Roles:
- Waiter
- Bartender
- Manager

Permissions:
- orders.create
- orders.view
- orders.prepare
- reports.view
- staff.manage
```

Recommended initial roles:

1. Super Administrator
2. Administrator
3. Hotel Manager
4. Receptionist
5. Accountant
6. Waiter
7. Service Agent
8. Bartender
9. Inventory Manager
10. Auditor
11. Customer/Guest

Roles are configurable.

Permissions are the real authorization mechanism.

---

# 7. Stakeholders

## Business Owner

Needs:

- overall visibility,
- financial information,
- operational reporting,
- business performance,
- staff accountability.

## Hotel Manager

Needs:

- reservations,
- room status,
- guest management,
- staff management,
- shifts,
- financial records,
- operational reports.

## Receptionist

Needs:

- reservations,
- guest records,
- room allocation,
- check-in,
- check-out,
- guest bills,
- payments.

## Waiter/Service Agent

Needs:

- tables,
- customer information,
- product menu,
- orders,
- bills,
- payment status,
- order delivery status.

## Bartender

Needs:

- incoming orders,
- preparation queue,
- item availability,
- preparation status,
- completed orders.

## Accountant

Needs:

- payments,
- refunds,
- revenue,
- expenses,
- outstanding bills,
- financial reports.

## Customer/Guest

Needs:

- room browsing,
- reservations,
- payments,
- receipts,
- hotel services,
- bar orders,
- bills,
- account history.

---

# 8. Public Website

The public-facing website should be visually premium and futuristic without sacrificing usability.

## Proposed Pages

```text
/
 /rooms
 /rooms/[slug]
 /amenities
 /services
 /bar
 /gallery
 /about
 /contact
 /location
 /reservation
 /reservation/confirmation
 /login
 /register
```

## Visual Direction

Use:

- premium hotel photography,
- large editorial typography,
- glass-like surfaces used selectively,
- subtle gradients,
- sophisticated dark/light theme,
- elegant cards,
- animated transitions,
- subtle parallax where useful,
- micro-interactions,
- responsive layout,
- mobile-first implementation,
- accessible contrast,
- fast-loading imagery.

Avoid excessive animation that interferes with reservation workflows.

---

# 9. Customer Journey

## Hotel Reservation

```text
Visitor
  |
  v
Browse Rooms
  |
  v
Select Dates
  |
  v
Check Availability
  |
  v
Select Room
  |
  v
Enter Guest Information
  |
  v
Choose Deposit or Full Payment
  |
  v
Payment
  |
  v
Reservation Confirmed
  |
  v
Email Confirmation
  |
  v
Customer Portal
```

---

# 10. Reservation Business Rules

1. A room cannot have overlapping confirmed occupancy periods.
2. Pending reservations may have an expiry time.
3. Confirmed reservations require the configured deposit or full payment.
4. A reservation may contain multiple rooms.
5. A reservation may contain multiple guests.
6. A guest may reserve on behalf of another guest.
7. Room availability considers:
   - reservations,
   - allocated rooms,
   - checked-in occupancy,
   - blocked rooms,
   - maintenance,
   - out-of-service periods.
8. Reservation prices are snapshotted at booking time.
9. Later changes to room pricing do not alter historical reservation charges unless an authorized adjustment is made.
10. Cancellation and refund rules must be configurable.
11. Every reservation status change is auditable.

---

# 11. Reservation Statuses

Recommended:

```text
DRAFT
PENDING_PAYMENT
CONFIRMED
PARTIALLY_PAID
CHECK_IN_PENDING
CHECKED_IN
CHECKED_OUT
CANCELLED
EXPIRED
NO_SHOW
```

---

# 12. Room Model

Room Types:

```text
Standard
Deluxe
Executive
Suite
Presidential
```

These are examples and should be configurable.

Individual rooms belong to a room type.

Example:

```text
Deluxe
  |
  +-- 201
  +-- 202
  +-- 203
```

## Room Fields

- room ID
- room number
- room type
- floor
- status
- base rate
- capacity
- amenities
- description
- images
- maintenance notes

## Room Statuses

```text
AVAILABLE
RESERVED
OCCUPIED
DIRTY
CLEANING
MAINTENANCE
OUT_OF_SERVICE
BLOCKED
```

---

# 13. Dynamic Availability Engine

Availability should be calculated by date/time and room constraints.

Conceptually:

```text
Available Rooms =
Rooms
- Rooms with overlapping confirmed reservations
- Occupied rooms
- Maintenance rooms
- Blocked rooms
- Out-of-service rooms
```

The availability service must use database transactions/locking where necessary to prevent race conditions.

A customer selecting a room does not permanently reserve it until the reservation is committed.

---

# 14. Hotel Payment Model

The reservation supports:

```text
Pay Deposit
Pay Full Amount
```

Recommended default deposit:

```text
30%
```

The manager can configure this.

Example:

```text
Accommodation: ₦200,000

Deposit required: 30%
Deposit: ₦60,000

Customer pays ₦60,000

Outstanding accommodation balance:
₦140,000
```

Additional partial payments are supported.

---

# 15. Payment Rules

Every payment should contain:

- payment reference,
- amount,
- currency,
- payment method,
- gateway,
- gateway transaction reference,
- status,
- payer,
- related bill/reservation,
- timestamps,
- metadata.

Statuses:

```text
PENDING
PROCESSING
SUCCESS
FAILED
CANCELLED
REFUNDED
PARTIALLY_REFUNDED
```

Never treat a browser redirect as proof of payment.

Payment confirmation must rely on verified gateway response/webhook plus reconciliation logic.

---

# 16. Payment Gateway Abstraction

Use:

```text
PaymentGatewayInterface
```

Implementations:

```text
PaystackGateway
FlutterwaveGateway
ManualPaymentGateway
```

Possible future:

```text
MoniepointGateway
OtherGateway
```

This prevents the business domain from depending directly on a specific provider.

---

# 17. Receipt Rules

If full accommodation payment is made:

- generate receipt,
- assign receipt number,
- save immutable receipt snapshot,
- email receipt,
- allow PDF download,
- allow printing.

Partial payments also generate payment receipts.

A receipt should never silently change after issuance.

Corrections should use reversal/refund/adjustment records.

---

# 18. Check-In

Receptionist:

1. Searches reservation.
2. Confirms guest identity.
3. Confirms reservation.
4. Confirms required payment condition.
5. Assigns/validates room.
6. Records check-in.
7. Updates room to OCCUPIED.
8. Sends confirmation.
9. Enables guest stay/service charging.

---

# 19. Check-Out

At checkout:

```text
Accommodation
+ Extra Nights
+ Hotel Services
+ Bar Charges
+ Other Charges
- Payments
= Outstanding Balance
```

If balance > 0:

```text
Payment Required
```

If balance = 0:

```text
Checkout Allowed
```

After successful checkout:

```text
Reservation -> CHECKED_OUT
Room -> DIRTY/CLEANING
Guest Stay -> CLOSED
Final Receipt -> Generated
```

Late checkout fees are configurable.

---

# 20. Unified Hotel Billing

The central billing concept is a guest/account ledger.

Example:

```text
Guest: John Doe
Room: 205

Accommodation             ₦150,000
Laundry                     ₦5,000
Room Service                ₦8,500
Bar                          ₦12,000
Extra Bed                    ₦5,000
--------------------------------
Gross Bill                 ₦180,500

Payments:
Deposit                     ₦45,000
Second Payment              ₦50,000
--------------------------------
Paid                        ₦95,000

Outstanding                 ₦85,500
```

The system must distinguish:

- bill,
- bill item,
- payment,
- payment allocation,
- refund,
- financial transaction.

---

# 21. Hotel Services

Services are configurable.

Examples:

```text
Laundry
Room Service
Restaurant
Spa
Airport Transfer
Extra Bed
Conference Room
Other
```

Each service can contain:

- name,
- category,
- description,
- price,
- tax,
- service charge,
- active/inactive state.

Authorized staff can add services to a guest bill.

---

# 22. Bar/POS Workflow

```text
Customer arrives
      |
      v
Waiter/Service Agent
      |
      v
Select Table
      |
      v
Enter Customer Phone/Email
      |
      v
Select Products
      |
      v
Create Order
      |
      v
Order Sent to Bartender
      |
      v
Bartender Accepts
      |
      v
Preparing
      |
      v
Ready
      |
      v
Waiter Delivers
      |
      v
Bill
      |
      +------> Pay Now
      |
      +------> Charge to Hotel Room
```

---

# 23. Bar Tables

Table fields:

- table ID
- table number/name
- capacity
- location
- status
- active flag

Statuses:

```text
AVAILABLE
OCCUPIED
RESERVED
CLEANING
BLOCKED
```

A customer may have multiple orders during the same visit.

---

# 24. Bar Orders

An order includes:

- order number,
- table,
- customer,
- waiter/service agent,
- hotel guest link if applicable,
- order items,
- subtotal,
- tax,
- service charge,
- discount,
- total,
- payment status,
- order status.

Order statuses:

```text
DRAFT
PLACED
ACCEPTED
PREPARING
READY
DELIVERED
COMPLETED
CANCELLED
```

---

# 25. Bar Order Interface

## Waiter Interface

Optimize for touch:

```text
Tables
Products
Categories
Current Order
Quantity Controls
Notes
Customer
Bill
Payment
Order History
```

Large touch targets should be used.

## Bartender Interface

Use a live queue:

```text
NEW
ACCEPTED
PREPARING
READY
```

Orders should be sortable by priority/time.

---

# 26. Customer Bill Notification

After order placement, the customer receives an email containing:

- hotel/bar identity,
- order number,
- table,
- items,
- quantities,
- prices,
- subtotal,
- discount,
- tax,
- service charge,
- total,
- outstanding amount,
- payment link.

The payment link must point to a secure server-created payment transaction.

---

# 27. Bar Payment Options

```text
ONLINE
CASH
CARD/POS
BANK_TRANSFER
```

Online payment requires gateway verification.

Manual payments require an authorized staff member.

Financial records must capture the staff user who recorded manual payment.

---

# 28. Hotel/Bar Integration

A bar order may be:

```text
PAY_NOW
```

or:

```text
CHARGE_TO_ROOM
```

For charge-to-room:

```text
Bar Order
   |
   v
Guest Stay Account
   |
   v
Hotel Bill
   |
   v
Final Settlement
```

Only active eligible hotel stays may receive room charges.

Permission checks must prevent unauthorized staff from charging arbitrary rooms.

---

# 29. Inventory Architecture

Inventory should be included in the architecture and can be activated in MVP if operationally required.

Core entities:

```text
products
product_categories
inventory_items
stock_movements
suppliers
purchases
purchase_items
recipes
recipe_items
```

A bar sale may reduce inventory.

For recipe-enabled products:

```text
Mojito
  |
  +-- Rum 50ml
  +-- Lime 30ml
  +-- Sugar 10g
  +-- Mint 5g
```

Inventory deductions should occur transactionally when the order reaches the defined fulfillment/sale state.

---

# 30. Staff Management

Staff records should contain:

- user,
- employee number,
- department,
- position,
- employment status,
- contact information,
- profile photo,
- start date,
- notes.

Users may have multiple roles.

Example:

```text
Employee:
John Doe

Roles:
Waiter
Bartender
Manager
```

---

# 31. Staff Scheduling

Shifts are configurable.

Example:

```text
Morning: 07:00 - 15:00
Afternoon: 15:00 - 23:00
Night: 23:00 - 07:00
```

But managers can create arbitrary shifts.

Shift assignment:

```text
Staff
+
Date
+
Shift
+
Department
+
Location
+
Status
```

Notifications are sent when shifts are:

- assigned,
- changed,
- cancelled.

Attendance is supported:

```text
CLOCKED_IN
CLOCKED_OUT
LATE
ABSENT
```

---

# 32. RBAC

Use permission-driven authorization.

Example permissions:

```text
users.view
users.create
users.update
users.delete

roles.view
roles.create
roles.update
roles.assign

rooms.view
rooms.create
rooms.update
rooms.manage_status

reservations.view
reservations.create
reservations.update
reservations.cancel

checkins.create
checkouts.create

bar.orders.create
bar.orders.view
bar.orders.update
bar.orders.prepare
bar.orders.deliver
bar.orders.cancel

payments.view
payments.create
payments.refund

finance.view
finance.reports
finance.expenses

staff.view
staff.create
staff.update
staff.schedule

inventory.view
inventory.manage

reports.view
reports.export

audit.view
```

---

# 33. Permission Principle

The frontend should hide unavailable functions for usability, but frontend visibility is NOT security.

Laravel must enforce authorization server-side through policies/middleware/permission checks.

Every sensitive operation must be protected at the API.

---

# 34. Finance Module

Finance dashboard:

```text
Today's Revenue
Hotel Revenue
Bar Revenue
Service Revenue
Outstanding Bills
Payments
Refunds
Cash
Card/POS
Online
Bank Transfer
Expenses
Net Position
```

Reports:

```text
Revenue Report
Payment Report
Outstanding Bills
Reservation Revenue
Bar Sales
Service Revenue
Refund Report
Expense Report
Daily Closing
Monthly Summary
```

All reports support date ranges.

Exports:

```text
PDF
CSV
Excel
```

---

# 35. Financial Integrity Rules

1. Money uses fixed precision numeric/decimal types.
2. Never use floating-point arithmetic for money.
3. Financial transactions are immutable.
4. Corrections use adjustment/reversal records.
5. Payment allocation is separate from payment.
6. Refunds reference original payments.
7. Every financial mutation is auditable.
8. Financial operations use database transactions.
9. Idempotency keys protect payment/webhook operations.
10. Duplicate gateway callbacks must not create duplicate payments.

---

# 36. Discounts

Authorized users may apply:

```text
Percentage discount
Fixed discount
Promotional discount
Coupon
```

Separate permissions:

```text
discounts.apply
discounts.approve
```

Discount history must be auditable.

---

# 37. Tax

The system supports configurable:

```text
VAT
Service Charge
Other Taxes
```

Tax configuration should be property/service/product aware.

Each invoice/bill stores the calculated values used at transaction time.

---

# 38. Notification System

Channels:

- Email
- In-app

Future-ready:

- SMS
- WhatsApp

Notifications include:

### Customer

- reservation confirmation,
- payment confirmation,
- receipt,
- check-in,
- checkout,
- booking reminder,
- bill update,
- outstanding balance,
- bar order,
- payment link,
- bar payment,
- order status.

### Staff

- account created,
- login details,
- shift assigned,
- shift changed,
- shift cancelled.

---

# 39. Authentication

## Customer

Support:

- email/password,
- phone/OTP where enabled,
- account registration,
- password reset.

## Staff

Use:

- email/password,
- temporary password,
- mandatory password change after first login.

## Administrative Security

2FA should be supported for privileged accounts.

---

# 40. Audit Logging

Audit:

```text
Authentication
Role changes
Permission changes
Reservation changes
Room status changes
Room allocation
Check-in
Checkout
Bill modifications
Payment creation
Payment status changes
Refunds
Discounts
Manual payment entry
Staff changes
Shift changes
Inventory changes
Financial adjustments
```

Audit record:

```text
actor
action
entity
entity_id
old_values
new_values
IP address
user agent
timestamp
```

Sensitive credentials must never be stored in audit logs.

---

# 41. Database Design

The following is the logical baseline. Exact migration ordering should be finalized during implementation.

## Identity

```text
users
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions
personal_access_tokens
```

## Property

```text
properties
departments
settings
```

## Staff

```text
employees
shifts
shift_assignments
attendance_records
```

## Hotel

```text
room_types
rooms
room_amenities
amenities
room_images
guests
guest_documents
reservations
reservation_rooms
reservation_guests
room_allocations
stays
check_ins
check_outs
```

## Services

```text
service_categories
hotel_services
service_orders
service_order_items
```

## Bar

```text
bar_tables
bar_table_sessions
product_categories
products
bar_orders
bar_order_items
```

## Inventory

```text
inventory_items
stock_movements
suppliers
purchases
purchase_items
recipes
recipe_items
```

## Billing

```text
bills
bill_items
bill_adjustments
discounts
taxes
```

## Payments

```text
payments
payment_allocations
payment_transactions
refunds
refund_items
```

## Finance

```text
financial_accounts
financial_transactions
expenses
expense_categories
```

## Notifications

```text
notifications
notification_preferences
```

## Audit

```text
audit_logs
```

---

# 42. Important Relationships

```text
User
 ├── Employee
 ├── Roles
 └── Audit Logs

Room Type
 └── Rooms

Guest
 ├── Reservations
 └── Stays

Reservation
 ├── Reservation Rooms
 ├── Reservation Guests
 ├── Payments
 └── Stay

Stay
 ├── Room Allocation
 ├── Service Orders
 ├── Bar Orders
 └── Bill

Bar Table
 └── Bar Orders

Bar Order
 └── Bar Order Items

Bill
 ├── Bill Items
 ├── Payment Allocations
 └── Adjustments

Payment
 └── Payment Allocations

Payment
 └── Refunds
```

---

# 43. Money Model

Recommended:

```text
numeric(14,2)
```

for NGN values.

All records should explicitly store currency where useful:

```text
currency = NGN
```

Do not use JavaScript floating-point arithmetic for final financial calculations.

---

# 44. API Standards

Base:

```text
/api/v1
```

Example groups:

```text
/api/v1/auth
/api/v1/users
/api/v1/roles
/api/v1/permissions

/api/v1/rooms
/api/v1/room-types
/api/v1/reservations
/api/v1/guests
/api/v1/check-ins
/api/v1/check-outs

/api/v1/services
/api/v1/bills
/api/v1/payments
/api/v1/refunds

/api/v1/bar/tables
/api/v1/bar/products
/api/v1/bar/orders

/api/v1/staff
/api/v1/shifts
/api/v1/attendance

/api/v1/inventory
/api/v1/finance
/api/v1/reports
/api/v1/audit
```

---

# 45. Standard API Response

Success:

```json
{
  "success": true,
  "message": "Reservation created successfully.",
  "data": {},
  "meta": {}
}
```

Validation failure:

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

Business-rule failure:

```json
{
  "success": false,
  "message": "The selected room is no longer available."
}
```

Never expose stack traces in production.

---

# 46. Next.js Structure

Recommended:

```text
src/
  app/
    (public)/
      page.tsx
      rooms/
      reservation/
      contact/

    (customer)/
      dashboard/
      reservations/
      bills/
      payments/
      profile/

    (staff)/
      dashboard/
      reservations/
      rooms/
      guests/
      bar/
      bills/
      payments/
      staff/
      finance/
      reports/
      settings/

  components/
    ui/
    forms/
    tables/
    charts/
    modals/
    navigation/

  features/
    auth/
    rooms/
    reservations/
    guests/
    billing/
    payments/
    bar/
    staff/
    finance/
    inventory/

  lib/
    api/
    auth/
    permissions/
    validation/
    formatting/

  hooks/
  types/
  services/
```

---

# 47. Laravel Structure

Recommended domain-oriented structure:

```text
app/
  Domain/
    Identity/
    Property/
    Hotel/
    Reservations/
    Guests/
    Billing/
    Payments/
    Bar/
    Inventory/
    Staff/
    Finance/
    Notifications/
    Reporting/
    Audit/

  Http/
    Controllers/
    Requests/
    Resources/

  Jobs/
  Events/
  Listeners/
  Notifications/
  Policies/
  Rules/
  Services/
```

Avoid putting all business logic inside controllers.

Controllers should coordinate requests and return responses.

---

# 48. Core Services

Examples:

```text
RoomAvailabilityService
ReservationService
ReservationPricingService
RoomAllocationService
CheckInService
CheckOutService
BillingService
PaymentService
PaymentReconciliationService
ReceiptService
BarOrderService
BarOrderFulfillmentService
InventoryService
ShiftService
FinanceReportingService
NotificationService
AuditService
```

---

# 49. Events

Examples:

```text
ReservationCreated
ReservationConfirmed
PaymentSucceeded
PaymentFailed
GuestCheckedIn
GuestCheckedOut
BarOrderPlaced
BarOrderReady
BarOrderDelivered
BillUpdated
ShiftAssigned
ShiftChanged
```

Events can trigger notifications and asynchronous processing.

---

# 50. Queue Jobs

Examples:

```text
SendReservationConfirmation
SendPaymentReceipt
SendBillNotification
SendShiftNotification
ProcessPaymentWebhook
GenerateFinancialReport
ProcessImage
SendReminder
```

Long-running or non-critical user-response work should be queued.

Laravel queues support database, Redis and other backends, making them appropriate for these background operations.

---

# 51. Scheduled Jobs

Examples:

```text
ExpirePendingReservations
SendReservationReminders
MarkNoShows
SendOutstandingBillReminders
GenerateDailySummary
PruneOldTokens
CleanTemporaryFiles
QueueMaintenance
```

Use Laravel Scheduler.

---

# 52. Realtime Requirements

Realtime is recommended for:

- new bar orders,
- bartender status,
- order ready notifications,
- waiter updates,
- dashboard metrics where valuable.

Initial architecture:

```text
Laravel Events
    |
Broadcast
    |
Realtime Provider/WebSocket Layer
    |
Next.js Client
```

Do not make the system dependent on realtime for financial correctness.

Polling/fallback can be used where appropriate.

---

# 53. Security Requirements

Implement:

- HTTPS,
- secure cookies/tokens,
- CSRF protection where applicable,
- CORS restriction,
- rate limiting,
- password hashing,
- strong password rules,
- 2FA for privileged users,
- authorization policies,
- input validation,
- output escaping,
- SQL injection protection,
- file upload validation,
- MIME validation,
- image processing,
- webhook signature verification,
- idempotency,
- audit logs,
- secure secrets,
- no secrets committed to Git.

---

# 54. Privacy

Guest information and ID documents are sensitive.

Implement:

- least-privilege access,
- encrypted transport,
- restricted document access,
- audit logs,
- retention rules,
- controlled exports,
- secure deletion/anonymization where legally appropriate.

---

# 55. File Storage

Do not rely on ephemeral application storage for production media.

Use object storage for:

- hotel images,
- room images,
- product images,
- guest documents,
- receipts,
- reports.

The storage provider should be abstracted behind a file/media service.

---

# 56. Search

Search should be supported for:

Hotel:

```text
Guest name
Phone
Email
Reservation number
Room number
```

Bar:

```text
Order number
Customer phone
Customer email
Table
Product
```

Staff:

```text
Name
Email
Employee number
Role
Department
```

---

# 57. Dashboard Design

## Manager

```text
Revenue
Occupancy
Reservations
Check-ins
Check-outs
Available Rooms
Occupied Rooms
Maintenance
Bar Orders
Staff on Duty
Outstanding Bills
```

## Receptionist

```text
Today's Arrivals
Today's Departures
Current Guests
Available Rooms
Pending Reservations
Outstanding Guest Balances
```

## Waiter

```text
My Tables
Open Orders
Pending Payments
Ready Orders
Recent Orders
```

## Bartender

```text
New Orders
Preparing
Ready
Completed
```

## Accountant

```text
Revenue
Payments
Refunds
Outstanding
Expenses
Daily Closing
```

---

# 58. MVP Scope

## MVP 1 — Foundation

- repositories
- Laravel
- Next.js
- PostgreSQL
- authentication
- RBAC
- CI
- development environments

## MVP 2 — Hotel

- property configuration
- rooms
- room types
- amenities
- guests
- reservations
- availability
- deposits
- full payment
- receipts
- check-in
- check-out

## MVP 3 — Billing

- guest ledger
- hotel services
- consolidated bill
- partial payments
- receipts
- online/manual payments

## MVP 4 — Bar

- products
- categories
- tables
- waiter ordering
- bartender workflow
- customer bill
- payment
- room charging

## MVP 5 — Staff

- employees
- roles
- permissions
- shifts
- notifications

## MVP 6 — Finance

- payment records
- revenue
- expenses
- reports
- exports

## MVP 7 — Production

- audit
- queues
- scheduler
- monitoring
- backups
- security hardening
- Railway deployment

---

# 59. Phase 2

Potential enhancements:

- advanced inventory,
- recipe management,
- supplier management,
- procurement,
- loyalty,
- promotions,
- advanced dynamic pricing,
- SMS,
- WhatsApp,
- advanced analytics,
- multi-property support,
- housekeeping workflows,
- maintenance tickets,
- accounting integration,
- mobile apps.

---

# 60. Non-Functional Requirements

## Performance

Public pages should load quickly on typical Nigerian mobile networks.

API targets:

- ordinary reads: preferably < 500ms under normal load,
- ordinary writes: preferably < 800ms,
- payment initiation: dependent on gateway,
- reports: asynchronous for heavy reports.

## Availability

Production should include:

- health checks,
- queue worker monitoring,
- database backups,
- error monitoring,
- structured logs.

## Scalability

Architecture should support:

- more rooms,
- more staff,
- more orders,
- more concurrent customers,
- additional properties later.

---

# 61. Testing Strategy

## Backend

- unit tests,
- feature/API tests,
- authorization tests,
- payment tests,
- reservation conflict tests,
- billing tests,
- webhook tests,
- database transaction tests.

## Frontend

- component tests,
- form validation tests,
- route/permission tests,
- critical workflow tests.

## E2E

Test:

```text
Reservation
Payment
Check-in
Service charge
Bar order
Room charge
Checkout
Final settlement
```

---

# 62. Critical Automated Tests

At minimum:

1. Cannot reserve an unavailable room.
2. Cannot create overlapping confirmed reservations.
3. Deposit calculation is correct.
4. Full payment closes accommodation balance.
5. Partial payments update balance correctly.
6. Duplicate webhook cannot duplicate payment.
7. Refund cannot exceed refundable amount.
8. Check-in respects reservation/payment rules.
9. Checkout cannot complete with unpaid required balance.
10. Bar order totals are correct.
11. Bar order can be charged to room.
12. Only active stays can receive room charges.
13. Unauthorized users cannot access financial data.
14. Multiple roles work correctly.
15. Permission removal immediately restricts access.
16. Staff cannot modify records outside their permissions.
17. Audit logs are created for sensitive mutations.

---

# 63. CI/CD

Two repositories:

```text
hotel-platform-backend
hotel-platform-frontend
```

## Backend Pipeline

```text
Push/PR
  |
Composer install
  |
Lint/static checks
  |
PHP tests
  |
Database migration test
  |
Security checks
  |
Build
```

## Frontend Pipeline

```text
Push/PR
  |
npm ci
  |
Lint
  |
Type check
  |
Unit tests
  |
Build
```

## Production

```text
main
 |
GitHub Actions
 |
Tests
 |
Deploy Railway
 |
Health check
 |
Complete
```

Railway supports GitHub deployment workflows and pre-deployment commands, health checks and deployment controls.

---

# 64. Railway Production Topology

Recommended:

```text
Railway Project
|
+-- PostgreSQL
|
+-- Laravel API
|
+-- Laravel Worker
|
+-- Laravel Scheduler
|
+-- Next.js
|
+-- Redis (recommended)
```

Environment variables should be configured per environment.

Never commit:

```text
.env
payment secrets
API keys
database passwords
SMTP passwords
private keys
```

---

# 65. Environments

Recommended:

```text
local
testing
staging
production
```

At minimum:

```text
local
staging
production
```

Use separate databases for staging and production.

---

# 66. Git Branching

Recommended:

```text
main
develop
feature/*
fix/*
hotfix/*
```

Pull requests required for protected branches.

Require:

- passing CI,
- code review where applicable,
- no failing tests.

---

# 67. Definition of Done

A module is complete only when:

- database migrations exist,
- models exist,
- validation exists,
- authorization exists,
- services exist,
- API endpoints exist,
- API tests exist,
- frontend screens exist,
- frontend validation exists,
- loading/error states exist,
- empty states exist,
- audit behavior exists where required,
- notifications exist where required,
- documentation is updated,
- CI passes.

---

# 68. Master AI Coding-Agent Prompt

Use the following as the master prompt for Claude Code, Codex, Cursor, Trae, Gemini CLI or another capable coding agent.

---

## MASTER PROMPT

You are the Lead Software Architect, Senior Laravel Engineer, Senior Next.js Engineer, Database Architect, QA Engineer and DevOps Engineer responsible for implementing a production-grade Hotel and Bar Unified Management Platform.

### Mission

Build the system described in this specification incrementally from repository initialization to production deployment.

Do not attempt to generate the entire application in one step.

Work module-by-module.

Never skip architecture, migrations, validation, authorization, tests or documentation.

---

## Technology

Backend:

- Laravel 13
- PHP
- PostgreSQL
- Laravel Sanctum
- Spatie Laravel Permission
- Laravel Queues
- Laravel Scheduler
- Laravel Events
- Laravel Notifications

Frontend:

- Next.js
- TypeScript
- App Router
- responsive UI
- accessible component system

Infrastructure:

- Git
- GitHub
- GitHub Actions
- Railway
- PostgreSQL
- Redis where required
- object storage

---

## Repository Strategy

Create two repositories:

```text
hotel-platform-backend
hotel-platform-frontend
```

Do not create a monorepo unless explicitly instructed.

---

## Core Architecture Rules

1. Laravel is the authoritative business-logic layer.
2. Next.js is the presentation/application client.
3. PostgreSQL is the authoritative transactional database.
4. Never duplicate financial business logic in Next.js.
5. Never trust frontend permission checks.
6. Every protected operation must be authorized by Laravel.
7. Every financial mutation must use a database transaction.
8. Payment webhooks must be idempotent.
9. Historical financial records must not be silently overwritten.
10. Use reversal/refund/adjustment records instead of deleting financial history.
11. Reservation availability must be concurrency-safe.
12. All important mutations must be auditable.
13. Never expose secrets.
14. Never commit environment credentials.
15. Do not invent business rules when the specification is ambiguous.
16. If a business rule is genuinely missing, document the assumption before implementing it.
17. Update documentation whenever architecture or schema changes.
18. Write tests before declaring a module complete.

---

# 69. Implementation Sequence

## Phase 0 — Project Planning

Create:

```text
README.md
ARCHITECTURE.md
PRD.md
SRD.md
API.md
DATABASE.md
SECURITY.md
DEPLOYMENT.md
TESTING.md
CHANGELOG.md
```

Create:

```text
docs/
  architecture/
  api/
  database/
  modules/
  deployment/
  security/
```

---

## Phase 1 — GitHub and Local Environment

Backend:

```text
Laravel project
```

Frontend:

```text
Next.js TypeScript project
```

Configure:

- Git,
- .gitignore,
- environment files,
- local PostgreSQL,
- API URL,
- CORS,
- authentication foundation.

Create initial GitHub repositories.

---

## Phase 2 — Backend Foundation

Implement:

- Laravel configuration,
- PostgreSQL,
- API versioning,
- exception handling,
- API response conventions,
- logging,
- validation,
- CORS,
- rate limiting,
- health endpoint.

Create:

```text
GET /api/v1/health
```

---

## Phase 3 — Authentication

Implement:

- customer registration,
- login,
- logout,
- password reset,
- staff login,
- Sanctum,
- session/token strategy,
- authentication middleware.

---

## Phase 4 — RBAC

Implement:

- roles,
- permissions,
- role assignment,
- permission assignment,
- multiple roles per user,
- policies,
- permission middleware.

Seed:

```text
Super Administrator
Administrator
Hotel Manager
Receptionist
Accountant
Waiter
Service Agent
Bartender
Inventory Manager
Auditor
Customer
```

---

## Phase 5 — Property and Hotel Configuration

Implement:

- property,
- departments,
- settings,
- room types,
- rooms,
- amenities,
- room images,
- room statuses.

Build corresponding Next.js management screens.

---

## Phase 6 — Guest Management

Implement:

- guests,
- guest profiles,
- contact details,
- guest documents,
- search,
- guest history.

---

## Phase 7 — Reservation Engine

Implement:

- availability search,
- reservation creation,
- multiple rooms,
- multiple guests,
- pricing snapshot,
- deposit configuration,
- full payment,
- pending reservation expiry,
- cancellation,
- confirmation.

Test concurrency.

---

## Phase 8 — Payments

Implement:

```text
PaymentGatewayInterface
PaystackGateway
FlutterwaveGateway
ManualPaymentGateway
```

Implement:

- payment initiation,
- payment callback,
- webhook,
- signature verification,
- idempotency,
- reconciliation,
- refunds,
- receipts.

Never consider a redirect alone proof of payment.

---

## Phase 9 — Check-In / Check-Out

Implement:

- room allocation,
- guest check-in,
- room status,
- stay,
- check-out,
- late checkout,
- outstanding balance,
- final receipt.

---

## Phase 10 — Billing

Implement:

- bills,
- bill items,
- guest ledger,
- service charges,
- taxes,
- discounts,
- payment allocation,
- outstanding balance.

---

## Phase 11 — Hotel Services

Implement configurable:

```text
Laundry
Room Service
Restaurant
Spa
Airport Transfer
Extra Bed
Conference Room
Other
```

Support charge-to-room.

---

## Phase 12 — Bar

Implement:

- tables,
- table status,
- categories,
- products,
- customer capture,
- orders,
- order items,
- waiter workflow,
- bartender workflow,
- order status.

---

## Phase 13 — Bar Payments

Implement:

- online payment,
- cash,
- POS/card,
- bank transfer,
- bill email,
- payment link,
- receipt,
- room charge.

---

## Phase 14 — Staff

Implement:

- employee profiles,
- roles,
- departments,
- shift templates,
- assignments,
- attendance,
- staff notifications.

---

## Phase 15 — Inventory

Implement:

- products,
- stock,
- stock movements,
- suppliers,
- purchases,
- recipes,
- deductions,
- low stock alerts.

---

## Phase 16 — Finance

Implement:

- financial transactions,
- revenue,
- expenses,
- refunds,
- payment methods,
- reconciliation,
- daily closing,
- reports,
- exports.

---

## Phase 17 — Dashboards

Create role-specific dashboards:

```text
Manager
Receptionist
Waiter
Bartender
Accountant
Administrator
Customer
```

---

## Phase 18 — Notifications

Implement email and in-app notifications.

Use queued jobs.

---

## Phase 19 — Audit

Implement complete audit logging.

Test audit creation for:

- financial changes,
- permissions,
- reservations,
- payments,
- refunds,
- discounts,
- room allocation,
- staff scheduling.

---

## Phase 20 — Realtime

Implement realtime bar order updates.

Fallback gracefully when realtime is unavailable.

---

## Phase 21 — Testing

Run:

```text
unit tests
feature tests
authorization tests
payment tests
reservation tests
billing tests
frontend tests
E2E tests
```

Do not proceed with known failing critical tests.

---

## Phase 22 — CI/CD

Create GitHub Actions for both repositories.

Every pull request must:

```text
install
lint
type check
test
build
```

Production deployment should occur only after required checks pass.

---

## Phase 23 — Railway

Deploy:

```text
PostgreSQL
Laravel API
Queue Worker
Scheduler
Redis
Next.js
```

Configure:

- environment variables,
- domains,
- HTTPS,
- health checks,
- migrations,
- worker,
- scheduler,
- logging.

---

## Phase 24 — Production Hardening

Perform:

- security review,
- permission review,
- payment review,
- database index review,
- query optimization,
- rate-limit review,
- backup validation,
- error monitoring,
- log review,
- performance testing,
- mobile/tablet testing.

---

# 70. AI Agent Working Protocol

Before modifying code:

1. Inspect repository.
2. Inspect existing architecture.
3. Inspect database schema.
4. Inspect API contracts.
5. Inspect tests.
6. Identify dependencies.
7. Propose implementation.
8. Implement.
9. Run tests.
10. Fix failures.
11. Update documentation.
12. Report completed work.

Never assume a file exists.

Never overwrite unrelated work.

Never remove existing functionality without explicit authorization.

---

# 71. Database Migration Rules

Migration order must respect foreign keys.

Use:

```text
users
roles/permissions
properties
departments
staff
room_types
amenities
rooms
guests
reservations
reservation_rooms
reservation_guests
stays
room_allocations
services
products
tables
orders
bills
payments
payment_allocations
refunds
finance
audit
notifications
```

Use indexes for:

- foreign keys,
- status,
- dates,
- reservation dates,
- payment references,
- order references,
- guest phone/email,
- room number.

Use unique constraints for:

- reservation numbers,
- order numbers,
- payment references,
- receipt numbers,
- room numbers within property,
- employee numbers.

---

# 72. API Development Rule

Before building a frontend feature:

1. Define API endpoint.
2. Define request DTO/schema.
3. Define validation.
4. Define authorization.
5. Define response.
6. Write API test.
7. Implement frontend API client.
8. Implement UI.

Never allow the frontend to invent undocumented endpoints.

---

# 73. Payment Development Rule

For every payment:

```text
Create internal transaction
       |
       v
Generate unique reference
       |
       v
Call gateway
       |
       v
Redirect/customer payment
       |
       v
Gateway webhook
       |
       v
Verify webhook
       |
       v
Check idempotency
       |
       v
Update payment
       |
       v
Allocate payment
       |
       v
Update bill/reservation
       |
       v
Create financial transaction
       |
       v
Generate receipt
       |
       v
Notify customer
```

All critical state changes must be transactional.

---

# 74. Reservation Concurrency Rule

The agent must protect against:

```text
Customer A selects Room 101
Customer B selects Room 101
Both attempt payment simultaneously
```

The system must ensure only one conflicting confirmed reservation succeeds.

Implement database-level and application-level protections appropriate for PostgreSQL.

---

# 75. Billing Rule

Never calculate the final bill independently in multiple places.

Create a canonical billing service.

Example:

```text
BillingService
  |
  +-- calculateSubtotal()
  +-- calculateTax()
  +-- calculateServiceCharge()
  +-- calculateDiscount()
  +-- calculateTotal()
  +-- calculatePaid()
  +-- calculateBalance()
```

The frontend may display calculations, but Laravel remains authoritative.

---

# 76. UX Rule

Every screen must support:

```text
Loading
Empty
Success
Error
Unauthorized
Validation failure
Network failure
```

Forms must preserve user input after validation failures where safe.

Destructive operations require confirmation.

---

# 77. Mobile/Tablet Rule

The waiter interface must be optimized for:

- Android phones,
- iPhones,
- tablets.

Minimum requirements:

- large touch targets,
- fast product selection,
- minimal typing,
- persistent order summary,
- quick table selection,
- obvious status indicators.

---

# 78. Accessibility

Target WCAG-conscious implementation:

- keyboard navigation,
- semantic HTML,
- visible focus,
- sufficient contrast,
- accessible labels,
- screen-reader-friendly forms,
- no color-only status indicators.

---

# 79. SEO

Public hotel pages should include:

- metadata,
- Open Graph,
- structured data where appropriate,
- canonical URLs,
- sitemap,
- robots configuration,
- semantic headings,
- optimized images.

---

# 80. Observability

Implement:

- structured application logs,
- error tracking,
- queue failure monitoring,
- payment failure monitoring,
- API response monitoring,
- database health monitoring.

Production errors must not expose sensitive details.

---

# 81. Final Acceptance Scenario

The complete system should successfully support this scenario:

```text
1. Customer visits hotel website.
2. Customer searches dates.
3. System displays available rooms.
4. Customer selects room.
5. Customer enters guest information.
6. Customer chooses 30% deposit.
7. Customer pays online.
8. Gateway webhook confirms payment.
9. Reservation becomes confirmed.
10. Customer receives confirmation and receipt.
11. Receptionist sees reservation.
12. Guest arrives.
13. Receptionist verifies guest.
14. Guest checks in.
15. Room becomes occupied.
16. Guest orders laundry.
17. ₦5,000 is added to guest bill.
18. Guest visits bar.
19. Waiter opens tablet.
20. Waiter selects table.
21. Waiter enters guest phone.
22. Waiter adds drinks.
23. Order is sent to bartender.
24. Bartender prepares order.
25. Bartender marks order ready.
26. Waiter delivers order.
27. Customer chooses Charge to Room.
28. Bar bill is added to guest stay.
29. Guest requests checkout.
30. System calculates final balance.
31. Guest pays outstanding balance.
32. Payment is verified.
33. Final receipt is issued.
34. Guest is checked out.
35. Room becomes dirty/cleaning.
36. Finance dashboard reflects all payments.
37. Audit log contains all sensitive operations.
```

This scenario must be covered by automated integration/E2E tests.

---

# 82. Product Success Criteria

The system is considered production-ready when:

- customers can successfully reserve rooms,
- availability is accurate,
- payments are verified,
- receipts are reliable,
- check-in/out works,
- guest billing is accurate,
- bar orders flow from waiter to bartender,
- customers can pay online or manually,
- bar charges can be attached to hotel stays,
- staff can have multiple roles,
- permissions are enforced,
- shifts are managed,
- finance records reconcile,
- audit logs work,
- critical workflows are tested,
- CI passes,
- Railway deployment is reproducible,
- production secrets are secured,
- backups and monitoring are operational.

---

# 83. Recommended First Development Milestone

Do NOT begin with the futuristic homepage.

The first engineering milestone should be:

```text
GitHub
+
Laravel
+
Next.js
+
PostgreSQL
+
Authentication
+
RBAC
+
API standards
+
CI
```

Then build the reservation engine.

A beautiful frontend without a reliable reservation/payment core will create expensive rework later.

---

# 84. Final Architectural Summary

```text
                    HOTEL & BAR PLATFORM
                             |
        +--------------------+--------------------+
        |                    |                    |
      HOTEL                  BAR                STAFF
        |                    |                    |
 Reservations             Tables              Users
 Rooms                    Orders              Roles
 Guests                   Products            Permissions
 Check-in                 Bartender           Shifts
 Check-out                Waiter              Attendance
 Services                 Payments
        |                    |
        +---------+----------+
                  |
               BILLING
                  |
        +---------+----------+
        |                    |
     Payments             Finance
        |                    |
        +---------+----------+
                  |
             AUDIT/REPORTS
                  |
             NOTIFICATIONS
                  |
             PostgreSQL
```

The key design decision is that **reservation, stay, bill, payment and financial transaction are separate concepts**. This prevents common hospitality-system data integrity problems and allows the hotel and bar to operate independently while still charging eligible bar/service transactions to a guest's hotel account.
