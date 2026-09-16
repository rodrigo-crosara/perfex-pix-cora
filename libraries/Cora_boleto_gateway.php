<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cora_boleto_gateway
 * 
 * Gateway nativo do Perfex CRM para emissão de Boletos Bancários Híbridos Cora (com Pix QR Code).
 * Suporta configuração de juros de mora, multa percentual, prazo de cancelamento e redirecionamento direto
 * ao PDF oficial gerado pelo Banco Cora.
 */
class Cora_boleto_gateway extends App_gateway
{
    /**
     * Instância do helper central Cora_api
     * @var Cora_api
     */
    public $cora_api;

    public function __construct()
    {
        parent::__construct();

        $this->setId('cora_boleto');
        $this->setName('Boleto Bancário Cora (com Pix)');

        // Carrega a biblioteca central compartilhada
        $this->ci->load->library('cora_payments/cora_api');
        $this->cora_api = $this->ci->cora_api;

        $webhookUrl = site_url('cora_payments/cora/webhook');

        // Diagnóstico dos certificados
        $diag = $this->cora_api->diagnosticar_certificados('cora_boleto');

        $certInfo = '<p class="text-muted">Cole o certificado (.pem ou .crt). <em>Se deixar em branco, o sistema utilizará automaticamente as credenciais configuradas na aba Pix Banco Cora.</em></p>';
        if (!empty($this->getSetting('cert_content')) || !empty($this->cora_api->get_setting('cert_content', 'cora_boleto'))) {
            if ($diag['cert_valido']) {
                $certInfo .= '<div class="alert alert-success mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-check-circle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
            } else {
                $certInfo .= '<div class="alert alert-danger mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-exclamation-triangle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
            }
        }

        $keyInfo = '<p class="text-muted">Cole a chave privada (.key). <em>Se deixar em branco, o sistema utilizará a chave configurada na aba Pix Banco Cora.</em></p>';
        if (!empty($this->getSetting('key_content')) || !empty($this->cora_api->get_setting('key_content', 'cora_boleto'))) {
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
                'info'  => '<p class="text-muted">Deixe vazio se já estiver configurado no gateway Pix (compartilhamento automático).</p>',
            ],
            [
                'name'  => 'cert_content',
                'type'  => 'textarea',
                'label' => 'Certificado mTLS (.pem ou .crt)',
                'info'  => $certInfo,
                'rows'  => 5,
            ],
            [
                'name'  => 'key_content',
                'type'  => 'textarea',
                'label' => 'Chave Privada mTLS (.key)',
                'info'  => $keyInfo,
                'rows'  => 5,
            ],
            [
                'name'          => 'sandbox',
                'type'          => 'yes_no',
                'label'         => 'Ambiente de Testes (Sandbox / Stage)',
                'default_value' => 0,
                'info'          => '<p class="text-muted">Ative para testes em stage.cora.com.br. Desative para Produção.</p>',
            ],
            [
                'name'          => 'multa_percentual',
                'type'          => 'input',
                'label'         => 'Multa após o vencimento (%)',
                'default_value' => '2.00',
                'info'          => '<p class="text-muted">Percentual de multa aplicado após o vencimento (Ex: 2.00 para 2%). Deixe 0 para não cobrar multa.</p>',
            ],
            [
                'name'          => 'juros_mensal_percentual',
                'type'          => 'input',
                'label'         => 'Juros de mora ao mês (%)',
                'default_value' => '1.00',
                'info'          => '<p class="text-muted">Percentual de juros de mora ao mês (Ex: 1.00 para 1% a.m.). Deixe 0 para não cobrar juros.</p>',
            ],
            [
                'name'          => 'dias_cancelamento',
                'type'          => 'input',
                'label'         => 'Dias para cancelamento após vencimento',
                'default_value' => '29',
                'info'          => '<p class="text-muted">Quantidade de dias corridos após o vencimento para expiração/baixa do boleto (padrão bancário: 29 dias).</p>',
            ],
            [
                'name'          => 'redirect_mode',
                'type'          => 'select',
                'label'         => 'Modo de Redirecionamento após Emissão',
                'default_value' => 'pdf',
                'options'       => [
                    ['id' => 'pdf',  'name' => 'Redirecionar diretamente para o PDF Oficial da Cora'],
                    ['id' => 'view', 'name' => 'Exibir página interna com código de barras, QR Code Pix e download'],
                ],
                'info'          => '<p class="text-muted">Escolha se o cliente é enviado diretamente para o PDF do boleto ou para a tela interativa do módulo.</p>',
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
                'info'             => '<p class="text-info"><i class="fa fa-info-circle"></i> URL utilizada para conciliação automática de boletos e Pix.</p>',
            ],
        ]);
    }

    /**
     * Processa o pagamento via Boleto Bancário Híbrido
     *
     * @param array $data Dados contendo 'invoice', 'amount'
     */
    public function process_payment($data)
    {
        $invoice = $data['invoice'];
        $amount  = (float)$data['amount'];

        // 1. Validação de Moeda: Apenas BRL é suportado
        $currency = isset($invoice->currency_name) ? $invoice->currency_name : 'BRL';
        if (strtoupper(trim($currency)) !== 'BRL') {
            set_alert('warning', 'O Boleto Bancário Cora está disponível apenas para faturas emitidas em Reais (BRL).');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // 2. Validação Fiscal: Documento (CPF ou CNPJ)
        $doc = preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            set_alert('danger', 'O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para emitir o Boleto Bancário.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        try {
            // Verifica se já existe um boleto gerado para a fatura que ainda esteja pendente e com PDF válido
            $this->ci->db->where('invoice_id', $invoice->id);
            $this->ci->db->where('type', 'BOLETO');
            $this->ci->db->where_in('status', ['PENDING', 'OPEN', 'ATIVA']);
            $this->ci->db->where('amount', $amount);
            $this->ci->db->order_by('id', 'DESC');
            $existing = $this->ci->db->get(db_prefix() . 'cora_transactions')->row();

            $redirectMode = $this->getSetting('redirect_mode') ?: 'pdf';

            if ($existing && !empty($existing->pdf_url)) {
                $createdAt = strtotime($existing->created_at);
                // Se o boleto foi criado há menos de 7 dias, reutiliza
                if ((time() - $createdAt) < (7 * 86400)) {
                    if ($redirectMode === 'pdf') {
                        redirect($existing->pdf_url);
                        return;
                    } else {
                        redirect(site_url('cora_payments/cora/boleto/' . $invoice->id . '/' . $existing->txid));
                        return;
                    }
                }
            }

            // Emite novo boleto híbrido na Cora
            $customOptions = [
                'multa'              => (float)$this->getSetting('multa_percentual'),
                'juros'              => (float)$this->getSetting('juros_mensal_percentual'),
                'dias_cancelamento'  => (int)$this->getSetting('dias_cancelamento'),
            ];

            $boleto = $this->cora_api->criar_boleto($invoice, $amount, $customOptions);

            // Persiste na tabela unificada cora_transactions
            $this->ci->db->insert(db_prefix() . 'cora_transactions', [
                'invoice_id'      => (int)$invoice->id,
                'type'            => 'BOLETO',
                'txid'            => $boleto['txid'],
                'cora_invoice_id' => $boleto['cora_invoice_id'],
                'amount'          => (float)$amount,
                'barcode'         => $boleto['barcode'],
                'pdf_url'         => $boleto['pdf_url'],
                'pix_copia_cola'  => $boleto['pix_copia_cola'],
                'status'          => 'PENDING',
                'created_at'      => date('Y-m-d H:i:s'),
                'paid_at'         => null,
            ]);

            set_alert('success', 'Boleto Bancário emitido com sucesso!');

            // Redirecionamento conforme preferência do administrador
            if ($redirectMode === 'pdf' && !empty($boleto['pdf_url'])) {
                redirect($boleto['pdf_url']);
                return;
            }

            redirect(site_url('cora_payments/cora/boleto/' . $invoice->id . '/' . $boleto['txid']));
        } catch (Exception $e) {
            log_activity('Falha na emissão de Boleto Cora para Fatura #' . $invoice->id . ': ' . $e->getMessage());
            set_alert('danger', 'Não foi possível gerar o Boleto Bancário: ' . $e->getMessage());
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }
    }
}
