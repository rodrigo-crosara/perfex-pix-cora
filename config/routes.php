<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Rotas oficiais do módulo Cora Payments (Pix & Boleto Híbrido)
 */

// Webhook unificado (Pix e Boletos Cora)
$route['cora_payments/cora/webhook'] = 'cora_payments/cora/webhook';
$route['cora_payments/webhook']      = 'cora_payments/cora/webhook';

// Tela de visualização e pagamento Pix
$route['cora_payments/cora/pay/(:num)/(:any)'] = 'cora_payments/cora/pay/$1/$2';

// Tela de visualização / download de Boleto Híbrido
$route['cora_payments/cora/boleto/(:num)/(:any)']          = 'cora_payments/cora/boleto/$1/$2';
$route['cora_payments/cora/download_boleto/(:num)/(:any)'] = 'cora_payments/cora/download_boleto/$1/$2';

// Endpoint de Polling para verificação assíncrona do status
$route['cora_payments/cora/check_status/(:num)/(:any)'] = 'cora_payments/cora/check_status/$1/$2';

// Validação administrativa de conexão mTLS
$route['admin/cora_payments/cora/test_connection'] = 'cora_payments/cora/test_connection';
