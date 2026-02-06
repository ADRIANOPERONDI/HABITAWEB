<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// ========== INSTALADOR (Wizard de Setup Inicial) ==========
$routes->group('install', ['namespace' => 'App\Controllers\Install'], function($routes) {
    $routes->get('/', 'InstallController::index');
    $routes->get('step/(:num)', 'InstallController::step/$1');
    $routes->post('test-database', 'InstallController::testDatabase');
    $routes->post('saveStep', 'InstallController::saveStep');
    $routes->post('process', 'InstallController::process');
    $routes->get('finalize', 'InstallController::finalize');
});

// ========== ROTAS PÚBLICAS ==========
$routes->get('/', 'Home::index');
// Rotas de Busca (SEO Friendly)
$routes->get('imoveis', 'Web\SearchController::index');
$routes->get('imoveis/(:segment)', 'Web\SearchController::searchOne/$1'); // ex: venda
$routes->get('imoveis/(:segment)/(:segment)', 'Web\SearchController::searchTwo/$1/$2'); // ex: venda/sao-paulo
$routes->get('imoveis/(:segment)/(:segment)/(:segment)', 'Web\SearchController::searchThree/$1/$2/$3'); // ex: venda/sao-paulo/centro
$routes->get('imovel/(:num)', 'Web\PropertyDetailsController::show/$1');
$routes->post('leads', 'Web\LeadController::store');
$routes->post('leads/register-event', 'Web\LeadController::registerEvent');
$routes->post('alertas/criar', 'Web\PropertyAlertController::create');

// Checkout Routes
$routes->group('checkout', function($routes) {
    $routes->get('plans', 'Web\CheckoutController::index');
    $routes->get('plan/(:num)', 'Web\CheckoutController::plan/$1');
    $routes->post('process', 'Web\CheckoutController::process');
    $routes->get('success', 'Web\CheckoutController::success');
    $routes->get('validate-coupon', 'Web\CheckoutController::validateCoupon');
});

// Webhooks
$routes->post('webhook/asaas', 'Webhook\WebhookController::asaas');
$routes->post('webhook/(:segment)', 'Webhook\WebhookController::receive/$1');

// Partner Routes (Public Marketplace)
$routes->get('parceiros', 'Web\PartnerController::index');
$routes->get('parceiro/(:num)', 'Web\PartnerController::show/$1');

// Custom Partner Registration

$routes->get('anuncie', 'Web\RegisterController::index');
$routes->post('anuncie', 'Web\RegisterController::process');
$routes->get('register/check-email', 'Web\RegisterController::checkEmail'); // AJAX Check


$routes->group('', ['filter' => 'session'], function($routes) {
    $routes->get('meus-favoritos', 'Web\MyFavoritesController::index');
});

// DISABLE Shield default routes (we use custom admin routes)
// service('auth')->routes($routes);

// FORCE redirect from /login to /admin/login
$routes->get('login', function() {
    return redirect()->to('/admin/login');
});

$routes->get('logout', function() {
    return redirect()->to('/admin/login');
});

$routes->get('register', function() {
    return redirect()->to('/anuncie');
});

$routes->group('api/v1', ['namespace' => 'App\\Controllers\\Api\\V1'], function($routes) {
    // Properties (requer auth exceto calculate-score)
    $routes->resource('properties', ['controller' => 'PropertyController']);
    $routes->post('properties/(:num)/report', 'PropertyController::report/$1');
    
    // Accounts (requer auth + admin)
    $routes->resource('accounts', ['controller' => 'AccountController']);
    
    // Leads (create é público, resto requer auth)
    $routes->post('leads', 'LeadController::create'); // Público
    $routes->resource('leads', ['controller' => 'LeadController', 'except' => 'create']);
    
    // Webhooks (requer auth)
    $routes->resource('webhooks', ['controller' => 'WebhookController']);
    $routes->post('webhooks/(:num)/test', 'WebhookController::test/$1');
    
    // Export/Import (requer auth)
    $routes->get('export/properties', 'ExportController::properties');
    $routes->post('import/properties', 'ImportController::properties');
    
    // Favorites
    $routes->post('favorites/toggle', 'FavoriteController::toggle');
});


$routes->get('api/docs', 'Api\DocsController::index');
$routes->get('api/docs/json', 'Api\DocsController::json');
$routes->get('api/test-suite', 'Api\DocsController::testSuite');

// Rotas de Login Administrativo Redirecionado
$routes->get('admin/login', '\App\Controllers\Admin\Auth\LoginController::loginView');
$routes->post('admin/login', '\App\Controllers\Admin\Auth\LoginController::loginAction');
$routes->get('admin/logout', '\App\Controllers\Admin\Auth\LoginController::logoutAction');

// Redireciona /admin se não estiver logado (via admin_auth)
$routes->get('admin', '\App\Controllers\Admin\DashboardController::index', ['filter' => 'admin_auth']);

$routes->group('admin', ['namespace' => 'App\Controllers\Admin', 'filter' => 'admin_auth'], function($routes) {
    $routes->get('/', 'DashboardController::index');
    // Custom Property Routes (Must come before resource)
    $routes->get('properties/(:num)/turbo', 'PromotionController::turbo/$1');
    $routes->post('properties/(:num)/media', 'PropertyMediaController::upload/$1');

    $routes->post('properties/(:num)/toggle-destaque', 'PropertyController::toggleDestaque/$1');
    $routes->get('properties/check-destaque-limit', 'PropertyController::checkDestaqueLimit');
    $routes->resource('properties', ['controller' => 'PropertyController']);
    $routes->post('properties/(:num)/restore', 'PropertyController::restore/$1');
    $routes->get('properties/(:num)/closure-leads', 'PropertyController::getLeadsForClosure/$1');
    $routes->post('properties/(:num)/close', 'PropertyController::markAsClosed/$1');

    $routes->post('promotions/store/(:num)', 'PromotionController::store/$1');
    $routes->get('promotions/check-status/(:segment)', 'PromotionController::checkStatus/$1');
    
    $routes->resource('promotions', ['controller' => 'PromotionController']);
    $routes->resource('leads', ['controller' => 'LeadsController']);
    $routes->post('leads/(:num)/update-status', 'LeadsController::updateStatus/$1');
    $routes->post('leads/(:num)/update', 'LeadsController::update/$1');
    $routes->resource('team', ['controller' => 'TeamController']); // New route
    
    $routes->get('clients/search', 'ClientController::search');
    $routes->post('clients/quick', 'ClientController::quickCreate');
    $routes->resource('clients', ['controller' => 'ClientController']);
    $routes->post('properties/calculate-score', '\App\Controllers\Api\V1\PropertyController::calculateScore');
    $routes->get('dashboard', 'DashboardController::index');
    $routes->get('profile', 'ProfileController::index');
    $routes->post('profile', 'ProfileController::update');
    
    // Super Admin Only
    $routes->resource('plans', ['controller' => 'PlanController', 'filter' => 'group:superadmin']);
    
    // Media Routes
    $routes->delete('media/(:num)', 'PropertyMediaController::delete/$1');
    $routes->post('media/(:num)/main', 'PropertyMediaController::setMain/$1');

    $routes->get('leads', 'LeadsController::index');

    // Subscription Routes
    $routes->get('subscription', 'SubscriptionController::index');
    $routes->get('subscription/invoices', 'SubscriptionController::invoices');
    $routes->get('subscription/upgrade/(:num)', 'SubscriptionController::upgrade/$1');
    $routes->get('subscription/preview-upgrade/(:num)', 'SubscriptionController::previewUpgrade/$1');
    $routes->get('subscription/invoice/(:num)', 'SubscriptionController::downloadInvoice/$1');
    $routes->post('subscription/cancel/(:num)', 'SubscriptionController::cancel/$1');
    $routes->get('settings', 'SettingsController::index', ['filter' => 'group:superadmin']);
    $routes->post('settings', 'SettingsController::update', ['filter' => 'group:superadmin']);
    $routes->post('settings/test-email', 'SettingsController::testEmail', ['filter' => 'group:superadmin']);

    // Payment Admin Routes
    $routes->get('payments', 'PaymentAdminController::index');
    $routes->get('payments/transactions', 'PaymentAdminController::transactions');
    $routes->get('payments/get-transactions', 'PaymentAdminController::getTransactions');
    $routes->get('payments/transaction/(:num)', 'PaymentAdminController::viewTransaction/$1');
    $routes->get('payments/export-transactions', 'PaymentAdminController::exportTransactions');

    // API Keys Management (Super Admin + Clientes)
    $routes->get('api-keys', 'ApiKeysController::index');
    $routes->post('api-keys', 'ApiKeysController::create');
    $routes->post('api-keys/(:num)/revoke', 'ApiKeysController::revoke/$1');
    $routes->post('api-keys/(:num)/toggle', 'ApiKeysController::toggle/$1');
    $routes->delete('api-keys/(:num)', 'ApiKeysController::delete/$1');

    // Plans Management (Super Admin)
    $routes->get('plans', 'PlansController::index', ['filter' => 'group:superadmin']);
    $routes->get('plans/create', 'PlansController::create', ['filter' => 'group:superadmin']);
    $routes->post('plans/store', 'PlansController::store', ['filter' => 'group:superadmin']);
    $routes->get('plans/(:num)/edit', 'PlansController::edit/$1', ['filter' => 'group:superadmin']);
    $routes->post('plans/(:num)/update', 'PlansController::update/$1', ['filter' => 'group:superadmin']);
    $routes->post('plans/(:num)/toggle', 'PlansController::toggle/$1', ['filter' => 'group:superadmin']);
    $routes->post('plans/(:num)/delete', 'PlansController::delete/$1', ['filter' => 'group:superadmin']);

    // Coupons Management (Super Admin + Admin)
    $routes->get('coupons', 'CouponsController::index', ['filter' => 'group:superadmin,admin']);
    $routes->get('coupons/create', 'CouponsController::create', ['filter' => 'group:superadmin,admin']);
    $routes->post('coupons/store', 'CouponsController::store', ['filter' => 'group:superadmin,admin']);
    $routes->get('coupons/(:num)/edit', 'CouponsController::edit/$1', ['filter' => 'group:superadmin,admin']);
    $routes->post('coupons/(:num)/update', 'CouponsController::update/$1', ['filter' => 'group:superadmin,admin']);
    $routes->post('coupons/(:num)/toggle', 'CouponsController::toggle/$1', ['filter' => 'group:superadmin,admin']);
    $routes->delete('coupons/(:num)', 'CouponsController::delete/$1', ['filter' => 'group:superadmin,admin']);
    $routes->get('coupons/report', 'CouponsController::report', ['filter' => 'group:superadmin,admin']);


    // Super Admin Management (Contas e Usuários)
    $routes->get('accounts', 'AccountsController::index', ['filter' => 'group:superadmin,admin']);
    $routes->get('accounts/(:num)/edit', 'AccountsController::edit/$1', ['filter' => 'group:superadmin,admin']);
    $routes->post('accounts/(:num)/update', 'AccountsController::update/$1', ['filter' => 'group:superadmin,admin']);
    
    // Gestão de Assinaturas por Conta
    $routes->get('accounts/(:num)/subscription', 'AccountSubscriptionController::show/$1', ['filter' => 'group:superadmin,admin']);
    $routes->post('accounts/(:num)/subscription/upgrade', 'AccountSubscriptionController::upgrade/$1', ['filter' => 'group:superadmin,admin']);
    $routes->post('accounts/(:num)/subscription/suspend', 'AccountSubscriptionController::suspend/$1', ['filter' => 'group:superadmin,admin']);
    $routes->post('accounts/(:num)/subscription/cancel', 'AccountSubscriptionController::cancel/$1', ['filter' => 'group:superadmin,admin']);
    
    $routes->get('users', 'UsersController::index', ['filter' => 'group:superadmin,admin']);
    $routes->get('users/(:num)/edit', 'UsersController::edit/$1', ['filter' => 'group:superadmin,admin']);
    $routes->post('users/(:num)/update', 'UsersController::update/$1', ['filter' => 'group:superadmin,admin']);


    // Curation Routes
    $routes->get('curation', 'CurationController::index', ['filter' => 'group:superadmin,admin']);
    $routes->post('curation/resolve/(:num)', 'CurationController::resolveReport/$1', ['filter' => 'group:superadmin,admin']);
    $routes->get('curation/approve/(:num)', 'CurationController::approveProperty/$1', ['filter' => 'group:superadmin,admin']);

    // Notifications Routes
    $routes->get('notifications', 'NotificationController::getLatest');
    $routes->post('notifications/mark-read/(:num)', 'NotificationController::markAsRead/$1');
    $routes->post('notifications/mark-all-read', 'NotificationController::markAllAsRead');

    // Financial Dashboard
    $routes->get('financeiro', 'FinancialDashboardController::index');

    // Payment Gateways Management
    $routes->group('payment-gateways', function($routes) {
        $routes->get('/', 'PaymentGatewayController::index');
        $routes->get('sync', 'PaymentGatewayController::sync');
        $routes->get('configure/(:num)', 'PaymentGatewayController::configure/$1');
        $routes->post('update/(:num)', 'PaymentGatewayController::update/$1');
        $routes->post('toggle/(:num)', 'PaymentGatewayController::toggle/$1');
        $routes->post('set-primary/(:num)', 'PaymentGatewayController::setPrimary/$1');
    });

    // Coupons
    $routes->resource('coupons', ['controller' => 'CouponController']);

    // Promotion Packages (CRUD)
    $routes->group('packages', function($routes) {
        $routes->get('/', 'PromotionPackageController::index');
        $routes->get('new', 'PromotionPackageController::new');
        $routes->post('create', 'PromotionPackageController::create');
        $routes->get('edit/(:num)', 'PromotionPackageController::edit/$1');
        $routes->post('update/(:num)', 'PromotionPackageController::update/$1');
        $routes->get('delete/(:num)', 'PromotionPackageController::delete/$1');
    });
});

