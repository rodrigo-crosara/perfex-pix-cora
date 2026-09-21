<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Cora Payments (Pix Imediato e Boleto Híbrido)
Description: Módulo unificado de pagamentos com Banco Cora para Perfex CRM. Suporta Pix Imediato dinâmico (API Cora Pro com mTLS e Webhook) e Modo Pix Manual de contingência (QR Code Estático sem API), além de Boleto Bancário Híbrido.
Version: 2.1.0
Requires at least: 2.3.*
Author: Perfex CRM Integration Team
*/

if (!defined('CORA_PAYMENTS_MODULE_NAME')) {
    define('CORA_PAYMENTS_MODULE_NAME', basename(__DIR__));
}

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
 * Registro dos Gateways de Pagamento no Perfex CRM
 * No Perfex CRM, register_payment_gateway deve ser invocado diretamente no arquivo principal do módulo.
 * Registramos imediatamente e também nos hooks app_init e before_payment_gateways_initialize para cobertura completa em qualquer versão.
 */
if (function_exists('register_payment_gateway')) {
    register_payment_gateway('cora_pix_gateway', CORA_PAYMENTS_MODULE_NAME);
    register_payment_gateway('cora_boleto_gateway', CORA_PAYMENTS_MODULE_NAME);
}

hooks()->add_action('app_init', 'cora_payments_gateways_init');
hooks()->add_action('before_payment_gateways_initialize', 'cora_payments_gateways_init');

function cora_payments_gateways_init()
{
    if (function_exists('register_payment_gateway')) {
        register_payment_gateway('cora_pix_gateway', CORA_PAYMENTS_MODULE_NAME);
        register_payment_gateway('cora_boleto_gateway', CORA_PAYMENTS_MODULE_NAME);
    }
}

/**
 * 1. Exceção de CSRF no Webhook (CodeIgniter)
 * Desativa a checagem de CSRF para requisições de webhook enviadas pelo Banco Cora.
 */
hooks()->add_action('app_init', function () {
    $CI = &get_instance();
    if (isset($CI->config)) {
        $whitelist = $CI->config->item('csrf_exclude_uris');
        if (!is_array($whitelist)) {
            $whitelist = [];
        }
        $whitelist[] = 'gateways/cora/webhook';
        $whitelist[] = 'gateways/cora/webhook/';
        $whitelist[] = 'gateways/cora/webhook/.*';
        $whitelist[] = 'gateways/cora/webhook/*';
        $whitelist[] = 'cora_payments/cora/webhook';
        $whitelist[] = 'cora_payments/cora/webhook/';
        $whitelist[] = 'cora_payments/cora/webhook/.*';
        $whitelist[] = 'cora_payments/cora/webhook/*';
        $whitelist[] = 'cora_payments/webhook';
        $whitelist[] = 'cora_payments/webhook/';
        $whitelist[] = 'cora_payments/webhook/.*';
        $whitelist[] = 'cora_payments/webhook/*';
        $whitelist[] = 'cora_payments/cora';
        $whitelist[] = 'cora_payments/cora/.*';
        $whitelist[] = 'cora_payments/cora/*';
        $whitelist[] = 'cora/webhook';
        $whitelist[] = 'cora/webhook/';
        $whitelist[] = 'cora/webhook/.*';
        $whitelist[] = 'cora/webhook/*';
        $CI->config->set_item('csrf_exclude_uris', array_unique($whitelist));
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
    $uris[] = 'gateways/cora/webhook';
    $uris[] = 'gateways/cora/webhook/';
    $uris[] = 'gateways/cora/webhook/.*';
    $uris[] = 'gateways/cora/webhook/*';
    $uris[] = 'cora_payments/cora/webhook';
    $uris[] = 'cora_payments/cora/webhook/';
    $uris[] = 'cora_payments/cora/webhook/.*';
    $uris[] = 'cora_payments/cora/webhook/*';
    $uris[] = 'cora_payments/webhook';
    $uris[] = 'cora_payments/webhook/';
    $uris[] = 'cora_payments/webhook/.*';
    $uris[] = 'cora_payments/webhook/*';
    $uris[] = 'cora/webhook';
    $uris[] = 'cora/webhook/';
    $uris[] = 'cora/webhook/.*';
    $uris[] = 'cora/webhook/*';
    return array_unique($uris);
}

/**
 * Validação de Moeda e Disponibilidade do Gateway na Fatura:
 * - Oculta Pix e Boleto Cora na visualização/pagamento de faturas que não sejam em Real (BRL).
 * - Oculta Boleto Cora na fatura caso as credenciais da API Cora Pro (mTLS) não estejam configuradas.
 * - IMPORTANTE: Quando $invoice é nulo ou vazio (ex: tela de Configurações do Admin / Setup > Settings > Payment Gateways),
 *   o filtro NUNCA deve ocultar os gateways, permitindo que as abas e campos de configuração sejam exibidos normalmente.
 */
hooks()->add_filter('is_payment_gateway_available', 'cora_payments_check_currency_available', 10, 3);

function cora_payments_check_currency_available($available, $gateway, $invoice = null)
{
    // 1. Se o gateway já está desativado pelo administrador nas opções do Perfex, mantém desativado
    if ($available === false) {
        return false;
    }

    // 2. REGRA CRÍTICA: Se não há fatura sendo avaliada (ex: tela de Configurações do Perfex / Admin Settings),
    // NUNCA aplica restrições de moeda ou credenciais, garantindo que as abas de configuração apareçam!
    if (empty($invoice) || (!is_object($invoice) && !is_array($invoice))) {
        return $available;
    }

    // Se for objeto sem dados básicos de fatura, não interfere
    if (is_object($invoice) && empty($invoice->id) && empty($invoice->currency) && empty($invoice->currency_name)) {
        return $available;
    }

    $gatewayId = is_array($gateway) ? ($gateway['id'] ?? '') : ($gateway->id ?? '');

    if ($gatewayId === 'cora_pix' || $gatewayId === 'cora_boleto') {
        $currencyName = '';

        if (is_object($invoice)) {
            if (!empty($invoice->currency_name)) {
                $currencyName = $invoice->currency_name;
            } elseif (!empty($invoice->currency) && function_exists('get_currency')) {
                $currencyObj = get_currency($invoice->currency);
                if ($currencyObj && !empty($currencyObj->name)) {
                    $currencyName = $currencyObj->name;
                }
            } elseif (!empty($invoice->id) && function_exists('get_invoice_currency_id')) {
                $currencyId = get_invoice_currency_id($invoice->id);
                if ($currencyId && function_exists('get_currency')) {
                    $currencyObj = get_currency($currencyId);
                    if ($currencyObj && !empty($currencyObj->name)) {
                        $currencyName = $currencyObj->name;
                    }
                }
            }
        } elseif (is_array($invoice)) {
            $currencyName = $invoice['currency_name'] ?? '';
            if (empty($currencyName) && !empty($invoice['currency']) && function_exists('get_currency')) {
                $currencyObj = get_currency($invoice['currency']);
                if ($currencyObj && !empty($currencyObj->name)) {
                    $currencyName = $currencyObj->name;
                }
            }
        }

        // Se a fatura tiver uma moeda identificada e NÃO for BRL, oculta o gateway na fatura
        if (!empty($currencyName) && strtoupper(trim($currencyName)) !== 'BRL') {
            return false;
        }

        // Para Boleto na Fatura: Oculta automaticamente caso não haja credenciais da API Cora Pro configuradas
        if ($gatewayId === 'cora_boleto') {
            $CI = &get_instance();
            $moduleName = defined('CORA_PAYMENTS_MODULE_NAME') ? CORA_PAYMENTS_MODULE_NAME : 'cora_payments';
            if (!class_exists('Cora_api', false)) {
                $CI->load->library($moduleName . '/cora_api');
            }
            if (isset($CI->cora_api)) {
                $clientId = $CI->cora_api->get_credential('client_id', 'cora_boleto');
                $certRaw  = $CI->cora_api->get_credential('cert_content', 'cora_boleto');
                $keyRaw   = $CI->cora_api->get_credential('key_content', 'cora_boleto');
                $certsDir = $CI->cora_api->get_certs_dir();
                $hasFiles = (file_exists($certsDir . DIRECTORY_SEPARATOR . 'cora_cert.pem') && file_exists($certsDir . DIRECTORY_SEPARATOR . 'cora_key.key'));

                if (empty($clientId) || (empty($certRaw) && !$hasFiles) || (empty($keyRaw) && !$hasFiles)) {
                    return false;
                }
            }
        }
    }

    return $available;
}

/**
 * POP Visual (Guia de Integração Cora) e Botão de Teste mTLS nas Abas de Configuração
 */
hooks()->add_action('after_payment_gateways_settings', 'cora_payments_render_admin_ui');
hooks()->add_action('app_admin_footer', 'cora_payments_render_admin_ui');

function cora_payments_render_admin_ui()
{
    static $rendered = false;
    if ($rendered) {
        return;
    }

    $CI = &get_instance();
    if (isset($CI->input) && $CI->input->get('group') !== 'payment_gateways') {
        return;
    }
    $rendered = true;

    $testUrlAdmin = admin_url('cora_payments/cora/test_connection');
    $testUrlSite  = site_url('cora_payments/cora/test_connection');
    $webhookUrl   = site_url('gateways/cora/webhook');
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var pixTab = document.querySelector('#online_payments_cora_pix_tab');
        var boletoTab = document.querySelector('#online_payments_cora_boleto_tab');

        var guideHtml = `
        <div class="panel panel-info mbot20">
            <div class="panel-heading pointer" data-toggle="collapse" data-target="#cora-sop-guide-body" style="cursor: pointer;">
                <i class="fa fa-book-open"></i> <strong>Guia de Configuração: Banco Cora (Modo Manual vs API Cora Pro)</strong>
                <span class="pull-right"><i class="fa fa-chevron-down"></i></span>
            </div>
            <div id="cora-sop-guide-body" class="panel-collapse collapse in">
                <div class="panel-body">
                    <div class="alert alert-success" style="margin-bottom: 15px;">
                        <i class="fa fa-star"></i> <strong>Novo Modo Pix Manual (Sem API Cora):</strong> Não possui o plano Cora Pro? Não se preocupe! Selecione <strong>"Modo Pix Manual"</strong> no campo <em>Modo de Operação do Pix</em> abaixo. Você precisará apenas informar sua Chave Pix e Nome do Titular. O sistema gerará o QR Code com o valor exato da fatura e sua equipe fará a baixa manual após receber o comprovante. Certificados mTLS e Client ID não são necessários!
                    </div>
                    <ol class="padding-left-20" style="line-height: 1.8;">
                        <li><strong>Modo Automático (API Cora Pro):</strong> No aplicativo Cora no celular, vá em <em>Integrações via APIs &gt; Integração Direta</em> e confirme a adesão para liberar chaves e certificados de API.</li>
                        <li><strong>Portal Desenvolvedor:</strong> Pelo navegador no computador, acesse <a href="https://app.cora.com.br" target="_blank" class="text-bold">app.cora.com.br</a> &gt; <em>Configurações &gt; Integrações via API</em>.</li>
                        <li><strong>Aplicação e Client ID:</strong> Crie uma aplicação (Ex: "Perfex CRM") e copie o <strong>Client ID</strong> gerado.</li>
                        <li><strong>Certificados mTLS:</strong> Baixe o certificado público (<code>.pem</code> ou <code>.crt</code>) e a chave privada (<code>.key</code>). <em>Atenção: Salve o arquivo .key de imediato, pois a Cora o exibe apenas uma vez.</em></li>
                        <li><strong>Compartilhamento Automático:</strong> Os certificados e o Client ID configurados na aba <strong>Pix Banco Cora</strong> são compartilhados automaticamente com a aba <strong>Boleto Bancário Cora</strong>! Você não precisa preencher duas vezes.</li>
                        <li><strong>Cadastro dos Dois Webhooks na Cora (Modo Automático):</strong><br>
                            &bull; <strong>URL Oficial do Webhook:</strong> <code style="user-select: all; font-weight: bold; background: #eef2f5; padding: 2px 6px; border-radius: 4px;"><?= $webhookUrl; ?></code><br>
                            &bull; <em>Webhook Pix:</em> Vinculado à Chave Pix para conciliação das transferências instantâneas.<br>
                            &bull; <em>Webhook Boletos (v2):</em> No portal <a href="https://app.cora.com.br" target="_blank">app.cora.com.br</a> em <em>Integrações &gt; Webhooks</em>, cadastre a URL oficial acima com os eventos <strong>invoice.paid</strong> e <strong>invoice.cancelled</strong>.
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
            <div class="alert alert-info mbot15">
                <i class="fa fa-sync-alt"></i> <strong>Credenciais Compartilhadas:</strong> Se você já preencheu o Client ID e os certificados na aba <strong>Pix Banco Cora</strong>, não é necessário preenchê-los novamente aqui. O módulo reutiliza automaticamente as credenciais já configuradas!
            </div>
            <div class="alert alert-warning mbot20">
                <i class="fa fa-shield-alt"></i> <strong>Atenção:</strong> O Boleto Bancário Híbrido requer obrigatoriamente a emissão via API Cora Pro com certificados mTLS ativos. Caso sua empresa utilize o <em>Modo Pix Manual</em> sem o plano Pro, a opção de Boleto será mantida oculta automaticamente para os clientes na fatura para prevenir falhas de emissão.
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
 * Unifica a opção "Pix Banco Cora" no seletor de Modos de Pagamento Permitidos no Admin.
 * Oculta a duplicata para que o usuário veja e selecione APENAS 1 "Pix Banco Cora",
 * enquanto sincroniza a seleção para garantir que o PDF e a tela fiquem 100% ativos.
 */
hooks()->add_action('app_admin_footer', 'cora_payments_admin_invoice_duplicate_fix');

function cora_payments_admin_invoice_duplicate_fix()
{
    ?>
    <script>
    (function() {
        function coraUnifyPixDropdown() {
            var selects = document.querySelectorAll('select[name="allowed_payment_modes[]"], select#allowed_payment_modes, select[name*="allowed_payment_modes"]');
            if (!selects || selects.length === 0) return;

            selects.forEach(function(select) {
                var options = select.querySelectorAll('option');
                var gatewayOpt = null;
                var numericOpt = null;

                for (var i = 0; i < options.length; i++) {
                    var val = options[i].value;
                    var txt = options[i].textContent.trim();
                    if (val === 'cora_pix') {
                        gatewayOpt = options[i];
                    } else if (!isNaN(val) && val !== '' && (txt === 'Pix Banco Cora' || txt.indexOf('Pix Banco Cora') !== -1)) {
                        numericOpt = options[i];
                    }
                }

                if (gatewayOpt && numericOpt) {
                    // Se o modo numérico estava selecionado no banco, marca o gateway oficial também
                    if (numericOpt.selected) {
                        gatewayOpt.selected = true;
                    }
                    if (gatewayOpt.selected) {
                        numericOpt.selected = true;
                    }

                    // Oculta a opção numérica no select nativo e no bootstrap-select
                    numericOpt.setAttribute('data-hidden', 'true');
                    numericOpt.classList.add('hidden');
                    numericOpt.style.display = 'none';

                    // Atualiza o Bootstrap Selectpicker se ativo
                    if (window.jQuery && typeof window.jQuery(select).selectpicker === 'function') {
                        window.jQuery(select).selectpicker('refresh');
                    }

                    // Garante que no menu dropdown do Bootstrap-Select só haja 1 item visível de Pix Banco Cora
                    var btnGroup = select.closest('.bootstrap-select') || (select.parentNode ? select.parentNode.querySelector('.bootstrap-select') : null);
                    if (btnGroup) {
                        var menuItems = btnGroup.querySelectorAll('.dropdown-menu li');
                        menuItems.forEach(function(li) {
                            var origIdx = li.getAttribute('data-original-index');
                            if (origIdx !== null) {
                                var idx = parseInt(origIdx, 10);
                                if (idx === numericOpt.index) {
                                    li.classList.add('hidden');
                                    li.style.display = 'none';
                                } else if (idx === gatewayOpt.index) {
                                    li.classList.remove('hidden');
                                    li.style.display = '';
                                }
                            }
                        });
                    }

                    // Sincroniza eventos de alteração e submissão
                    if (!select._coraSynced) {
                        select._coraSynced = true;
                        var syncSelection = function() {
                            if (gatewayOpt && numericOpt) {
                                numericOpt.selected = gatewayOpt.selected;
                            }
                        };

                        if (window.jQuery) {
                            window.jQuery(select).on('changed.bs.select change', syncSelection);
                        } else {
                            select.addEventListener('change', syncSelection);
                        }

                        var form = select.closest('form');
                        if (form) {
                            form.addEventListener('submit', syncSelection);
                        }
                    }
                }
            });
        }

        if (document.readyState !== 'loading') {
            coraUnifyPixDropdown();
        } else {
            document.addEventListener("DOMContentLoaded", coraUnifyPixDropdown);
        }

        // Intervalos de reforço para acomodar renderização assíncrona do Perfex CRM
        setTimeout(coraUnifyPixDropdown, 150);
        setTimeout(coraUnifyPixDropdown, 400);
        setTimeout(coraUnifyPixDropdown, 1000);
        setTimeout(coraUnifyPixDropdown, 2000);

        if (window.jQuery) {
            window.jQuery(document).ajaxComplete(function() {
                setTimeout(coraUnifyPixDropdown, 100);
            });
        }
    })();
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
        ->order_by('id', 'DESC')
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

/**
 * Hook para interceptar a construção do PDF da fatura e sincronizar os dados do Pix dinamicamente
 */
hooks()->add_action('pdf_construct', 'cora_payments_on_pdf_construct');

function cora_payments_on_pdf_construct($data = null)
{
    $pdf = is_array($data) ? ($data['pdf_instance'] ?? null) : $data;
    if (!$pdf || !is_object($pdf)) {
        return;
    }
    $invoice = null;
    if (isset($pdf->invoice) && is_object($pdf->invoice)) {
        $invoice = $pdf->invoice;
    } elseif (method_exists($pdf, 'get_invoice')) {
        $invoice = $pdf->get_invoice();
    }
    if ($invoice && isset($invoice->id)) {
        $modeId = cora_payments_sync_payment_modes($invoice);

        // Habilita tanto em memória quanto no banco de dados para a montagem do PDF
        if ($modeId > 0 && isset($invoice->allowed_payment_modes)) {
            $allowed = @unserialize($invoice->allowed_payment_modes);
            if (is_array($allowed) && (in_array('cora_pix', $allowed) || in_array((string)$modeId, $allowed) || in_array($modeId, $allowed))) {
                if (!in_array((string)$modeId, $allowed) || !in_array('cora_pix', $allowed)) {
                    $allowed[] = (string)$modeId;
                    $allowed[] = 'cora_pix';
                    $allowed = array_values(array_unique($allowed));
                    $invoice->allowed_payment_modes = serialize($allowed);

                    $CI = &get_instance();
                    if (isset($CI->db)) {
                        $CI->db->where('id', $invoice->id)->update(db_prefix() . 'invoices', [
                            'allowed_payment_modes' => serialize($allowed),
                        ]);
                    }
                }
            }
        }
    }
}

/**
 * Garante sincronização perfeita do modo Pix nas faturas criadas/editadas no Admin.
 * Quando o usuário seleciona a única opção visível "Pix Banco Cora" (cora_pix),
 * o backend garante que o ID numérico correspondente em tblpayment_modes também seja gravado,
 * garantindo 100% de compatibilidade tanto com o portal online quanto com o PDF baixado.
 */
hooks()->add_filter('before_invoice_added', 'cora_payments_filter_invoice_modes_on_save');
hooks()->add_filter('before_invoice_updated', 'cora_payments_filter_invoice_modes_on_save');

function cora_payments_filter_invoice_modes_on_save($data, $id = null)
{
    if (!is_array($data)) {
        return $data;
    }

    if (isset($data['allowed_payment_modes']) && is_array($data['allowed_payment_modes'])) {
        $CI = &get_instance();
        if (isset($CI->db)) {
            $pm = $CI->db->where('name', 'Pix Banco Cora')->or_like('name', 'Pix Banco Cora', 'both')->get(db_prefix() . 'payment_modes')->row();
            if ($pm) {
                $modeIdStr = (string)$pm->id;
                if (in_array('cora_pix', $data['allowed_payment_modes'])) {
                    if (!in_array($modeIdStr, $data['allowed_payment_modes']) && !in_array((int)$pm->id, $data['allowed_payment_modes'])) {
                        $data['allowed_payment_modes'][] = $modeIdStr;
                    }
                } else {
                    $data['allowed_payment_modes'] = array_values(array_diff($data['allowed_payment_modes'], [$modeIdStr, (int)$pm->id]));
                }
            }
        }
    }

    return $data;
}

hooks()->add_action('after_invoice_added', 'cora_payments_sync_invoice_modes_on_save');
hooks()->add_action('after_invoice_updated', 'cora_payments_sync_invoice_modes_on_save');

function cora_payments_sync_invoice_modes_on_save($data)
{
    $invoiceId = is_array($data) ? ($data['id'] ?? ($data['invoice_id'] ?? 0)) : (int)$data;
    if (!$invoiceId) {
        return;
    }

    $CI = &get_instance();
    if (!isset($CI->db) || !method_exists($CI->db, 'where')) {
        return;
    }

    $invoice = $CI->db->select('id, allowed_payment_modes')->where('id', (int)$invoiceId)->get(db_prefix() . 'invoices')->row();
    if (!$invoice || empty($invoice->allowed_payment_modes)) {
        return;
    }

    $allowed = @unserialize($invoice->allowed_payment_modes);
    if (!is_array($allowed)) {
        return;
    }

    $pm = $CI->db->where('name', 'Pix Banco Cora')->or_like('name', 'Pix Banco Cora', 'both')->get(db_prefix() . 'payment_modes')->row();
    if (!$pm) {
        return;
    }
    $modeIdStr = (string)$pm->id;

    $modified = false;
    if (in_array('cora_pix', $allowed)) {
        if (!in_array($modeIdStr, $allowed) && !in_array((int)$pm->id, $allowed)) {
            $allowed[] = $modeIdStr;
            $modified = true;
        }
    } else {
        if (in_array($modeIdStr, $allowed) || in_array((int)$pm->id, $allowed)) {
            $allowed = array_values(array_diff($allowed, [$modeIdStr, (int)$pm->id]));
            $modified = true;
        }
    }

    if ($modified) {
        $CI->db->where('id', $invoice->id)->update(db_prefix() . 'invoices', [
            'allowed_payment_modes' => serialize(array_values(array_unique($allowed))),
        ]);
    }
}

/**
 * Formata número de telefone brasileiro para exibição amigável
 */
function cora_payments_format_phone($phone)
{
    $digits = preg_replace('/\D/', '', (string)$phone);
    if (strlen($digits) >= 12 && substr($digits, 0, 2) === '55') {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 11) {
        return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7);
    } elseif (strlen($digits) === 10) {
        return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 4) . '-' . substr($digits, 6);
    }
    return $phone;
}

/**
 * Gera URL oficial do WhatsApp com mensagem pré-formatada
 */
function cora_payments_get_wa_url($phone, $text = '')
{
    $digits = preg_replace('/\D/', '', (string)$phone);
    if (empty($digits)) {
        return '';
    }
    if (strlen($digits) <= 11) {
        $digits = '55' . $digits;
    }
    $url = 'https://wa.me/' . $digits;
    if (!empty($text)) {
        $url .= '?text=' . urlencode($text);
    }
    return $url;
}

/**
 * Gera e armazena em cache local o arquivo PNG do QR Code para exibição nativa no TCPDF
 */
function cora_payments_get_qr_image_path($pixPayload, $id = 'generic')
{
    $tempDir = defined('TEMP_FOLDER') ? TEMP_FOLDER : (defined('FCPATH') ? FCPATH . 'uploads/temp/' : sys_get_temp_dir() . '/');
    if (!is_dir($tempDir) || !is_writable($tempDir)) {
        $tempDir = sys_get_temp_dir() . '/';
    }
    $tempFile = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . 'cora_qr_' . $id . '.png';
    $normalized = str_replace('\\', '/', $tempFile);

    // Se o arquivo já existe e foi modificado há menos de 24 horas, reutiliza
    if (file_exists($tempFile) && filesize($tempFile) > 100 && (time() - filemtime($tempFile) < 86400)) {
        return $normalized;
    }

    $pngData = '';

    // Método 1: TCPDF2DBarcode nativo do Perfex CRM (se GD estiver ativo)
    if (extension_loaded('gd')) {
        if (!class_exists('TCPDF2DBarcode', false) && defined('APPPATH')) {
            $tcpdfBarcode = APPPATH . 'libraries/pdf/tcpdf/tcpdf_barcodes_2d.php';
            if (file_exists($tcpdfBarcode)) {
                @require_once($tcpdfBarcode);
            }
        }
        if (class_exists('TCPDF2DBarcode')) {
            try {
                $barcode = new TCPDF2DBarcode($pixPayload, 'QRCODE,M');
                $pngData = $barcode->getBarcodePngData(4, 4);
            } catch (Exception $e) {}
        }
    }

    // Método 2: Fallback via API pública de QR Code (sem dependência de GD)
    if (empty($pngData) || strpos($pngData, "\x89PNG") !== 0) {
        $apis = [
            'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($pixPayload),
            'https://chart.googleapis.com/chart?chs=250x250&cht=qr&chl=' . urlencode($pixPayload),
        ];
        foreach ($apis as $apiUrl) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 4],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);
            $data = @file_get_contents($apiUrl, false, $ctx);
            if (!empty($data) && strpos($data, "\x89PNG") === 0) {
                $pngData = $data;
                break;
            }
        }
    }

    if (!empty($pngData) && strpos($pngData, "\x89PNG") === 0) {
        @file_put_contents($tempFile, $pngData);
        if (file_exists($tempFile) && filesize($tempFile) > 0) {
            return $normalized;
        }
    }

    // Método 3: Fallback de URL direta caso escrita em disco não seja permitida
    return 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($pixPayload);
}

/**
 * Sincronização Automática com tblpayment_modes para exibição das instruções Pix no PDF
 * Atualiza description e show_on_pdf para que o Perfex CRM imprima nativamente
 * o QR Code, dados bancários e link clicável do WhatsApp no PDF da fatura.
 */
hooks()->add_action('app_init', 'cora_payments_sync_payment_modes');
hooks()->add_action('before_payment_gateways_initialize', 'cora_payments_sync_payment_modes');

function cora_payments_sync_payment_modes($invoice = null)
{
    $CI = &get_instance();
    if (!isset($CI->db) || !method_exists($CI->db, 'table_exists')) {
        return 0;
    }

    if (!$CI->db->table_exists(db_prefix() . 'payment_modes')) {
        return 0;
    }

    $pixKey = trim((string)get_option('paymentmethod_cora_pix_pix_manual_key'));
    if (empty($pixKey)) {
        $pixKey = trim((string)get_option('paymentmethod_cora_pix_chave_pix'));
    }

    if (empty($pixKey)) {
        return 0;
    }

    $merchantName = trim((string)get_option('paymentmethod_cora_pix_pix_manual_merchant_name')) ?: get_option('companyname') ?: 'EMPRESA';
    $merchantCity = trim((string)get_option('paymentmethod_cora_pix_pix_manual_merchant_city')) ?: 'BRASÍLIA';
    $whatsapp     = trim((string)get_option('paymentmethod_cora_pix_pix_manual_whatsapp'));
    $customInst   = trim((string)get_option('paymentmethod_cora_pix_pix_manual_instructions'));

    // Detecta fatura corrente a partir do contexto se não foi informada
    if (!$invoice) {
        if (isset($CI->uri)) {
            $s1 = $CI->uri->segment(1);
            $s2 = $CI->uri->segment(2);
            $s3 = $CI->uri->segment(3);
            $s4 = $CI->uri->segment(4);

            if ($s1 === 'invoice' && is_numeric($s2)) {
                $invoice = $CI->db->where('id', (int)$s2)->get(db_prefix() . 'invoices')->row();
            } elseif ($s1 === 'admin' && $s2 === 'invoices' && is_numeric($s4)) {
                $invoice = $CI->db->where('id', (int)$s4)->get(db_prefix() . 'invoices')->row();
            } elseif ($s1 === 'clients' && $s2 === 'invoice' && is_numeric($s3)) {
                $invoice = $CI->db->where('id', (int)$s3)->get(db_prefix() . 'invoices')->row();
            }
        }
    }

    $amount = 0.0;
    $invoiceNum = '';
    $invoiceId = 'generic';

    if ($invoice && is_object($invoice) && isset($invoice->id)) {
        $invoiceId = (int)$invoice->id;
        $amount = (float)($invoice->total_left_to_pay ?? $invoice->total);
        $invoiceNum = function_exists('format_invoice_number') ? format_invoice_number($invoice->id) : (string)$invoice->id;
    }

    // Busca ou gera o código Copia e Cola da transação
    $pixPayload = '';
    if ($invoice && is_object($invoice) && isset($invoice->id)) {
        $CI->db->where('invoice_id', $invoice->id);
        $CI->db->where('type', 'PIX');
        $CI->db->where_in('status', ['PENDING', 'ATIVA']);
        $CI->db->order_by('id', 'DESC');
        $tx = $CI->db->get(db_prefix() . 'cora_transactions')->row();
        if ($tx && !empty($tx->pix_copia_cola)) {
            $pixPayload = $tx->pix_copia_cola;
        }
    }

    if (empty($pixPayload)) {
        if (!class_exists('Pix_payload', false)) {
            require_once(__DIR__ . '/libraries/Pix_payload.php');
        }
        $keyType = get_option('paymentmethod_cora_pix_pix_manual_key_type');
        if (empty($keyType) || $keyType === 'auto') {
            $keyType = Pix_payload::detect_key_type($pixKey);
        }
        $txid = ($invoice && isset($invoice->id))
            ? substr('FAT' . $invoice->id . strtoupper(substr(md5(uniqid((string)$invoice->id, true)), 0, 8)), 0, 25)
            : '***';
        $desc = !empty($invoiceNum) ? ('Fatura #' . $invoiceNum) : '';

        $pixPayload = Pix_payload::generate_payload(
            $pixKey,
            $keyType,
            $amount,
            $txid,
            $merchantName,
            $merchantCity,
            $desc
        );

        if ($invoice && isset($invoice->id) && $amount > 0) {
            $CI->db->insert(db_prefix() . 'cora_transactions', [
                'invoice_id'     => (int)$invoice->id,
                'type'           => 'PIX',
                'txid'           => $txid,
                'amount'         => (float)$amount,
                'pix_copia_cola' => $pixPayload,
                'status'         => 'PENDING',
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Formatação amigável do identificador da chave Pix
    $digits = preg_replace('/\D/', '', $pixKey);
    $keyLabel = 'Chave Pix';
    if (strlen($digits) === 14) {
        $keyLabel = 'CNPJ';
    } elseif (strlen($digits) === 11 && strpos($pixKey, '@') === false) {
        $keyLabel = (substr($digits, 2, 1) === '9') ? 'Celular / WhatsApp' : 'CPF';
    } elseif (strpos($pixKey, '@') !== false) {
        $keyLabel = 'E-mail';
    }

    // Caminho da imagem do QR Code
    $qrImgPath = cora_payments_get_qr_image_path($pixPayload, $invoiceId);

    // Formatação do WhatsApp com link direto
    $formattedWa = cora_payments_format_phone($whatsapp);
    $waText = !empty($invoiceNum)
        ? "Olá! Efetuei o pagamento da Fatura #{$invoiceNum} no valor de R$ " . number_format($amount, 2, ',', '.') . " via Pix. Segue o comprovante em anexo:"
        : "Olá! Efetuei o pagamento via Pix. Segue o comprovante:";
    $waUrl = cora_payments_get_wa_url($whatsapp, $waText);

    // Bloco do botão/link de envio de comprovante
    $waBlock = '';
    if (!empty($waUrl)) {
        $waBlock = '
        <table cellpadding="4" cellspacing="0" style="background-color: #16a34a; width: 100%; border-radius: 4px;">
            <tr>
                <td align="center">
                    <a href="' . $waUrl . '" style="color: #ffffff; text-decoration: none; font-weight: bold; font-size: 8.5px;">
                        CLIQUE AQUI PARA ENVIAR COMPROVANTE VIA WHATSAPP: ' . htmlspecialchars($formattedWa) . '
                    </a>
                </td>
            </tr>
        </table>';
    } elseif (!empty($customInst)) {
        $waBlock = '<span style="font-size: 8px; color: #475569;">' . nl2br(htmlspecialchars($customInst)) . '</span>';
    }

    $valorLine = ($amount > 0)
        ? '<b>Valor da Fatura:</b>&nbsp;&nbsp;<span style="font-weight: bold; color: #047857;">R$&nbsp;' . number_format($amount, 2, ',', '.') . '</span><br />'
        : '';

    // Monta o Card Moderno em HTML para o TCPDF
    $description = '
<table cellpadding="8" cellspacing="0" style="width: 100%; border: 1px solid #10b981; background-color: #f8fafc;">
    <tr>
        <td width="30%" align="center" style="vertical-align: top;">
            <img src="' . $qrImgPath . '" width="115" height="115" />
            <br /><br />
            <span style="font-size: 7.5px; color: #64748b;">Aponte a camera do celular ou o app do banco para pagar</span>
        </td>
        <td width="70%" style="font-size: 8.5px; color: #1e293b; line-height: 1.5; vertical-align: top;">
            <strong style="font-size: 11px; color: #059669;">PAGAMENTO INSTANTANEO VIA PIX</strong><br /><br />
            <b>Chave Pix (' . $keyLabel . '):</b>&nbsp;&nbsp;' . htmlspecialchars($pixKey) . '<br />
            <b>Beneficiario:</b>&nbsp;&nbsp;' . htmlspecialchars($merchantName) . '<br />
            <b>Cidade:</b>&nbsp;&nbsp;' . htmlspecialchars($merchantCity) . '<br />
            ' . $valorLine . '<br />
            ' . $waBlock . '
            <br /><br />
            <span style="font-size: 7.5px; color: #475569;"><b>Codigo Pix Copia e Cola:</b></span><br />
            <span style="font-size: 6.5px; color: #334155; font-family: courier, helvetica;">' . htmlspecialchars($pixPayload) . '</span>
        </td>
    </tr>
</table>';

    // Busca se já existe um modo de pagamento no banco com nome Pix Banco Cora ou Pix
    $mode = $CI->db->where('name', 'Pix Banco Cora')
        ->or_like('name', 'Pix Banco Cora', 'both')
        ->get(db_prefix() . 'payment_modes')
        ->row();

    $modeId = 0;
    if ($mode) {
        $CI->db->where('id', $mode->id)->update(db_prefix() . 'payment_modes', [
            'name'                => 'Pix Banco Cora',
            'description'         => $description,
            'show_on_pdf'         => 1,
            'active'              => 1,
            'selected_by_default' => 0, // NUNCA marcar 1 para evitar duplicidade na criação de novas faturas
            'invoices_only'       => 0,
            'expenses_only'       => 0,
        ]);
        $modeId = (int)$mode->id;
    } else {
        $CI->db->insert(db_prefix() . 'payment_modes', [
            'name'                => 'Pix Banco Cora',
            'description'         => $description,
            'show_on_pdf'         => 1,
            'active'              => 1,
            'selected_by_default' => 0, // NUNCA marcar 1
            'invoices_only'       => 0,
            'expenses_only'       => 0,
        ]);
        $modeId = (int)$CI->db->insert_id();
    }

    // Sincronização automática em tblinvoices:
    // Garante que toda fatura com 'cora_pix' ou '$modeId' tenha AMBOS salvos no banco.
    // Isso restaura faturas antigas e garante que o PDF e a tela sempre renderizem o QR code!
    if ($modeId > 0 && $CI->db->table_exists(db_prefix() . 'invoices')) {
        $invoices = $CI->db->select('id, allowed_payment_modes')
            ->where_in('status', [1, 3, 4]) // 1=Não pagas, 3=Parcialmente pagas, 4=Vencidas
            ->get(db_prefix() . 'invoices')
            ->result();

        foreach ($invoices as $inv) {
            $allowed = @unserialize($inv->allowed_payment_modes);
            if (is_array($allowed)) {
                $hasGateway = in_array('cora_pix', $allowed);
                $hasNumeric = (in_array((string)$modeId, $allowed) || in_array($modeId, $allowed));

                if (($hasGateway && !$hasNumeric) || ($hasNumeric && !$hasGateway)) {
                    $allowed[] = (string)$modeId;
                    $allowed[] = 'cora_pix';
                    $CI->db->where('id', $inv->id)->update(db_prefix() . 'invoices', [
                        'allowed_payment_modes' => serialize(array_values(array_unique($allowed))),
                    ]);
                }
            }
        }
    }

    return $modeId;
}

/**
 * Renderização Automática do Pix Card diretamente na Fatura Online (HTML)
 * Permite que o cliente escaneie o QR Code ou copie o código Copia e Cola
 * diretamente na página da fatura em 100% de largura, sem quebras no col-md-6.
 */
hooks()->add_action('app_customers_footer', 'cora_payments_render_invoice_pix_embed');

function cora_payments_render_invoice_pix_embed()
{
    $CI = &get_instance();

    // Verifica se estamos na rota pública de visualização de fatura: /invoice/{id}/{hash}
    if (!isset($CI->uri)) {
        return;
    }

    $segment1  = $CI->uri->segment(1);
    $invoiceId = (int)$CI->uri->segment(2);
    $hash      = (string)$CI->uri->segment(3);

    if ($segment1 !== 'invoice' || empty($invoiceId) || empty($hash)) {
        return;
    }

    if (!class_exists('Invoices_model', false)) {
        $CI->load->model('invoices_model');
    }

    $invoice = $CI->invoices_model->get($invoiceId);
    if (!$invoice || $invoice->hash !== $hash) {
        return;
    }

    // Fatura já paga (2), cancelada (5) ou em rascunho (6)
    if (in_array((int)$invoice->status, [2, 5, 6])) {
        return;
    }

    // Verifica se o gateway cora_pix está ativo
    $gatewayActive = (int)get_option('paymentmethod_cora_pix_active');
    if (!$gatewayActive) {
        return;
    }

    // Sincroniza a fatura atual com o modo de pagamento para que o PDF sempre traga as instruções
    $modeId = cora_payments_sync_payment_modes($invoice);

    // Verifica se o modo Pix está permitido para esta fatura específica
    $allowed = @unserialize($invoice->allowed_payment_modes);
    if (is_array($allowed) && !in_array('cora_pix', $allowed) && !in_array((string)$modeId, $allowed) && !in_array($modeId, $allowed)) {
        return;
    }

    if ($modeId > 0 && is_array($allowed)) {
        if (!in_array((string)$modeId, $allowed) || !in_array('cora_pix', $allowed)) {
            $allowed[] = (string)$modeId;
            $allowed[] = 'cora_pix';
            $CI->db->where('id', $invoice->id)->update(db_prefix() . 'invoices', [
                'allowed_payment_modes' => serialize(array_values(array_unique($allowed))),
            ]);
        }
    }

    // Configuração de exibição direta na fatura (padrão ativado)
    $embedSetting = get_option('paymentmethod_cora_pix_embed_on_invoice');
    if ($embedSetting !== '' && (int)$embedSetting === 0) {
        return;
    }

    // Validação de Moeda: Apenas BRL
    $currencyName = '';
    if (!empty($invoice->currency_name)) {
        $currencyName = $invoice->currency_name;
    } elseif (!empty($invoice->currency) && function_exists('get_currency')) {
        $c = get_currency($invoice->currency);
        if ($c && !empty($c->name)) {
            $currencyName = $c->name;
        }
    }
    if (empty($currencyName) && function_exists('get_base_currency')) {
        $bc = get_base_currency();
        if ($bc && !empty($bc->name)) {
            $currencyName = $bc->name;
        }
    }
    if (strtoupper(trim($currencyName ?: 'BRL')) !== 'BRL') {
        return;
    }

    $amount = (float)($invoice->total_left_to_pay ?? $invoice->total);
    if ($amount <= 0) {
        return;
    }

    $pixKey = trim((string)get_option('paymentmethod_cora_pix_pix_manual_key'));
    if (empty($pixKey)) {
        $pixKey = trim((string)get_option('paymentmethod_cora_pix_chave_pix'));
    }

    if (empty($pixKey)) {
        return;
    }

    $merchantName = trim((string)get_option('paymentmethod_cora_pix_pix_manual_merchant_name')) ?: get_option('companyname') ?: 'EMPRESA';
    $merchantCity = trim((string)get_option('paymentmethod_cora_pix_pix_manual_merchant_city')) ?: 'BRASILIA';
    $whatsapp     = trim((string)get_option('paymentmethod_cora_pix_pix_manual_whatsapp'));

    // Busca ou gera transação ativa no banco
    $CI->db->where('invoice_id', $invoice->id);
    $CI->db->where('type', 'PIX');
    $CI->db->where_in('status', ['PENDING', 'ATIVA']);
    $CI->db->order_by('id', 'DESC');
    $tx = $CI->db->get(db_prefix() . 'cora_transactions')->row();

    if ($tx && !empty($tx->pix_copia_cola)) {
        $pixPayload = $tx->pix_copia_cola;
    } else {
        $txid = 'FAT' . $invoice->id . strtoupper(substr(md5(uniqid((string)$invoice->id, true)), 0, 8));
        if (strlen($txid) > 25) {
            $txid = substr($txid, 0, 25);
        }

        if (!class_exists('Pix_payload', false)) {
            require_once(__DIR__ . '/libraries/Pix_payload.php');
        }

        $keyType = get_option('paymentmethod_cora_pix_pix_manual_key_type');
        if (empty($keyType) || $keyType === 'auto') {
            $keyType = Pix_payload::detect_key_type($pixKey);
        }

        $pixPayload = Pix_payload::generate_payload(
            $pixKey,
            $keyType,
            $amount,
            $txid,
            $merchantName,
            $merchantCity,
            'Fatura #' . format_invoice_number($invoice->id)
        );

        $CI->db->insert(db_prefix() . 'cora_transactions', [
            'invoice_id'     => (int)$invoice->id,
            'type'           => 'PIX',
            'txid'           => $txid,
            'amount'         => (float)$amount,
            'pix_copia_cola' => $pixPayload,
            'status'         => 'PENDING',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    $qrJsUrl         = module_dir_url(CORA_PAYMENTS_MODULE_NAME, 'assets/js/qrcode.min.js');
    $formattedAmount = number_format($amount, 2, ',', '.');
    $cleanWa         = preg_replace('/\D/', '', $whatsapp);
    $invoiceNum      = format_invoice_number($invoice->id);
    $waText          = "Olá! Efetuei o pagamento da Fatura #{$invoiceNum} no valor de R$ {$formattedAmount} via Pix. Segue o comprovante em anexo:";
    $waUrl           = cora_payments_get_wa_url($whatsapp, $waText);
    ?>
    <script src="<?= $qrJsUrl; ?>"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var form         = document.querySelector('#online_payment_form');
        var paymentsDiv  = document.querySelector('.invoice-html-payments');
        var paymentCol12 = form ? form.closest('.col-md-12') : null;

        var cardHtml = `
        <div id="cora-pix-invoice-box" class="panel panel-default" style="border: 2px solid #10b981; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.12), 0 8px 10px -6px rgba(0, 0, 0, 0.04); margin-top: 25px; margin-bottom: 30px; width: 100%;">
            <div class="panel-heading" style="background: linear-gradient(135deg, #059669, #10b981); color: #fff; padding: 16px 24px; font-weight: 700; font-size: 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: none;">
                <span style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa fa-qrcode" style="font-size: 20px;"></i>
                    <span>Pagamento Instantâneo via Pix</span>
                </span>
                <span style="background: rgba(255,255,255,0.22); color: #fff; padding: 5px 14px; border-radius: 20px; font-size: 14px; font-weight: 700;">
                    Valor: R$ <?= $formattedAmount; ?>
                </span>
            </div>
            <div class="panel-body" style="background: #ffffff; padding: 25px 20px;">
                <div class="row" style="display: flex; flex-wrap: wrap; align-items: center; margin: 0;">
                    <div class="col-xs-12 col-sm-4 text-center" style="padding: 10px 15px; margin-bottom: 15px;">
                        <div style="display: inline-block; padding: 12px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 10px rgba(0,0,0,0.06);">
                            <div id="cora-embed-qr" style="width: 180px; height: 180px; margin: 0 auto;"></div>
                        </div>
                        <p class="text-muted" style="font-size: 13px; margin-top: 10px; margin-bottom: 0; font-weight: 500;">
                            <i class="fa fa-camera"></i> Abra o app do seu banco e escaneie o QR Code
                        </p>
                    </div>
                    <div class="col-xs-12 col-sm-8" style="padding: 10px 20px;">
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-weight: 600; color: #1e293b; font-size: 14px;">Pix Copia e Cola:</label>
                            <div class="input-group">
                                <input type="text" id="cora-embed-payload-input" class="form-control input-lg" value="<?= html_escape($pixPayload); ?>" readonly style="font-size: 12px; background: #f8fafc; cursor: pointer; height: 44px;" onclick="this.select();">
                                <span class="input-group-btn">
                                    <button class="btn btn-primary btn-lg" type="button" id="cora-embed-copy-btn" style="background: #10b981; border-color: #10b981; font-weight: 600; height: 44px; padding: 0 20px;">
                                        <i class="fa fa-copy"></i> Copiar Código
                                    </button>
                                </span>
                            </div>
                        </div>

                        <div class="alert alert-info" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 14px 18px; border-radius: 8px; font-size: 13px; margin-bottom: 15px; line-height: 1.7;">
                            <div class="row">
                                <div class="col-xs-12 col-sm-6">
                                    <strong>Chave Pix (CNPJ):</strong> <?= html_escape($pixKey); ?><br>
                                    <strong>Beneficiário:</strong> <?= html_escape($merchantName); ?>
                                </div>
                                <div class="col-xs-12 col-sm-6">
                                    <strong>Cidade:</strong> <?= html_escape($merchantCity); ?><br>
                                    <strong>Valor a Pagar:</strong> <span style="font-weight: 700; color: #047857;">R$ <?= $formattedAmount; ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($waUrl)): ?>
                        <a href="<?= $waUrl; ?>" target="_blank" class="btn btn-block btn-success btn-lg" style="background: #25D366; border-color: #25D366; font-weight: 700; font-size: 14px; padding: 10px 16px; border-radius: 8px; text-decoration: none; box-shadow: 0 4px 10px rgba(37, 211, 102, 0.25);">
                            <i class="fab fa-whatsapp" style="font-size: 18px; vertical-align: middle; margin-right: 5px;"></i> Enviar Comprovante no WhatsApp
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        `;

        // Ponto de injeção na página: SEMPRE em 100% de largura (col-md-12 na raiz da fatura)
        var container = document.createElement('div');
        container.className = 'col-md-12';
        container.style.width = '100%';
        container.style.padding = '0';
        container.innerHTML = cardHtml;

        if (paymentCol12 && paymentCol12.parentNode) {
            // Insere o card Pix em 100% de largura antes do bloco de pagamento
            paymentCol12.parentNode.insertBefore(container, paymentCol12);
        } else if (paymentsDiv && paymentsDiv.parentNode) {
            paymentsDiv.parentNode.insertBefore(container, paymentsDiv);
        } else {
            var bodyRow = document.querySelector('.invoice-html-note') || document.querySelector('.mtop15');
            if (bodyRow && bodyRow.parentNode) {
                bodyRow.parentNode.insertBefore(container, bodyRow);
            }
        }

        // Renderiza o QR Code dinamicamente
        function renderQR() {
            var qrBox = document.getElementById('cora-embed-qr');
            if (qrBox && typeof QRCode !== 'undefined') {
                new QRCode(qrBox, {
                    text: <?= json_encode($pixPayload); ?>,
                    width: 180,
                    height: 180,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            } else {
                setTimeout(renderQR, 100);
            }
        }
        renderQR();

        // Botão de Copiar Código com feedback visual
        var copyBtn = document.getElementById('cora-embed-copy-btn');
        var payloadInput = document.getElementById('cora-embed-payload-input');
        if (copyBtn && payloadInput) {
            copyBtn.addEventListener('click', function() {
                payloadInput.select();
                payloadInput.setSelectionRange(0, 99999);
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(payloadInput.value).then(function() {
                        onCopied();
                    }).catch(function() {
                        document.execCommand('copy');
                        onCopied();
                    });
                } else {
                    document.execCommand('copy');
                    onCopied();
                }

                function onCopied() {
                    var origHtml = copyBtn.innerHTML;
                    copyBtn.innerHTML = '<i class="fa fa-check"></i> Copiado!';
                    copyBtn.style.background = '#16a34a';
                    copyBtn.style.borderColor = '#16a34a';
                    setTimeout(function() {
                        copyBtn.innerHTML = origHtml;
                        copyBtn.style.background = '#10b981';
                        copyBtn.style.borderColor = '#10b981';
                    }, 2500);
                }
            });
        }

        // Se Pix for a única opção online, oculta o bloco redundante de pagamento que ficava no col-md-6
        var radios = document.querySelectorAll('.online-payment-radio');
        if (radios.length <= 1) {
            if (paymentCol12) {
                paymentCol12.style.display = 'none';
            }
            var payNowTop = document.querySelector('.invoice-html-pay-now-top');
            if (payNowTop) {
                payNowTop.style.display = 'none';
            }
        }
    });
    </script>
    <?php
}
