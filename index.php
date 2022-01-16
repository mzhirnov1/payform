<?php
header('Access-Control-Allow-Origin: *');
if(!isset($_POST['action']) && !isset($_GET['stripe']) && !isset($_GET['auth'])) {
  http_response_code(404);
} else {
  require_once(__DIR__ . '/app/payment_class.php');
  $paymentClass = new paymentClass();
  exit(json_encode($paymentClass->load($_POST, $_GET)));
}