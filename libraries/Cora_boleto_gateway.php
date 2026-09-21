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
     * Instância do CodeIgniter
     * @var object
     */
    protected $ci;

    /**
     * Instância do helper central Cora_api
     * @var Cora_api
     */
    public $cora_api;

    public function __construct()
    {
        parent::__construct();

        $this->ci = &get_instance();

        $this->setId('cora_boleto');
        $this->setName('Boleto Bancário Cora (com Pix)');

        // Carrega a biblioteca central compartilhada
        $moduleName = defined('CORA_PAYMENTS_MODULE_NAME') ? CORA_PAYMENTS_MODULE_NAME : 'cora_payments';
        $this->ci->load->library($moduleName . '/cora_api');
        $this->cora_api = $this->ci->cora_api;

        $webhookUrl = site_url('gateways/cora/webhook');

        // Diagnóstico dos certificados (apenas se preenchidos)
        $certRaw = $this->getSetting('cert_content') ?: $this->cora_api->get_setting('cert_content', 'cora_boleto');
        $keyRaw  = $this->getSetting('key_content') ?: $this->cora_api->get_setting('key_content', 'cora_boleto');

        $certInfo = '<p class="text-muted">Cole o certificado (.pem ou .crt). <em>Se deixar em branco, o sistema utilizará automaticamente as credenciais configuradas na aba Pix Banco Cora.</em></p>';
        $keyInfo  = '<p class="text-muted">Cole a chave privada (.key). <em>Se deixar em branco, o sistema utilizará a chave configurada na aba Pix Banco Cora.</em></p>';

        if (!empty($certRaw) || !empty($keyRaw)) {
            $diag = $this->cora_api->diagnosticar_certificados('cora_boleto');
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
                'name'          => 'redirect_to_pdf',
                'type'          => 'yes_no',
                'label'         => 'Redirecionar diretamente para o PDF Oficial da Cora',
                'default_value' => 1,
                'info'          => '<p class="text-muted">Ativado: envia o cliente diretamente para o PDF do boleto gerado pela Cora. Desativado: exibe a página interna interativa do módulo com linha digitável e QR Code.</p>',
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

        // Validação de valor mínimo: impede emissão de boleto com valor zero ou negativo
        if ($amount <= 0) {
            set_alert('warning', 'Não é possível emitir um boleto bancário para uma fatura com valor zero ou negativo.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // 0. Bloqueio de Faturas em Rascunho (STATUS_DRAFT = 6)
        $statusDraft = defined('Invoices_model::STATUS_DRAFT') ? Invoices_model::STATUS_DRAFT : 6;
        if ((int)$invoice->status === (int)$statusDraft) {
            set_alert('warning', 'Esta fatura ainda se encontra em rascunho e não pode receber pagamentos.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // 1. Validação de Moeda: Apenas BRL é suportado
        $currency = '';
        if (isset($invoice->currency_name) && !empty($invoice->currency_name)) {
            $currency = $invoice->currency_name;
        } elseif (isset($invoice->currency) && function_exists('get_currency')) {
            $c = get_currency($invoice->currency);
            if ($c && isset($c->name)) {
                $currency = $c->name;
            }
        }
        if (empty($currency) && function_exists('get_base_currency')) {
            $bc = get_base_currency();
            if ($bc && isset($bc->name)) {
                $currency = $bc->name;
            }
        }
        if (strtoupper(trim($currency ?: 'BRL')) !== 'BRL') {
            set_alert('warning', 'O Boleto Bancário Cora está disponível apenas para faturas emitidas em Reais (BRL).');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // 2. Validação Fiscal: Documento (CPF ou CNPJ) - Preservação estrita de zeros como string
        $doc = (string) preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            set_alert('danger', 'O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para emitir o Boleto Bancário.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // 3. Validação de Credenciais mTLS da API Cora Pro (Boleto Bancário depende estritamente de registro bancário)
        $clientId = trim((string)$this->cora_api->get_credential('client_id', 'cora_boleto'));
        $certRaw  = trim((string)$this->cora_api->get_credential('cert_content', 'cora_boleto'));
        $keyRaw   = trim((string)$this->cora_api->get_credential('key_content', 'cora_boleto'));
        $certsDir = $this->cora_api->get_certs_dir();
        $hasCertFiles = (file_exists($certsDir . DIRECTORY_SEPARATOR . 'cora_cert.pem') && file_exists($certsDir . DIRECTORY_SEPARATOR . 'cora_key.key'));

        if (empty($clientId) || (empty($certRaw) && !$hasCertFiles) || (empty($keyRaw) && !$hasCertFiles)) {
            set_alert('warning', 'A emissão de Boletos Bancários requer a integração ativa da API Cora Pro com certificados mTLS registrados. Caso sua empresa utilize o Modo Pix Manual, por favor selecione a opção Pix para efetuar o pagamento.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        try {
            // 1. Reutilização de Boletos Ativos (Anti-Duplicação no DDA com salvaguarda de vencimento)
            $existente = $this->ci->db->where('invoice_id', $invoice->id)
                ->where('type', 'BOLETO')
                ->where('status', 'PENDING')
                ->order_by('id', 'DESC')
                ->get(db_prefix() . 'cora_transactions')
                ->row();

            $redirectSetting = $this->getSetting('redirect_to_pdf');
            $redirectToPdf = ($redirectSetting === null || (int)$redirectSetting === 1 || $this->getSetting('redirect_mode') === 'pdf');

            // Se já existe e a URL do PDF está salva, valida se o vencimento original ainda é válido
            if ($existente && !empty($existente->pdf_url)) {
                $hoje = date('Y-m-d');
                $vencimento = !empty($invoice->duedate) ? $invoice->duedate : $hoje;

                // Se a fatura venceu após a emissão do boleto anterior, emite um novo atualizado
                if (strtotime($vencimento) >= strtotime($hoje)) {
                    if ($redirectToPdf) {
                        redirect($existente->pdf_url);
                    } else {
                        redirect(site_url('cora_payments/cora/boleto_view/' . $invoice->id . '/' . $invoice->hash . '/' . $existente->txid));
                    }
                    return;
                }
            }

            // Emite novo boleto híbrido na Cora
            $customOptions = [
                'multa'              => (float)$this->getSetting('multa_percentual'),
                'juros'              => (float)$this->getSetting('juros_mensal_percentual'),
                'dias_cancelamento'  => (int)$this->getSetting('dias_cancelamento'),
            ];

            $boleto = $this->cora_api->criar_boleto($invoice, $amount, $customOptions);

            // 1. Garante txid único baseado no ID da Cora para evitar Duplicate entry '' for key 'txid'
            $coraInvoiceId = $boleto['cora_invoice_id'];
            $txid          = !empty($boleto['txid']) ? $boleto['txid'] : ('BOL_' . $coraInvoiceId);

            // Persiste na tabela unificada cora_transactions
            $this->ci->db->insert(db_prefix() . 'cora_transactions', [
                'invoice_id'      => (int)$invoice->id,
                'type'            => 'BOLETO',
                'txid'            => $txid,
                'cora_invoice_id' => $coraInvoiceId,
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
            if ($redirectToPdf && !empty($boleto['pdf_url'])) {
                redirect($boleto['pdf_url']);
                return;
            }

            redirect(site_url('cora_payments/cora/boleto/' . $invoice->id . '/' . $invoice->hash . '/' . $txid));
        } catch (Exception $e) {
            log_activity('Falha na emissão de Boleto Cora para Fatura #' . $invoice->id . ': ' . $e->getMessage());
            set_alert('danger', 'Não foi possível gerar o Boleto Bancário: ' . $e->getMessage());
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }
    }
}
