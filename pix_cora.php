<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Pix Direto Banco Cora
Description: Integração nativa e direta com o Pix do Banco Cora para Perfex CRM via autenticação mTLS e conciliação automática por Webhook.
Version: 1.0.0
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
 * Exclusão do endpoint de Webhook da verificação de CSRF do CodeIgniter
 * Permite que as notificações enviadas pelos servidores do Banco Cora sejam recebidas sem bloqueio de CSRF token.
 */
hooks()->add_filter('csrf_exclude_uris', 'pix_cora_csrf_exclude');

function pix_cora_csrf_exclude($uris)
{
    $uris[] = 'pix_cora/pix/webhook';
    $uris[] = 'pix_cora/pix/webhook/';
    return $uris;
}
