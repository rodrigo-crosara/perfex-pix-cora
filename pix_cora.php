<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Pix Direto Banco Cora
Description: Integração nativa e direta com o Pix do Banco Cora para Perfex CRM via autenticação mTLS e conciliação automática por Webhook.
Version: 1.1.0
Requires at least: 2.3.*
Author: Perfex CRM Integration Team
*/

define('PIX_CORA_MODULE_NAME', 'pix_cora');

/**
 * Hook de Ativação do Módulo
 * Executa a migração do banco de dados e cria a estrutura de diretórios e segurança.
 */
register_activation_hook(PIX_CORA_MODULE_NAME, 'pix_cora_activation_hook');

function pix_cora_activation_hook()
{
    $CI = &get_instance();
    require_once(__DIR__ . '/install.php');
}

/**
 * Registro do Gateway de Pagamento no Perfex CRM
 * Disparado antes da inicialização dos gateways de pagamento.
 */
hooks()->add_action('before_payment_gateways_initialize', 'pix_cora_gateway_init');

function pix_cora_gateway_init()
{
    /**
     * Registra a classe Pix_cora_gateway localizada em modules/pix_cora/libraries/Pix_cora_gateway.php
     */
    register_payment_gateway('pix_cora_gateway', PIX_CORA_MODULE_NAME);
}

/**
 * 1. Exceção de CSRF no Webhook (CodeIgniter)
 * O Perfex CRM mantém a proteção CSRF ativada globalmente.
 * Registra a rota nas exceções do CodeIgniter no hook pre_system para garantir
 * que as chamadas POST do Banco Cora não recebam HTTP 403 Forbidden.
 */
hooks()->add_action('pre_system', function () {
    $CI = &get_instance();
    if (isset($CI->config)) {
        $whitelist = $CI->config->item('csrf_exclude_uris');
        if (!is_array($whitelist)) {
            $whitelist = [];
        }
        $whitelist[] = 'pix_cora/pix/webhook';
        $whitelist[] = 'pix_cora/pix/webhook/*';
        $CI->config->set_item('csrf_exclude_uris', $whitelist);
    }
});

/**
 * Filtro auxiliar de compatibilidade para CSRF exclusion
 */
hooks()->add_filter('csrf_exclude_uris', 'pix_cora_csrf_exclude');

function pix_cora_csrf_exclude($uris)
{
    if (!is_array($uris)) {
        $uris = [];
    }
    $uris[] = 'pix_cora/pix/webhook';
    $uris[] = 'pix_cora/pix/webhook/';
    $uris[] = 'pix_cora/pix/webhook/*';
    return array_unique($uris);
}

/**
 * Validação de Moeda: Auto-desativação do botão Pix Cora para Faturas em Moeda Estrangeira (USD/EUR)
 * Como o Bacen opera estritamente em BRL, se a moeda da fatura não for BRL, o gateway é ocultado.
 */
hooks()->add_filter('is_payment_gateway_available', 'pix_cora_check_currency_available', 10, 3);

function pix_cora_check_currency_available($available, $gateway, $invoice)
{
    $gatewayId = is_array($gateway) ? ($gateway['id'] ?? '') : ($gateway->id ?? '');

    if ($gatewayId === 'pix_cora') {
        $currencyName = '';
        if (isset($invoice->currency_name) && !empty($invoice->currency_name)) {
            $currencyName = $invoice->currency_name;
        } elseif (isset($invoice->currency)) {
            $currencyObj = get_currency($invoice->currency);
            if ($currencyObj && isset($currencyObj->name)) {
                $currencyName = $currencyObj->name;
            }
        }

        // Desativa caso a moeda seja definida e diferente de BRL
        if (!empty($currencyName) && strtoupper(trim($currencyName)) !== 'BRL') {
            return false;
        }
    }

    return $available;
}
