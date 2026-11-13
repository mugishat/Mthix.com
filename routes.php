<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AffiliateController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\DeliveryController;
use App\Controllers\ResellerController;
use App\Controllers\SellerController;
use App\Controllers\ShopController;
use App\Core\Router;

/**
 * Route table. Role arrays are enforced by the Router BEFORE the controller runs (null = public,
 * [] = any signed-in user). Ownership checks (this store's order, this buyer's order) live in the controllers/services.
 */
$r = new Router();

// ---------- public shop ----------
$r->get('/', [ShopController::class, 'home']);
$r->get('/category/{slug}', [ShopController::class, 'category']);
$r->get('/search', [ShopController::class, 'search']);
$r->get('/product/{slug}', [ShopController::class, 'product']);
$r->get('/coming-soon/{what}', [ShopController::class, 'comingSoon']);
$r->post('/wishlist/{id}', [ShopController::class, 'wishlistToggle'], []);

// ---------- auth ----------
$r->get('/login', [AuthController::class, 'loginForm']);
$r->post('/login', [AuthController::class, 'login']);
$r->get('/register', [AuthController::class, 'registerForm']);
$r->post('/register', [AuthController::class, 'register']);
$r->post('/logout', [AuthController::class, 'logout']);

// ---------- cart, checkout, payment ----------
$r->get('/cart', [CartController::class, 'show']);
$r->post('/cart/add', [CartController::class, 'add']);
$r->post('/cart/update', [CartController::class, 'update']);
$r->post('/cart/remove', [CartController::class, 'remove']);
$r->post('/cart/clear', [CartController::class, 'clear']);

$r->get('/checkout', [CheckoutController::class, 'form'], []);
$r->post('/checkout', [CheckoutController::class, 'place'], []);
$r->get('/pay/{group}', [CheckoutController::class, 'pay'], []);
$r->post('/pay/{group}', [CheckoutController::class, 'payStart'], []);
$r->post('/pay/{group}/simulate', [CheckoutController::class, 'simulate'], []);   // dev only: stands in for the gateway
$r->get('/order/success/{group}', [CheckoutController::class, 'success'], []);
$r->post('/webhooks/payment', [CheckoutController::class, 'webhook'], null, false); // gateway -> us, HMAC-signed, no CSRF

// ---------- buyer account (also used by every role for wallet / KYC / notifications / messages) ----------
$r->get('/account', [AccountController::class, 'index'], []);
$r->get('/account/orders', [AccountController::class, 'orders'], []);
$r->get('/account/orders/{code}', [AccountController::class, 'order'], []);
$r->post('/account/orders/{code}/confirm', [AccountController::class, 'confirmReceipt'], []);
$r->post('/account/orders/{code}/cancel', [AccountController::class, 'cancel'], []);
$r->post('/account/orders/{code}/dispute', [AccountController::class, 'dispute'], []);
$r->post('/account/orders/{code}/review', [AccountController::class, 'review'], []);
$r->get('/account/wishlist', [AccountController::class, 'wishlist'], []);
$r->get('/account/settings', [AccountController::class, 'settings'], []);
$r->post('/account/settings', [AccountController::class, 'saveSettings'], []);

$r->get('/wallet', [AccountController::class, 'wallet'], []);
$r->post('/wallet/withdraw', [AccountController::class, 'withdraw'], []);
$r->get('/kyc', [AccountController::class, 'kyc'], []);
$r->post('/kyc', [AccountController::class, 'kycSubmit'], []);
$r->get('/notifications', [AccountController::class, 'notifications'], []);
$r->post('/notifications/read', [AccountController::class, 'notificationsRead'], []);
$r->get('/messages', [AccountController::class, 'messages'], []);
$r->get('/messages/{id}', [AccountController::class, 'thread'], []);
$r->post('/messages/send', [AccountController::class, 'sendMessage'], []);

// ---------- seller ----------
$S = ['seller'];
$r->get('/seller', [SellerController::class, 'dashboard'], $S);
$r->get('/seller/products', [SellerController::class, 'products'], $S);
$r->get('/seller/products/new', [SellerController::class, 'productForm'], $S);
$r->post('/seller/products', [SellerController::class, 'productCreate'], $S);
$r->get('/seller/products/{id}/edit', [SellerController::class, 'productEdit'], $S);
$r->post('/seller/products/{id}', [SellerController::class, 'productUpdate'], $S);
$r->post('/seller/products/{id}/status', [SellerController::class, 'productStatus'], $S);
$r->get('/seller/products/{id}/variants', [SellerController::class, 'variants'], $S);          // Variant Builder
$r->post('/seller/products/{id}/variants/generate', [SellerController::class, 'variantsGenerate'], $S);
$r->post('/seller/products/{id}/variants/save', [SellerController::class, 'variantsSave'], $S);
$r->get('/seller/orders', [SellerController::class, 'orders'], $S);
$r->get('/seller/orders/{code}', [SellerController::class, 'order'], $S);
$r->post('/seller/orders/{code}/{action}', [SellerController::class, 'orderAction'], $S);      // confirm | pack | cancel
$r->get('/seller/store', [SellerController::class, 'store'], $S);
$r->post('/seller/store', [SellerController::class, 'storeSave'], $S);
$r->get('/seller/wallet', [AccountController::class, 'wallet'], $S);

// ---------- reseller ----------
$R = ['reseller'];
$r->get('/reseller', [ResellerController::class, 'dashboard'], $R);
$r->get('/reseller/products', [ResellerController::class, 'products'], $R);
$r->get('/reseller/orders', [ResellerController::class, 'orders'], $R);

// ---------- affiliate ----------
$A = ['affiliate'];
$r->get('/affiliate', [AffiliateController::class, 'dashboard'], $A);
$r->get('/affiliate/orders', [AffiliateController::class, 'orders'], $A);

// ---------- delivery partner ----------
$D = ['delivery'];
$r->get('/delivery', [DeliveryController::class, 'dashboard'], $D);
$r->get('/delivery/jobs', [DeliveryController::class, 'jobs'], $D);
$r->get('/delivery/mine', [DeliveryController::class, 'mine'], $D);
$r->post('/delivery/jobs/{id}/accept', [DeliveryController::class, 'accept'], $D);
$r->post('/delivery/jobs/{id}/{step}', [DeliveryController::class, 'step'], $D);               // pickup | out | delivered | failed

// ---------- admin (staff; each page re-checks its own sub-role in the controller) ----------
$STAFF = ['admin', 'finance_admin', 'support_admin'];
$r->get('/admin', [AdminController::class, 'dashboard'], $STAFF);
$r->get('/admin/users', [AdminController::class, 'users'], ['admin', 'support_admin']);
$r->post('/admin/users/{id}/status', [AdminController::class, 'userStatus'], ['admin']);
$r->get('/admin/kyc', [AdminController::class, 'kyc'], ['admin', 'support_admin']);
$r->post('/admin/kyc/{id}', [AdminController::class, 'kycDecide'], ['admin', 'support_admin']);
$r->get('/admin/products', [AdminController::class, 'products'], ['admin', 'support_admin']);
$r->post('/admin/products/{id}/status', [AdminController::class, 'productStatus'], ['admin', 'support_admin']);
$r->get('/admin/orders', [AdminController::class, 'orders'], ['admin', 'support_admin']);
$r->get('/admin/orders/{code}', [AdminController::class, 'order'], ['admin', 'support_admin']);
$r->post('/admin/orders/{code}/transition', [AdminController::class, 'orderTransition'], ['admin', 'support_admin']);
$r->get('/admin/disputes', [AdminController::class, 'disputes'], ['admin', 'support_admin']);
$r->post('/admin/disputes/{id}', [AdminController::class, 'disputeResolve'], ['admin', 'support_admin']);
$r->get('/admin/payments', [AdminController::class, 'payments'], ['admin', 'finance_admin']);
$r->get('/admin/withdrawals', [AdminController::class, 'withdrawals'], ['admin', 'finance_admin']);
$r->post('/admin/withdrawals/{id}/{decision}', [AdminController::class, 'withdrawalDecide'], ['admin', 'finance_admin']); // approve | reject | paid
$r->get('/admin/commissions', [AdminController::class, 'commissions'], ['admin', 'finance_admin']);
$r->post('/admin/commissions', [AdminController::class, 'commissionCreate'], ['admin']);
$r->get('/admin/audit', [AdminController::class, 'audit'], ['admin']);

return $r;
