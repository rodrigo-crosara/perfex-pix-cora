<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Cora Payments (Pix Imediato e Boleto Híbrido)
Description: Módulo unificado de pagamentos com Banco Cora para Perfex CRM. Suporta Pix Imediato dinâmico e Boleto Bancário Híbrido com QR Code Pix embutido, autenticação mTLS, régua de cobrança automática/WhatsApp e conciliação atômica via Webhook.
Version: 2.0.0
Requires at least: 2.3.*
Author: Perfex CRM Integration Team
*/

define('CORA_PAYMENTS_MODULE_NAME', 'cora_payments');

/**
 * Hook de Ativação do Módulo
 * Cria a tabela unificada {db_prefix}cora_transactions e a infraestrutura segura de certificados mTLS
 */
register_activation_hook(CORA_PAYMENTS_MODULE_NAME, 'cora_payments_activation_hook');

function cora_payments_activation_hook()
{
    $CI = &get_instance();
    require_once(__DIR__ . '/install.php');
}

/**
 * Registro dos Dois Gateways de Pagamento no Perfex CRM
 * Disparado antes da inicialização dos gateways de pagamento.
 */
hooks()->add_action('before_payment_gateways_initialize', 'cora_payments_gateways_init');

function cora_payments_gateways_init()
{
    // Registra os gateways Pix e Boleto Híbrido localizados em cora_payments/libraries/
    register_payment_gateway('cora_pix_gateway', CORA_PAYMENTS_MODULE_NAME);
    register_payment_gateway('cora_boleto_gateway', CORA_PAYMENTS_MODULE_NAME);
}

/**
 * 1. Exceção de CSRF no Webhook (CodeIgniter)
 * Desativa a checagem de CSRF para requisições de webhook enviadas pelo Banco Cora.
 */
hooks()->add_action('pre_system', function () {
    $CI = &get_instance();
    if (isset($CI->config)) {
        $whitelist = $CI->config->item('csrf_exclude_uris');
        if (!is_array($whitelist)) {
            $whitelist = [];
        }
        $whitelist[] = 'cora_payments/cora/webhook';
        $whitelist[] = 'cora_payments/cora/webhook/*';
        $whitelist[] = 'cora_payments/webhook';
        $CI->config->set_item('csrf_exclude_uris', $whitelist);
    }
});

/**
 * Filtro auxiliar de compatibilidade para exceção de CSRF
 */
hooks()->add_filter('csrf_exclude_uris', 'cora_payments_csrf_exclude');

function cora_payments_csrf_exclude($uris)
{
    if (!is_array($uris)) {
        $uris = [];
    }
    $uris[] = 'cora_payments/cora/webhook';
    $uris[] = 'cora_payments/cora/webhook/';
    $uris[] = 'cora_payments/cora/webhook/*';
    $uris[] = 'cora_payments/webhook';
    return array_unique($uris);
}

/**
 * Validação de Moeda: Oculta os gateways Cora para faturas que não sejam em Real (BRL)
 */
hooks()->add_filter('is_payment_gateway_available', 'cora_payments_check_currency_available', 10, 3);

function cora_payments_check_currency_available($available, $gateway, $invoice)
{
    $gatewayId = is_array($gateway) ? ($gateway['id'] ?? '') : ($gateway->id ?? '');

    if ($gatewayId === 'cora_pix' || $gatewayId === 'cora_boleto') {
        $currencyName = '';
        if (isset($invoice->currency_name) && !empty($invoice->currency_name)) {
            $currencyName = $invoice->currency_name;
        } elseif (isset($invoice->currency)) {
            $currencyObj = get_currency($invoice->currency);
            if ($currencyObj && isset($currencyObj->name)) {
                $currencyName = $currencyObj->name;
            }
        }

        if (!empty($currencyName) && strtoupper(trim($currencyName)) !== 'BRL') {
            return false;
        }
    }

    return $available;
}

/**
 * POP Visual (Guia de Integração Cora) e Botão de Teste mTLS nas Abas de Configuração
 */
hooks()->add_action('after_payment_gateways_settings', 'cora_payments_render_admin_ui');

function cora_payments_render_admin_ui()
{
    $testUrlAdmin = admin_url('cora_payments/cora/test_connection');
    $testUrlSite  = site_url('cora_payments/cora/test_connection');
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var pixTab = document.querySelector('#online_payments_cora_pix_tab');
        var boletoTab = document.querySelector('#online_payments_cora_boleto_tab');

        var guideHtml = `
        <div class="panel panel-info mbot20">
            <div class="panel-heading pointer" data-toggle="collapse" data-target="#cora-sop-guide-body" style="cursor: pointer;">
                <i class="fa fa-book-open"></i> <strong>Guia Passo a Passo: Configuração do Banco Cora (Pix &amp; Boleto Híbrido)</strong>
                <span class="pull-right"><i class="fa fa-chevron-down"></i></span>
            </div>
            <div id="cora-sop-guide-body" class="panel-collapse collapse in">
                <div class="panel-body">
                    <ol class="padding-left-20" style="line-height: 1.8;">
                        <li><strong>Plano Cora Pro:</strong> No aplicativo Cora no celular, vá em <em>Integrações via APIs &gt; Integração Direta</em> e confirme a adesão para liberar chaves e certificados de API.</li>
                        <li><strong>Portal Desenvolvedor:</strong> Pelo navegador no computador, acesse <a href="https://app.cora.com.br" target="_blank" class="text-bold">app.cora.com.br</a> &gt; <em>Configurações &gt; Integrações via API</em>.</li>
                        <li><strong>Aplicação e Client ID:</strong> Crie uma aplicação (Ex: "Perfex CRM") e copie o <strong>Client ID</strong> gerado.</li>
                        <li><strong>Certificados mTLS:</strong> Baixe o certificado público (<code>.pem</code> ou <code>.crt</code>) e a chave privada (<code>.key</code>). <em>Atenção: Salve o arquivo .key de imediato, pois a Cora o exibe apenas uma vez.</em></li>
                        <li><strong>Compartilhamento Automático:</strong> Os certificados e o Client ID configurados na aba <strong>Pix Banco Cora</strong> são compartilhados automaticamente com a aba <strong>Boleto Bancário Cora</strong>! Você não precisa preencher duas vezes.</li>
                        <li><strong>Cadastro dos Dois Webhooks na Cora:</strong><br>
                            &bull; <em>Webhook Pix:</em> Vinculado à Chave Pix para conciliação das transferências instantâneas.<br>
                            &bull; <em>Webhook Boletos (v2):</em> No portal <a href="https://app.cora.com.br" target="_blank">app.cora.com.br</a> em <em>Integrações &gt; Webhooks</em>, cadastre a URL oficial com os eventos <strong>invoice.paid</strong> e <strong>invoice.cancelled</strong>.
                        </li>
                    </ol>
                </div>
            </div>
        </div>
        `;

        // Injeta guia nas abas se existirem
        if (pixTab) {
            pixTab.insertAdjacentHTML('afterbegin', guideHtml);
            injectTestButton(pixTab, 'cora_pix');
        }

        if (boletoTab) {
            var boletoNotice = `
            <div class="alert alert-info mbot20">
                <i class="fa fa-sync-alt"></i> <strong>Credenciais Compartilhadas:</strong> Se você já preencheu o Client ID e os certificados na aba <strong>Pix Banco Cora</strong>, não é necessário preenchê-los novamente aqui. O módulo reutiliza automaticamente as credenciais já configuradas!
            </div>
            `;
            boletoTab.insertAdjacentHTML('afterbegin', boletoNotice);
            injectTestButton(boletoTab, 'cora_boleto');
        }

        function injectTestButton(container, gatewayId) {
            var wrapper = document.createElement('div');
            wrapper.className = 'form-group mtop25';
            wrapper.style.borderTop = '1px solid #e2e8f0';
            wrapper.style.paddingTop = '15px';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-info';
            btn.innerHTML = '<i class="fa fa-plug"></i> Testar Conexão mTLS com a Cora';

            var resultBox = document.createElement('div');
            resultBox.id = 'cora_test_result_' + gatewayId;
            resultBox.style.marginTop = '12px';
            resultBox.style.display = 'none';

            btn.onclick = function() {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Validando Conexão e Certificados...';
                resultBox.style.display = 'none';

                fetch('<?= $testUrlAdmin; ?>')
                    .then(function(r) {
                        if (!r.ok) {
                            return fetch('<?= $testUrlSite; ?>').then(function(res) { return res.json(); });
                        }
                        return r.json();
                    })
                    .then(function(res) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa fa-plug"></i> Testar Conexão mTLS com a Cora';

                        if (typeof alert_float === 'function') {
                            alert_float(res.success ? 'success' : 'danger', res.message);
                        }

                        resultBox.style.display = 'block';
                        resultBox.className = res.success ? 'alert alert-success' : 'alert alert-danger';
                        resultBox.innerHTML = '<strong>' + (res.success ? '<i class="fa fa-check-circle"></i> Sucesso: ' : '<i class="fa fa-exclamation-circle"></i> Falha: ') + '</strong>' + res.message;
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa fa-plug"></i> Testar Conexão mTLS com a Cora';

                        if (typeof alert_float === 'function') {
                            alert_float('danger', 'Não foi possível conectar ao servidor para validar.');
                        }

                        resultBox.style.display = 'block';
                        resultBox.className = 'alert alert-danger';
                        resultBox.innerHTML = '<strong>Erro de requisição:</strong> Falha ao contatar o endpoint de validação.';
                    });
            };

            wrapper.appendChild(btn);
            wrapper.appendChild(resultBox);
            container.appendChild(wrapper);
        }
    });
    </script>
    <?php
}

/**
 * Cancelamento Automático no Perfex (Hook de Limpeza)
 * Para evitar que o cliente pague um boleto que foi cancelado pela sua equipe no CRM,
 * aciona a API Cora (DELETE /v2/invoices/{id}) e atualiza o status para CANCELLED.
 */
hooks()->add_action('after_invoice_cancelled', 'cora_cancel_invoice_on_bank');

function cora_cancel_invoice_on_bank($invoice_id)
{
    $CI = &get_instance();
    $transacao = $CI->db->where('invoice_id', $invoice_id)
        ->where('status', 'PENDING')
        ->get(db_prefix() . 'cora_transactions')
        ->row();

    if ($transacao && !empty($transacao->cora_invoice_id)) {
        $CI->load->library('cora_payments/cora_api');
        $CI->cora_api->cancelar_cobranca($transacao->cora_invoice_id);
        $CI->db->where('id', $transacao->id)->update(db_prefix() . 'cora_transactions', [
            'status' => 'CANCELLED',
        ]);
        log_activity('Boleto Cora cancelado no banco após cancelamento da Fatura #' . $invoice_id);
    }
}
