<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Rotas oficiais do módulo Cora Payments (Pix & Boleto Híbrido)
 */

// Webhook unificado nativo (O Perfex CRM libera nativamente gateways/.* de CSRF)
$route['gateways/cora/webhook']        = 'cora_payments/cora/webhook';
$route['gateways/cora/webhook/(:any)'] = 'cora_payments/cora/webhook/$1';

// Rotas diretas do webhook
$route['cora_payments/cora/webhook']        = 'cora_payments/cora/webhook';
$route['cora_payments/cora/webhook/(:any)'] = 'cora_payments/cora/webhook/$1';
$route['cora_payments/webhook']             = 'cora_payments/cora/webhook';
$route['cora_payments/webhook/(:any)']      = 'cora_payments/cora/webhook/$1';

// Tela de visualização e pagamento Pix (com proteção anti-IDOR via hash)
$route['cora_payments/cora/pay/(:num)/(:any)/(:any)'] = 'cora_payments/cora/pay/$1/$2/$3';
$route['cora_payments/cora/pay/(:num)/(:any)']        = 'cora_payments/cora/pay/$1/$2';

// Tela de visualização / download de Boleto Híbrido (com proteção anti-IDOR via hash)
$route['cora_payments/cora/boleto/(:num)/(:any)/(:any)']          = 'cora_payments/cora/boleto/$1/$2/$3';
$route['cora_payments/cora/boleto/(:num)/(:any)']                 = 'cora_payments/cora/boleto/$1/$2';
$route['cora_payments/cora/boleto_view/(:num)/(:any)/(:any)']     = 'cora_payments/cora/boleto_view/$1/$2/$3';
$route['cora_payments/cora/boleto_view/(:num)/(:any)']            = 'cora_payments/cora/boleto_view/$1/$2';
$route['cora_payments/cora/download_boleto/(:num)/(:any)/(:any)'] = 'cora_payments/cora/download_boleto/$1/$2/$3';
$route['cora_payments/cora/download_boleto/(:num)/(:any)']        = 'cora_payments/cora/download_boleto/$1/$2';

// Endpoint de Polling para verificação assíncrona do status (com proteção anti-IDOR via hash)
$route['cora_payments/cora/check_status/(:num)/(:any)/(:any)'] = 'cora_payments/cora/check_status/$1/$2/$3';
$route['cora_payments/cora/check_status/(:num)/(:any)']        = 'cora_payments/cora/check_status/$1/$2';

// Validação administrativa de conexão mTLS
$route['admin/cora_payments/cora/test_connection'] = 'cora_payments/cora/test_connection';
