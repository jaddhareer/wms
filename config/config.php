<?php
// ============================================================
// WMS LSN - App Configuration
// ============================================================
defined('BASE_PATH') or die('Direct access not allowed');

define('APP_NAME',        'WMS LSN');
define('APP_VERSION',     '1.0.0');
define('SESSION_TIMEOUT', 28800); // 8 hours

// Product type → kg per carton
define('PRODUCT_KG_MAP', [
    '500gr'      => 10.0,
    '5kg'        => 10.0,
    '10kg'       => 10.0,
    '25kg'       => 25.0,
    '11gr/2.64kg'=> 2.64,
    '11gr/3.3kg' => 3.30,
    '11gr/5.5kg' => 5.50,
]);

// Transaction ID prefixes
define('TXN_PREFIX', [
    'inbound'  => 'LSNIB',
    'outbound' => 'LSNOB',
    'softcase' => 'LSNSC',
    'moving'   => 'LSNMO',
]);

// Role → allowed modules
define('ROLE_ACCESS', [
    'admin' => [
        'dashboard','inbound','outbound','softcase','moving',
        'stock','movements','softcase-monitoring','users'
    ],
    'supervisor' => [
        'dashboard','inbound','outbound','softcase','moving',
        'stock','movements','softcase-monitoring'
    ],
    'staff' => [
        'dashboard','inbound','outbound','moving','stock','movements','softcase-monitoring'
    ],
    'softchecker' => [
        'dashboard','moving','softcase','softcase-monitoring'
    ],
]);
