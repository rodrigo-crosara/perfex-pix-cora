<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cora_pix_gateway
 * 
 * Gateway nativo do Perfex CRM para recebimento via Pix.
 * Suporta dois modos de operação:
 * 1. Automático (API Cora Pro): Cobranças dinâmicas via mTLS e conciliação atômica por Webhook.
 * 2. Modo Manual (Pix Estático sem API): BR Code (QR Code e Copia e Cola) EMVCo com CRC16
 *    sem exigência do plano Cora Pro nem certificados digitais, com baixa manual pela equipe financeira.
 */
class Cora_pix_gateway extends App_gateway
{
    /**
     * Instância do helper central Cora_api
     * @var Cora_api
     */
    public $cora_api;

    public function __construct()
    {
        parent::__construct();

        $this->setId('cora_pix');
        $this->setName('Pix Banco Cora');

        // Carrega a biblioteca central compartilhada
        $this->ci->load->library('cora_payments/cora_api');
        $this->cora_api = $this->ci->cora_api;

        // Carrega o helper de payload Pix EMVCo
        $this->ci->load->library('cora_payments/pix_payload');

        // URL do Webhook oficial (Liberado nativamente de CSRF pelo Perfex)
        $webhookUrl = site_url('gateways/cora/webhook');

        // Diagnóstico dos certificados (apenas se preenchidos)
        $certRaw = $this->getSetting('cert_content') ?: $this->cora_api->get_setting('cert_content', 'cora_pix');
        $keyRaw  = $this->getSetting('key_content') ?: $this->cora_api->get_setting('key_content', 'cora_pix');

        $certInfo = '<p class="text-muted">Cole o conteúdo do certificado (.pem ou .crt). <em>Obrigatório apenas no Modo Automático (API Cora Pro).</em></p>';
        $keyInfo  = '<p class="text-muted">Cole o conteúdo da sua chave privada (.key). <em>Obrigatório apenas no Modo Automático (API Cora Pro).</em></p>';

        if (!empty($certRaw) || !empty($keyRaw)) {
            $diag = $this->cora_api->diagnosticar_certificados('cora_pix');
            if (!empty($certRaw)) {
                if ($diag['cert_valido']) {
                    $certInfo .= '<div class="alert alert-success mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-check-circle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
                } else {
                    $certInfo .= '<div class="alert alert-danger mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-exclamation-triangle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
                }
            }
            if (!empty($keyRaw)) {
                if ($diag['key_valida']) {
                    $keyInfo .= '<div class="alert alert-success mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-check-circle"></i> <strong>' . html_escape($diag['key_mensagem']) . '</strong></div>';
                } else {
                    $keyInfo .= '<div class="alert alert-danger mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-exclamation-triangle"></i> <strong>' . html_escape($diag['key_mensagem']) . '</strong></div>';
                }
            }
        }

        $this->setSettings([
            [
                'name'          => 'operation_mode',
                'type'          => 'select',
                'label'         => 'Modo de Operação do Pix',
                'default_value' => 'api_cora',
                'options'       => [
                    [
                        'id'   => 'api_cora',
                        'name' => 'Modo Automático (API Cora Pro com mTLS e Baixa por Webhook)',
                    ],
                    [
                        'id'   => 'manual',
                        'name' => 'Modo Pix Manual (Sem API Cora - QR Code Estático e Baixa Manual)',
                    ],
                ],
                'info'          => '<p class="text-info"><i class="fa fa-info-circle"></i> <strong>Dica de Contingência:</strong> Caso sua conta Cora não possua o plano Cora Pro ativo, selecione <strong>Modo Pix Manual</strong>. O sistema gerará o QR Code com o valor exato da fatura e permitirá baixa manual pela sua equipe financeira após envio do comprovante.</p>',
            ],
            [
                'name'          => 'pix_manual_key_type',
                'type'          => 'select',
                'label'         => '[Modo Manual] Tipo da Chave Pix',
                'default_value' => 'cnpj',
                'options'       => [
                    ['id' => 'cnpj',      'name' => 'CNPJ'],
                    ['id' => 'cpf',       'name' => 'CPF'],
                    ['id' => 'email',     'name' => 'E-mail'],
                    ['id' => 'phone',     'name' => 'Telefone (Celular com DDD)'],
                    ['id' => 'aleatoria', 'name' => 'Chave Aleatória (EVP)'],
                ],
                'info'          => '<p class="text-muted">Selecione o formato da sua chave Pix no banco.</p>',
            ],
            [
                'name'  => 'pix_manual_key',
                'type'  => 'input',
                'label' => '[Modo Manual] Chave Pix',
                'info'  => '<p class="text-muted">Informe sua chave Pix (ex: CNPJ sem pontuação, telefone com DDD, e-mail ou chave aleatória).</p>',
            ],
            [
                'name'  => 'pix_manual_merchant_name',
                'type'  => 'input',
                'label' => '[Modo Manual] Nome do Titular da Conta',
                'info'  => '<p class="text-muted">Nome do titular da conta bancária (máx. 25 caracteres, sem acentos, ex: SUA EMPRESA LTDA). Se vazio, usa o nome da empresa no Perfex.</p>',
            ],
            [
                'name'          => 'pix_manual_merchant_city',
                'type'          => 'input',
                'label'         => '[Modo Manual] Cidade do Titular da Conta',
                'default_value' => 'SAO PAULO',
                'info'          => '<p class="text-muted">Cidade onde a conta bancária está sediada (máx. 15 caracteres, sem acentos, ex: SAO PAULO).</p>',
            ],
            [
                'name'          => 'pix_manual_instructions',
                'type'          => 'textarea',
                'label'         => '[Modo Manual] Instruções de Pagamento e Envio do Comprovante',
                'default_value' => 'Após efetuar o pagamento via Pix, favor enviar o comprovante informando o número da sua fatura para nossa equipe financeira registrar a baixa.',
                'info'          => '<p class="text-muted">Instruções visíveis ao cliente na tela de pagamento para envio do comprovante.</p>',
                'rows'          => 4,
            ],
            [
                'name'  => 'pix_manual_whatsapp',
                'type'  => 'input',
                'label' => '[Modo Manual] WhatsApp Financeiro para Comprovantes (Opcional)',
                'info'  => '<p class="text-muted">Número com DDD (ex: 11999998888). Exibe na tela do cliente um botão para envio direto do comprovante via WhatsApp com mensagem pré-preenchida.</p>',
            ],
            [
                'name'  => 'client_id',
                'type'  => 'input',
                'label' => '[API Cora Pro] Client ID',
                'info'  => '<p class="text-muted">Client ID fornecido no portal Cora Developers (Obrigatório apenas no Modo Automático).</p>',
            ],
            [
                'name'  => 'chave_pix',
                'type'  => 'input',
                'label' => '[API Cora Pro] Chave Pix (Cora)',
                'info'  => '<p class="text-muted">Chave Pix cadastrada na sua conta Cora vinculada à API para cobranças dinâmicas.</p>',
            ],
            [
                'name'  => 'cert_content',
                'type'  => 'textarea',
                'label' => '[API Cora Pro] Certificado mTLS (.pem ou .crt)',
                'info'  => $certInfo,
                'rows'  => 6,
            ],
            [
                'name'  => 'key_content',
                'type'  => 'textarea',
                'label' => '[API Cora Pro] Chave Privada mTLS (.key)',
                'info'  => $keyInfo,
                'rows'  => 6,
            ],
            [
                'name'          => 'sandbox',
                'type'          => 'yes_no',
                'label'         => '[API Cora Pro] Ambiente de Testes (Sandbox / Stage)',
                'default_value' => 0,
                'info'          => '<p class="text-muted">Ative para testes em https://matls-clients.stage.cora.com.br. Desative para Produção.</p>',
            ],
            [
                'name'          => 'expiration_minutes',
                'type'          => 'input',
                'label'         => 'Tempo de Expiração do Pix (minutos)',
                'default_value' => '1440',
                'info'          => '<p class="text-muted">Padrão: 1440 minutos (24 horas).</p>',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'settings_paymentmethod_currencies',
                'default_value' => 'BRL',
            ],
            [
                'name'             => 'webhook_url_display',
                'type'             => 'input',
                'label'            => 'URL do Webhook Oficial (API Cora Pro)',
                'default_value'    => $webhookUrl,
                'field_attributes' => ['readonly' => 'readonly', 'onclick' => 'this.select();'],
                'info'             => '<p class="text-info"><i class="fa fa-info-circle"></i> Cadastre esta URL no portal Cora Developers para conciliação automática via API.</p>',
            ],
        ]);
    }

    /**
     * Processa o pagamento iniciado pelo cliente no Perfex CRM
     *
     * @param array $data Dados com fatura e valor
     */
    public function process_payment($data)
    {
        $invoice = $data['invoice'];
        $amount  = (float)$data['amount'];

        // 0. Bloqueio de Faturas em Rascunho (STATUS_DRAFT = 6)
        $statusDraft = defined('Invoices_model::STATUS_DRAFT') ? Invoices_model::STATUS_DRAFT : 6;
        if ((int)$invoice->status === (int)$statusDraft) {
            set_alert('warning', 'Esta fatura ainda se encontra em rascunho e não pode receber pagamentos.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // Validação de Moeda: Apenas BRL é permitido
        $currency = isset($invoice->currency_name) ? $invoice->currency_name : 'BRL';
        if (strtoupper(trim($currency)) !== 'BRL') {
            set_alert('warning', 'O Pix está disponível apenas para faturas emitidas em Reais (BRL).');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        $operationMode = $this->getSetting('operation_mode') ?: 'api_cora';

        // =========================================================================
        // MODO MANUAL (Pix Estático sem API Cora / Sem plano Pro)
        // =========================================================================
        if ($operationMode === 'manual') {
            try {
                $pixKey = trim((string)$this->getSetting('pix_manual_key'));
                if (empty($pixKey)) {
                    $pixKey = trim((string)$this->getSetting('chave_pix'));
                }

                if (empty($pixKey)) {
                    set_alert('danger', 'A Chave Pix do Modo Manual não foi configurada nas opções do módulo pelo administrador.');
                    redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
                    return;
                }

                $keyType      = $this->getSetting('pix_manual_key_type') ?: 'cnpj';
                $merchantName = trim((string)$this->getSetting('pix_manual_merchant_name'));
                if (empty($merchantName)) {
                    $merchantName = get_option('companyname') ?: 'EMPRESA';
                }
                $merchantCity = trim((string)$this->getSetting('pix_manual_merchant_city')) ?: 'SAO PAULO';

                // Verifica se já existe uma transação Pix ativa recente para o mesmo valor
                $this->ci->db->where('invoice_id', $invoice->id);
                $this->ci->db->where('type', 'PIX');
                $this->ci->db->where_in('status', ['PENDING', 'ATIVA']);
                $this->ci->db->where('amount', $amount);
                $this->ci->db->order_by('id', 'DESC');
                $existing = $this->ci->db->get(db_prefix() . 'cora_transactions')->row();

                if ($existing && !empty($existing->pix_copia_cola)) {
                    $createdAt  = strtotime($existing->created_at);
                    $expMinutes = (int)($this->getSetting('expiration_minutes') ?: 1440);
                    if ((time() - $createdAt) < ($expMinutes * 60)) {
                        redirect(site_url('cora_payments/cora/pay/' . $invoice->id . '/' . $invoice->hash . '/' . $existing->txid));
                        return;
                    }
                }

                // Gera txid único com até 25 caracteres para conformidade com EMVCo e chave única no banco
                $txid = 'FAT' . $invoice->id . strtoupper(substr(md5(uniqid((string)$invoice->id, true)), 0, 8));
                if (strlen($txid) > 25) {
                    $txid = substr($txid, 0, 25);
                }

                $description = 'Fatura #' . format_invoice_number($invoice->id);

                if (!class_exists('Pix_payload', false)) {
                    $this->ci->load->library('cora_payments/pix_payload');
                }

                $pixPayload = Pix_payload::generate_payload(
                    $pixKey,
                    $keyType,
                    $amount,
                    $txid,
                    $merchantName,
                    $merchantCity,
                    $description
                );

                // Persiste na tabela unificada cora_transactions
                $this->ci->db->insert(db_prefix() . 'cora_transactions', [
                    'invoice_id'     => (int)$invoice->id,
                    'type'           => 'PIX',
                    'txid'           => $txid,
                    'amount'         => (float)$amount,
                    'pix_copia_cola' => $pixPayload,
                    'status'         => 'PENDING',
                    'created_at'     => date('Y-m-d H:i:s'),
                    'paid_at'        => null,
                ]);

                set_alert('success', 'Código Pix gerado com sucesso! Efetue o pagamento e envie o comprovante.');
                redirect(site_url('cora_payments/cora/pay/' . $invoice->id . '/' . $invoice->hash . '/' . $txid));
                return;
            } catch (Exception $e) {
                log_activity('Falha no processamento Pix Manual para Fatura #' . $invoice->id . ': ' . $e->getMessage());
                set_alert('danger', 'Não foi possível gerar a cobrança Pix: ' . $e->getMessage());
                redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
                return;
            }
        }

        // =========================================================================
        // MODO AUTOMÁTICO (API Cora Pro via mTLS)
        // =========================================================================
        // Validação Fiscal: Documento (CPF ou CNPJ) - Preservação estrita de zeros como string
        $doc = (string) preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            set_alert('danger', 'O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para pagar com Pix via API Cora.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        try {
            // Verifica se já existe uma transação Pix ativa recente para o mesmo valor
            $this->ci->db->where('invoice_id', $invoice->id);
            $this->ci->db->where('type', 'PIX');
            $this->ci->db->where_in('status', ['PENDING', 'ATIVA']);
            $this->ci->db->where('amount', $amount);
            $this->ci->db->order_by('id', 'DESC');
            $existing = $this->ci->db->get(db_prefix() . 'cora_transactions')->row();

            if ($existing) {
                $createdAt = strtotime($existing->created_at);
                $expMinutes = (int)($this->getSetting('expiration_minutes') ?: 1440);
                if ((time() - $createdAt) < ($expMinutes * 60)) {
                    redirect(site_url('cora_payments/cora/pay/' . $invoice->id . '/' . $invoice->hash . '/' . $existing->txid));
                    return;
                }
            }

            // Cria cobrança Pix via helper central
            $pixData = $this->cora_api->criar_pix($invoice, $amount);

            // Persiste na tabela unificada
            $this->ci->db->insert(db_prefix() . 'cora_transactions', [
                'invoice_id'     => (int)$invoice->id,
                'type'           => 'PIX',
                'txid'           => $pixData['txid'],
                'amount'         => (float)$amount,
                'pix_copia_cola' => $pixData['pix_copia_cola'],
                'status'         => 'PENDING',
                'created_at'     => date('Y-m-d H:i:s'),
                'paid_at'        => null,
            ]);

            set_alert('success', 'Código Pix gerado com sucesso! Efetue o pagamento via QR Code ou Copia e Cola.');
            redirect(site_url('cora_payments/cora/pay/' . $invoice->id . '/' . $invoice->hash . '/' . $pixData['txid']));
        } catch (Exception $e) {
            log_activity('Falha no processamento Pix Cora para Fatura #' . $invoice->id . ': ' . $e->getMessage());
            set_alert('danger', 'Não foi possível gerar a cobrança Pix: ' . $e->getMessage());
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }
    }
}
