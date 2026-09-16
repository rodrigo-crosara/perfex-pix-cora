<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cora_pix_gateway
 * 
 * Gateway nativo do Perfex CRM para recebimento instantâneo via Pix Banco Cora.
 * Utiliza autenticação mTLS e a biblioteca unificada Cora_api.
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

        // URL do Webhook oficial (Liberado nativamente de CSRF pelo Perfex)
        $webhookUrl = site_url('gateways/cora/webhook');

        // Diagnóstico dos certificados
        $diag = $this->cora_api->diagnosticar_certificados('cora_pix');

        $certInfo = '<p class="text-muted">Cole o conteúdo textual completo do certificado público (.pem ou .crt), incluindo as linhas -----BEGIN CERTIFICATE----- e -----END CERTIFICATE-----.</p>';
        if (!empty($this->getSetting('cert_content')) || !empty($this->cora_api->get_setting('cert_content', 'cora_pix'))) {
            if ($diag['cert_valido']) {
                $certInfo .= '<div class="alert alert-success mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-check-circle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
            } else {
                $certInfo .= '<div class="alert alert-danger mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-exclamation-triangle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
            }
        }

        $keyInfo = '<p class="text-muted">Cole o conteúdo textual da sua chave privada (.key), incluindo as linhas -----BEGIN RSA PRIVATE KEY----- ou -----BEGIN PRIVATE KEY-----.</p>';
        if (!empty($this->getSetting('key_content')) || !empty($this->cora_api->get_setting('key_content', 'cora_pix'))) {
            if ($diag['key_valida']) {
                $keyInfo .= '<div class="alert alert-success mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-check-circle"></i> <strong>' . html_escape($diag['key_mensagem']) . '</strong></div>';
            } else {
                $keyInfo .= '<div class="alert alert-danger mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-exclamation-triangle"></i> <strong>' . html_escape($diag['key_mensagem']) . '</strong></div>';
            }
        }

        $this->setSettings([
            [
                'name'  => 'client_id',
                'type'  => 'input',
                'label' => 'Client ID (Cora)',
                'info'  => '<p class="text-muted">Client ID fornecido no portal Cora Developers.</p>',
            ],
            [
                'name'  => 'chave_pix',
                'type'  => 'input',
                'label' => 'Chave Pix (Cora)',
                'info'  => '<p class="text-muted">Chave Pix cadastrada na sua conta Cora (CNPJ, E-mail, Telefone ou Aleatória).</p>',
            ],
            [
                'name'  => 'cert_content',
                'type'  => 'textarea',
                'label' => 'Certificado mTLS (.pem ou .crt)',
                'info'  => $certInfo,
                'rows'  => 6,
            ],
            [
                'name'  => 'key_content',
                'type'  => 'textarea',
                'label' => 'Chave Privada mTLS (.key)',
                'info'  => $keyInfo,
                'rows'  => 6,
            ],
            [
                'name'          => 'sandbox',
                'type'          => 'yes_no',
                'label'         => 'Ambiente de Testes (Sandbox / Stage)',
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
                'label'            => 'URL do Webhook Oficial',
                'default_value'    => $webhookUrl,
                'field_attributes' => ['readonly' => 'readonly', 'onclick' => 'this.select();'],
                'info'             => '<p class="text-info"><i class="fa fa-info-circle"></i> Cadastre esta URL no portal Cora Developers para conciliação automática.</p>',
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

        // Validação Fiscal: Documento (CPF ou CNPJ) - Preservação estrita de zeros como string
        $doc = (string) preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            set_alert('danger', 'O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para pagar com Pix.');
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
