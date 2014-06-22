<?php

/* ----------------------------------------------------------
  Load WordPress
---------------------------------------------------------- */

define('WP_USE_THEMES', false);

// To fix : allow a different path.
include dirname(__FILE__) . '/../../../wp-load.php';

/* ----------------------------------------------------------
  Check order
---------------------------------------------------------- */

if (empty($_GET) || !isset($_GET['id']) || !isset($_GET['key'])) {
    die();
}

// Get order details
$wpu_o = new wpuOrders();
$order = $wpu_o->get_order_details($_GET['id'], $_GET['key']);
if (!is_object($order)) {
    die();
}

// Set order status to success
$wpu_o->update_order($order->id, array(
    'status' => 'complete'
));
