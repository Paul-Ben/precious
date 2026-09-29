<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Bar\CatalogController as BarCatalogController;
use App\Http\Controllers\Api\V1\Bar\OrderController as BarOrderController;
use App\Http\Controllers\Api\V1\Bar\TabController as BarTabController;
use App\Http\Controllers\Api\V1\Bar\TableController as BarTableController;
use App\Http\Controllers\Api\V1\Customer\ReservationController as CustomerReservationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Hotel\AmenityController;
use App\Http\Controllers\Api\V1\Hotel\ChargeController;
use App\Http\Controllers\Api\V1\Hotel\FolioController;
use App\Http\Controllers\Api\V1\Hotel\FrontDeskController;
use App\Http\Controllers\Api\V1\Hotel\GuestController;
use App\Http\Controllers\Api\V1\Hotel\PropertyController;
use App\Http\Controllers\Api\V1\Hotel\ReservationController;
use App\Http\Controllers\Api\V1\Hotel\RoomController;
use App\Http\Controllers\Api\V1\Hotel\RoomTypeController;
use App\Http\Controllers\Api\V1\Hotel\ServiceController;
use App\Http\Controllers\Api\V1\Hotel\StayController;
use App\Http\Controllers\Api\V1\PaymentGatewaySettingsController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Payments\RefundController;
use App\Http\Controllers\Api\V1\Payments\WebhookController;
use App\Http\Controllers\Api\V1\Public\BarTabController as PublicBarTabController;
use App\Http\Controllers\Api\V1\Public\CatalogController;
use App\Http\Controllers\Api\V1\Public\PaymentController as PublicPaymentController;
use App\Http\Controllers\Api\V1\Public\ReservationController as PublicReservationController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1  (prefix: /api/v1 - see bootstrap/app.php)
|--------------------------------------------------------------------------
| Every sensitive route is protected server-side by the `permission:`
| middleware. Frontend visibility is never relied upon for security.
*/

Route::get('health', HealthController::class)->name('health');

// ------------------------------------------------------------ Public website
Route::prefix('public')->name('public.')->middleware('throttle:public')->group(function () {
    Route::get('property', [CatalogController::class, 'property'])->name('property');
    Route::get('room-types', [CatalogController::class, 'roomTypes'])->name('room-types.index');
    Route::get('room-types/{slug}', [CatalogController::class, 'roomType'])->where('slug', '[a-z0-9-]+')->name('room-types.show');
    Route::get('availability', [CatalogController::class, 'availability'])->name('availability');
    Route::post('quote', [CatalogController::class, 'quote'])->name('quote');
    Route::post('reservations', [PublicReservationController::class, 'store'])->middleware('throttle:booking')->name('reservations.store');
    Route::get('reservations/{number}', [PublicReservationController::class, 'show'])
        ->where('number', '[A-Z]{3}-\d{4}-\d{3,10}')
        ->middleware('throttle:booking-lookup')
        ->name('reservations.show');

    // Online payment for guests (number + lookup token).
    Route::get('reservations/{number}/payment-options', [PublicPaymentController::class, 'options'])
        ->where('number', '[A-Z]{3}-\d{4}-\d{3,10}')->middleware('throttle:booking-lookup')->name('reservations.payment-options');
    Route::post('reservations/{number}/payments', [PublicPaymentController::class, 'store'])
        ->where('number', '[A-Z]{3}-\d{4}-\d{3,10}')->middleware('throttle:payments')->name('reservations.payments.store');
    // Bar bill pay link (TAB number + token from the bill email).
    Route::get('bar-tabs/{number}', [PublicBarTabController::class, 'show'])->where('number', 'TAB-\d{4}-\d{3,10}')->middleware('throttle:booking-lookup')->name('bar-tabs.show');
    Route::get('bar-tabs/{number}/payment-options', [PublicBarTabController::class, 'options'])->where('number', 'TAB-\d{4}-\d{3,10}')->middleware('throttle:booking-lookup')->name('bar-tabs.payment-options');
    Route::post('bar-tabs/{number}/payments', [PublicBarTabController::class, 'pay'])->where('number', 'TAB-\d{4}-\d{3,10}')->middleware('throttle:payments')->name('bar-tabs.payments.store');
    // Called by the /pay/callback page after the gateway redirects back.
    Route::post('payments/verify', [PublicPaymentController::class, 'verify'])->middleware('throttle:payments')->name('payments.verify');
});

// ------------------------------------------------------ Gateway webhooks
Route::post('webhooks/payments/{gateway}', WebhookController::class)
    ->where('gateway', '[a-z]+')
    ->middleware('throttle:webhooks')
    ->name('webhooks.payments');

Route::middleware('throttle:api')->group(function () {

    // ----------------------------------------------------------------- Auth
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('staff/login', [AuthController::class, 'staffLogin'])->name('staff.login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
        });

        Route::middleware('throttle:two-factor')->group(function () {
            Route::post('two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->name('two-factor.verify');
            Route::post('two-factor/resend', [AuthController::class, 'resendTwoFactor'])->name('two-factor.resend');
        });

        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('password/change', [AuthController::class, 'changePassword'])
                ->middleware('two_factor')
                ->name('password.change');
        });
    });

    // ------------------------------------------------------- Customer portal
    Route::prefix('me')->name('me.')->middleware(['auth:sanctum', 'active', 'two_factor', 'password.changed'])->group(function () {
        Route::get('reservations', [CustomerReservationController::class, 'index'])->name('reservations.index');
        Route::get('reservations/{reservation}', [CustomerReservationController::class, 'show'])->whereUuid('reservation')->name('reservations.show');
        Route::post('reservations/{reservation}/cancel', [CustomerReservationController::class, 'cancel'])->whereUuid('reservation')->name('reservations.cancel');
        Route::get('reservations/{reservation}/payment-options', [CustomerReservationController::class, 'paymentOptions'])->whereUuid('reservation')->name('reservations.payment-options');
        Route::post('reservations/{reservation}/payments', [CustomerReservationController::class, 'pay'])->middleware('throttle:payments')->whereUuid('reservation')->name('reservations.payments.store');
    });

    // --------------------------------------------------------------- Staff
    Route::middleware(['auth:sanctum', 'active', 'two_factor', 'password.changed', 'staff'])->group(function () {

        // Users
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create')->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update')->name('users.update');
        Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('permission:roles.assign')->name('users.roles.sync');
        Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->middleware('permission:users.update')->name('users.suspend');
        Route::post('users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:users.update')->name('users.activate');
        Route::post('users/{user}/temporary-password', [UserController::class, 'issueTemporaryPassword'])->middleware('permission:users.update')->name('users.temporary-password');

        // Roles & permissions
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view')->name('permissions.index');
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('roles.show');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update')->name('roles.update');
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.update')->name('roles.permissions.sync');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete')->name('roles.destroy');

        // Settings - payment gateways
        Route::prefix('settings/payment-gateways')->name('settings.payment-gateways.')
            ->middleware('permission:settings.payment_gateways.manage')
            ->group(function () {
                Route::get('/', [PaymentGatewaySettingsController::class, 'index'])->name('index');
                Route::get('{gateway}', [PaymentGatewaySettingsController::class, 'show'])->name('show');
                Route::patch('{gateway}', [PaymentGatewaySettingsController::class, 'update'])->name('update');
                Route::post('{gateway}/test', [PaymentGatewaySettingsController::class, 'test'])->name('test');
            });

        // Audit
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit-logs.index');

        // Property & hotel policies
        Route::get('property', [PropertyController::class, 'show'])->middleware('permission:settings.view|settings.update')->name('property.show');
        Route::patch('property', [PropertyController::class, 'update'])->middleware('permission:settings.update')->name('property.update');

        // Amenities
        Route::get('amenities', [AmenityController::class, 'index'])->middleware('permission:rooms.view')->name('amenities.index');
        Route::post('amenities', [AmenityController::class, 'store'])->middleware('permission:rooms.update')->name('amenities.store');
        Route::patch('amenities/{amenity}', [AmenityController::class, 'update'])->middleware('permission:rooms.update')->whereNumber('amenity')->name('amenities.update');
        Route::delete('amenities/{amenity}', [AmenityController::class, 'destroy'])->middleware('permission:rooms.update')->whereNumber('amenity')->name('amenities.destroy');

        // Room types
        Route::get('room-types', [RoomTypeController::class, 'index'])->middleware('permission:rooms.view')->name('room-types.index');
        Route::post('room-types', [RoomTypeController::class, 'store'])->middleware('permission:rooms.create')->name('room-types.store');
        Route::get('room-types/{roomType}', [RoomTypeController::class, 'show'])->middleware('permission:rooms.view')->whereNumber('roomType')->name('room-types.show');
        Route::patch('room-types/{roomType}', [RoomTypeController::class, 'update'])->middleware('permission:rooms.update')->whereNumber('roomType')->name('room-types.update');
        Route::delete('room-types/{roomType}', [RoomTypeController::class, 'destroy'])->middleware('permission:rooms.update')->whereNumber('roomType')->name('room-types.destroy');
        Route::post('room-types/{roomType}/images', [RoomTypeController::class, 'storeImage'])->middleware('permission:rooms.update')->whereNumber('roomType')->name('room-types.images.store');
        Route::put('room-types/{roomType}/images/order', [RoomTypeController::class, 'reorderImages'])->middleware('permission:rooms.update')->whereNumber('roomType')->name('room-types.images.order');
        Route::delete('room-types/{roomType}/images/{image}', [RoomTypeController::class, 'destroyImage'])->middleware('permission:rooms.update')->whereNumber(['roomType', 'image'])->name('room-types.images.destroy');

        // Rooms
        Route::get('rooms', [RoomController::class, 'index'])->middleware('permission:rooms.view')->name('rooms.index');
        Route::get('rooms/board', [RoomController::class, 'board'])->middleware('permission:rooms.view')->name('rooms.board');
        Route::post('rooms', [RoomController::class, 'store'])->middleware('permission:rooms.create')->name('rooms.store');
        Route::get('rooms/{room}', [RoomController::class, 'show'])->middleware('permission:rooms.view')->whereNumber('room')->name('rooms.show');
        Route::patch('rooms/{room}', [RoomController::class, 'update'])->middleware('permission:rooms.update')->whereNumber('room')->name('rooms.update');
        Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->middleware('permission:rooms.update')->whereNumber('room')->name('rooms.destroy');
        Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus'])->middleware('permission:rooms.manage_status')->whereNumber('room')->name('rooms.status');
        Route::post('rooms/{room}/blocks', [RoomController::class, 'storeBlock'])->middleware('permission:rooms.manage_status')->whereNumber('room')->name('rooms.blocks.store');
        Route::delete('rooms/{room}/blocks/{block}', [RoomController::class, 'destroyBlock'])->middleware('permission:rooms.manage_status')->whereNumber(['room', 'block'])->name('rooms.blocks.destroy');

        // Guests
        Route::get('guests', [GuestController::class, 'index'])->middleware('permission:guests.view')->name('guests.index');
        Route::post('guests', [GuestController::class, 'store'])->middleware('permission:guests.create')->name('guests.store');
        Route::get('guests/{guest}', [GuestController::class, 'show'])->middleware('permission:guests.view')->whereUuid('guest')->name('guests.show');
        Route::patch('guests/{guest}', [GuestController::class, 'update'])->middleware('permission:guests.update')->whereUuid('guest')->name('guests.update');
        Route::delete('guests/{guest}', [GuestController::class, 'destroy'])->middleware('permission:guests.update')->whereUuid('guest')->name('guests.destroy');
        Route::get('guests/{guest}/reservations', [GuestController::class, 'reservations'])->middleware('permission:guests.view')->whereUuid('guest')->name('guests.reservations');
        Route::get('guests/{guest}/documents', [GuestController::class, 'documents'])->middleware('permission:guests.documents.view')->whereUuid('guest')->name('guests.documents.index');
        Route::post('guests/{guest}/documents', [GuestController::class, 'storeDocument'])->middleware('permission:guests.documents.view')->whereUuid('guest')->name('guests.documents.store');
        Route::get('guests/{guest}/documents/{document}/download', [GuestController::class, 'downloadDocument'])->middleware('permission:guests.documents.view')->whereUuid(['guest', 'document'])->name('guests.documents.download');
        Route::post('guests/{guest}/documents/{document}/verify', [GuestController::class, 'verifyDocument'])->middleware('permission:guests.documents.view')->whereUuid(['guest', 'document'])->name('guests.documents.verify');
        Route::delete('guests/{guest}/documents/{document}', [GuestController::class, 'destroyDocument'])->middleware('permission:guests.documents.view')->whereUuid(['guest', 'document'])->name('guests.documents.destroy');

        // Reservations
        Route::get('reservations', [ReservationController::class, 'index'])->middleware('permission:reservations.view')->name('reservations.index');
        Route::get('reservations/availability', [ReservationController::class, 'availability'])->middleware('permission:reservations.view|reservations.create')->name('reservations.availability');
        Route::post('reservations', [ReservationController::class, 'store'])->middleware('permission:reservations.create')->name('reservations.store');
        Route::get('reservations/{reservation}', [ReservationController::class, 'show'])->middleware('permission:reservations.view')->whereUuid('reservation')->name('reservations.show');
        Route::patch('reservations/{reservation}', [ReservationController::class, 'update'])->middleware('permission:reservations.update')->whereUuid('reservation')->name('reservations.update');
        Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->middleware('permission:reservations.cancel')->whereUuid('reservation')->name('reservations.cancel');

        // Front desk
        Route::get('front-desk/summary', [FrontDeskController::class, 'summary'])->middleware('permission:reservations.view')->name('front-desk.summary');

        // Check-in / stays / check-out (M2c)
        Route::get('stays', [StayController::class, 'index'])->middleware('permission:reservations.view')->name('stays.index');
        Route::get('reservations/{reservation}/check-in', [StayController::class, 'checkInOptions'])->middleware('permission:checkins.create')->whereUuid('reservation')->name('reservations.check-in.options');
        Route::post('reservations/{reservation}/check-in', [StayController::class, 'checkIn'])->middleware('permission:checkins.create')->whereUuid('reservation')->name('reservations.check-in');
        Route::post('reservations/{reservation}/extend', [StayController::class, 'extend'])->middleware('permission:reservations.update')->whereUuid('reservation')->name('reservations.extend');
        Route::post('stays/{stay}/move', [StayController::class, 'move'])->middleware('permission:checkins.create')->whereUuid('stay')->name('stays.move');
        Route::get('reservations/{reservation}/check-out', [StayController::class, 'checkOutPreview'])->middleware('permission:checkouts.create')->whereUuid('reservation')->name('reservations.check-out.preview');
        Route::post('reservations/{reservation}/check-out', [StayController::class, 'checkOut'])->middleware('permission:checkouts.create')->whereUuid('reservation')->name('reservations.check-out');
        Route::get('folios/{number}', [FolioController::class, 'show'])->middleware('permission:reservations.view|payments.view')->where('number', 'FOL-\d{4}-\d{5,}')->name('folios.show');
        Route::post('folios/{number}/email', [FolioController::class, 'email'])->middleware('permission:checkouts.create|payments.view')->where('number', 'FOL-\d{4}-\d{5,}')->name('folios.email');

        // Guest bill items & services (spec §20-21)
        Route::post('reservations/{reservation}/charges', [ChargeController::class, 'store'])->middleware('permission:services.charge|bills.update')->whereUuid('reservation')->name('reservations.charges.store');
        Route::post('charges/{charge}/void', [ChargeController::class, 'void'])->middleware('permission:bills.update')->whereUuid('charge')->name('charges.void');
        Route::get('services', [ServiceController::class, 'index'])->middleware('permission:services.view|services.charge|services.manage')->name('services.index');
        Route::post('services', [ServiceController::class, 'store'])->middleware('permission:services.manage')->name('services.store');
        Route::patch('services/{service}', [ServiceController::class, 'update'])->middleware('permission:services.manage')->whereNumber('service')->name('services.update');
        Route::delete('services/{service}', [ServiceController::class, 'destroy'])->middleware('permission:services.manage')->whereNumber('service')->name('services.destroy');

        // Bar / POS (M3)
        Route::prefix('bar')->name('bar.')->group(function () {
            Route::get('menu', [BarCatalogController::class, 'menu'])->middleware('permission:bar.products.view|bar.orders.create')->name('menu');
            Route::get('categories', [BarCatalogController::class, 'categories'])->middleware('permission:bar.products.view|bar.products.manage')->name('categories.index');
            Route::post('categories', [BarCatalogController::class, 'storeCategory'])->middleware('permission:bar.products.manage')->name('categories.store');
            Route::patch('categories/{category}', [BarCatalogController::class, 'updateCategory'])->middleware('permission:bar.products.manage')->whereNumber('category')->name('categories.update');
            Route::delete('categories/{category}', [BarCatalogController::class, 'destroyCategory'])->middleware('permission:bar.products.manage')->whereNumber('category')->name('categories.destroy');
            Route::get('products', [BarCatalogController::class, 'products'])->middleware('permission:bar.products.view|bar.products.manage')->name('products.index');
            Route::post('products', [BarCatalogController::class, 'storeProduct'])->middleware('permission:bar.products.manage')->name('products.store');
            Route::patch('products/{product}', [BarCatalogController::class, 'updateProduct'])->middleware('permission:bar.products.manage')->whereNumber('product')->name('products.update');
            Route::patch('products/{product}/availability', [BarCatalogController::class, 'availability'])->middleware('permission:bar.products.manage|bar.orders.prepare')->whereNumber('product')->name('products.availability');
            Route::delete('products/{product}', [BarCatalogController::class, 'destroyProduct'])->middleware('permission:bar.products.manage')->whereNumber('product')->name('products.destroy');

            Route::get('tables', [BarTableController::class, 'index'])->middleware('permission:bar.tables.view|bar.tables.manage')->name('tables.index');
            Route::post('tables', [BarTableController::class, 'store'])->middleware('permission:bar.tables.manage')->name('tables.store');
            Route::patch('tables/{table}', [BarTableController::class, 'update'])->middleware('permission:bar.tables.manage')->whereNumber('table')->name('tables.update');
            Route::patch('tables/{table}/status', [BarTableController::class, 'status'])->middleware('permission:bar.tables.manage|bar.orders.create')->whereNumber('table')->name('tables.status');
            Route::delete('tables/{table}', [BarTableController::class, 'destroy'])->middleware('permission:bar.tables.manage')->whereNumber('table')->name('tables.destroy');

            Route::get('tabs', [BarTabController::class, 'index'])->middleware('permission:bar.orders.view')->name('tabs.index');
            Route::post('tabs', [BarTabController::class, 'store'])->middleware('permission:bar.orders.create')->name('tabs.store');
            Route::get('tabs/{tab}', [BarTabController::class, 'show'])->middleware('permission:bar.orders.view')->name('tabs.show');
            Route::patch('tabs/{tab}', [BarTabController::class, 'update'])->middleware('permission:bar.orders.create|bar.orders.update')->name('tabs.update');
            Route::post('tabs/{tab}/orders', [BarTabController::class, 'placeOrder'])->middleware('permission:bar.orders.create')->name('tabs.orders.store');
            Route::post('tabs/{tab}/discount', [BarTabController::class, 'discount'])->middleware('permission:discounts.apply')->name('tabs.discount');
            Route::post('tabs/{tab}/payments', [BarTabController::class, 'pay'])->middleware('permission:payments.create')->name('tabs.payments.store');
            Route::post('tabs/{tab}/charge-to-room', [BarTabController::class, 'chargeToRoom'])->middleware('permission:bar.orders.charge_to_room')->name('tabs.charge-to-room');
            Route::post('tabs/{tab}/close', [BarTabController::class, 'close'])->middleware('permission:bar.orders.create|bar.orders.update')->name('tabs.close');
            Route::post('tabs/{tab}/email', [BarTabController::class, 'email'])->middleware('permission:bar.orders.view')->name('tabs.email');

            Route::get('orders/queue', [BarOrderController::class, 'queue'])->middleware('permission:bar.orders.view|bar.orders.prepare')->name('orders.queue');
            Route::post('orders/{order}/status', [BarOrderController::class, 'advance'])->middleware('permission:bar.orders.prepare|bar.orders.deliver')->whereUuid('order')->name('orders.status');
            Route::post('orders/{order}/cancel', [BarOrderController::class, 'cancel'])->middleware('permission:bar.orders.create|bar.orders.cancel')->whereUuid('order')->name('orders.cancel');
        });

        // Payments & receipts
        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view')->name('payments.index');
        Route::get('payments/summary', [PaymentController::class, 'summary'])->middleware('permission:payments.view')->name('payments.summary');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view')->whereUuid('payment')->name('payments.show');
        Route::post('payments/{payment}/verify', [PaymentController::class, 'verify'])->middleware('permission:payments.view')->whereUuid('payment')->name('payments.verify');
        Route::post('payments/{payment}/resolve', [PaymentController::class, 'resolve'])->middleware('permission:payments.refund')->whereUuid('payment')->name('payments.resolve');
        Route::get('reservations/{reservation}/payments', [PaymentController::class, 'forReservation'])->middleware('permission:payments.view|reservations.view')->whereUuid('reservation')->name('reservations.payments.index');
        Route::post('reservations/{reservation}/payments', [PaymentController::class, 'store'])->middleware('permission:payments.create')->whereUuid('reservation')->name('reservations.payments.store');
        Route::get('receipts/{number}', [PaymentController::class, 'receipt'])->middleware('permission:payments.view|reservations.view|bills.view')->where('number', 'RCP-\d{4}-\d{6,}')->name('receipts.show');
        Route::post('receipts/{number}/email', [PaymentController::class, 'emailReceipt'])->middleware('permission:payments.view|payments.create')->where('number', 'RCP-\d{4}-\d{6,}')->name('receipts.email');

        // Refunds (P14: above the threshold a second person must approve)
        Route::get('refunds', [RefundController::class, 'index'])->middleware('permission:payments.view')->name('refunds.index');
        Route::post('payments/{payment}/refunds', [RefundController::class, 'store'])->middleware('permission:payments.refund')->whereUuid('payment')->name('refunds.store');
        Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])->middleware('permission:payments.refund')->whereUuid('refund')->name('refunds.approve');
        Route::post('refunds/{refund}/reject', [RefundController::class, 'reject'])->middleware('permission:payments.refund')->whereUuid('refund')->name('refunds.reject');
        Route::post('refunds/{refund}/complete', [RefundController::class, 'complete'])->middleware('permission:payments.refund')->whereUuid('refund')->name('refunds.complete');
    });
});
