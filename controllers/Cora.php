<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controller Cora
 * 
 * Controlador oficial unificado do módulo Cora Payments para Perfex CRM.
 * 
 * Responsabilidades:
 * 1. Webhook Unificado: Conciliação atômica para Pix Imediato e Boleto Bancário Híbrido,
 *    com dupla checagem ativa mTLS e barramento estrito de concorrência.
 * 2. Tela de Pagamento Pix (pay): Exibição de QR Code e Copia e Cola.
 * 3. Tela e Download de Boleto (boleto/download_boleto): Exibição do boleto e redirecionamento de PDF.
 * 4. Polling em tempo real (check_status): Atualização assíncrona da tela do cliente.
 * 5. Teste de Conexão Administrativo (test_connection): Validação instantânea de certificados e OAuth2.
 */
class Cora extends App_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('invoices_model');
        $this->load->model('clients_model');

        // Carrega helper de API e gateways
        $this->load->library('cora_payments/cora_api');
        $this->load->library('cora_payments/cora_pix_gateway');
        $this->load->library('cora_payments/cora_boleto_gateway');
    }

    /**
     * Tela de pagamento Pix com QR Code dinâmico e código Copia e Cola
     *
     * @param int|string $invoice_id
     * @param string $txid
     */
    public function pay($invoice_id = null, $txid = null)
    {
        if (empty($invoice_id) || empty($txid)) {
            show_404();
            return;
        }

        $invoice_id = (int)$invoice_id;
        $invoice    = $this->invoices_model->get($invoice_id);

        if (!$invoice) {
            show_404();
            return;
        }

        // Fatura já quitada (Status 2 = STATUS_PAID no Perfex CRM)
        if ((int)$invoice->status === 2) {
            set_alert('info', 'Esta fatura já se encontra liquidada.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // Busca transação local
        $this->db->where('invoice_id', $invoice_id);
        $this->db->where('txid', $txid);
        $transaction = $this->db->get(db_prefix() . 'cora_transactions')->row();

        if (!$transaction) {
            show_error('Transação de pagamento não encontrada para esta fatura.', 404);
            return;
        }

        // Se já concluída
        if ($transaction->status === 'CONCLUIDA') {
            set_alert('success', 'O pagamento já foi confirmado com sucesso!');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        $amountToPay = !empty($transaction->amount) && (float)$transaction->amount > 0
            ? (float)$transaction->amount
            : (float)($invoice->total_left_to_pay ?? $invoice->total);

        $data = [
            'title'            => 'Pagamento Pix - Fatura #' . format_invoice_number($invoice->id),
            'invoice'          => $invoice,
            'transaction'      => $transaction,
            'txid'             => $txid,
            'pix_copia_cola'   => $transaction->pix_copia_cola,
            'amount'           => $amountToPay,
            'check_status_url' => site_url('cora_payments/cora/check_status/' . $invoice->id . '/' . $txid),
            'invoice_url'      => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ];

        $this->load->view('cora_payments/pix_payment', $data);
    }

    /**
     * Tela de exibição de Boleto Bancário Híbrido (com opção de download, linha digitável e Pix)
     *
     * @param int|string $invoice_id
     * @param string $txid
     */
    public function boleto($invoice_id = null, $txid = null)
    {
        if (empty($invoice_id) || empty($txid)) {
            show_404();
            return;
        }

        $invoice_id = (int)$invoice_id;
        $invoice    = $this->invoices_model->get($invoice_id);

        if (!$invoice) {
            show_404();
            return;
        }

        $this->db->where('invoice_id', $invoice_id);
        $this->db->where('txid', $txid);
        $transaction = $this->db->get(db_prefix() . 'cora_transactions')->row();

        if (!$transaction) {
            show_error('Boleto bancário não encontrado para esta fatura.', 404);
            return;
        }

        // Se a fatura já estiver paga, redireciona
        if ((int)$invoice->status === 2 || $transaction->status === 'CONCLUIDA') {
            set_alert('success', 'Esta fatura já foi quitada!');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        $amountToPay = !empty($transaction->amount) && (float)$transaction->amount > 0
            ? (float)$transaction->amount
            : (float)($invoice->total_left_to_pay ?? $invoice->total);

        $data = [
            'title'            => 'Boleto Bancário - Fatura #' . format_invoice_number($invoice->id),
            'invoice'          => $invoice,
            'transaction'      => $transaction,
            'txid'             => $txid,
            'barcode'          => $transaction->barcode,
            'pdf_url'          => $transaction->pdf_url,
            'pix_copia_cola'   => $transaction->pix_copia_cola,
            'amount'           => $amountToPay,
            'check_status_url' => site_url('cora_payments/cora/check_status/' . $invoice->id . '/' . $txid),
            'invoice_url'      => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ];

        $this->load->view('cora_payments/boleto_payment', $data);
    }

    /**
     * Download seguro do PDF do Boleto Oficial da Cora
     *
     * @param int|string $invoice_id
     * @param string $txid
     */
    public function download_boleto($invoice_id = null, $txid = null)
    {
        if (empty($invoice_id) || empty($txid)) {
            show_404();
            return;
        }

        $this->db->where('invoice_id', (int)$invoice_id);
        $this->db->where('txid', $txid);
        $transaction = $this->db->get(db_prefix() . 'cora_transactions')->row();

        if (!$transaction || empty($transaction->pdf_url)) {
            show_error('PDF do boleto indisponível ou não localizado.', 404);
            return;
        }

        redirect($transaction->pdf_url);
    }

    /**
     * Endpoint de Polling assíncrono para telas de pagamento (Pix e Boleto)
     * Retorna JSON indicando se o pagamento já foi compensado e URL de retorno.
     *
     * @param int|string $invoice_id
     * @param string $txid
     */
    public function check_status($invoice_id = null, $txid = null)
    {
        if (empty($invoice_id) || empty($txid)) {
            header('Content-Type: application/json');
            echo json_encode(['paid' => false, 'error' => 'Parâmetros ausentes']);
            exit;
        }

        $transacao = $this->db->where('txid', $txid)
                              ->where('invoice_id', (int)$invoice_id)
                              ->get(db_prefix() . 'cora_transactions')->row();

        $invoice = $this->invoices_model->get((int)$invoice_id);

        $isPaid = false;
        if (($transacao && $transacao->status === 'CONCLUIDA') || ($invoice && (int)$invoice->status === 2)) {
            $isPaid = true;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'paid'         => $isPaid,
            'status'       => $transacao ? $transacao->status : 'INEXISTENTE',
            'type'         => $transacao ? $transacao->type : '',
            'redirect_url' => $invoice ? site_url('invoice/' . $invoice->id . '/' . $invoice->hash) : site_url(),
        ]);
        exit;
    }

    /**
     * Validação administrativa instantânea de conexão mTLS e credenciais
     * Acionado pelo botão "Testar Conexão com a Cora" na aba de configurações.
     */
    public function test_connection()
    {
        if (function_exists('has_permission') && !has_permission('settings', '', 'view') && !is_admin()) {
            if (function_exists('ajax_access_denied')) {
                ajax_access_denied();
            } else {
                header('HTTP/1.1 403 Forbidden');
                echo json_encode(['success' => false, 'message' => 'Acesso não autorizado.']);
            }
            exit;
        }

        header('Content-Type: application/json');

        // Diagnóstico dos certificados
        $diag = $this->cora_api->diagnosticar_certificados('cora_pix');
        if (!$diag['cert_valido']) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro no Certificado: ' . $diag['cert_mensagem'],
            ]);
            exit;
        }

        if (!$diag['key_valida']) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro na Chave Privada: ' . $diag['key_mensagem'],
            ]);
            exit;
        }

        try {
            // Força requisição nova ao endpoint /token com os certificados mTLS
            $token = $this->cora_api->get_token('cora_pix', true);

            if ($token) {
                $isSandbox = (int)$this->cora_api->get_setting('sandbox', 'cora_pix') === 1;
                $ambiente  = $isSandbox ? 'Homologação / Sandbox' : 'Produção';

                echo json_encode([
                    'success' => true,
                    'message' => 'Conexão mTLS autenticada com sucesso no ambiente ' . $ambiente . '! Certificados válidos e Token OAuth2 gerado pelo Banco Cora.',
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Falha na autenticação mTLS. Verifique se o Client ID e os certificados pertencem à mesma conta e ambiente.',
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Falha de comunicação mTLS com a Cora: ' . $e->getMessage(),
            ]);
        }
        exit;
    }

    /**
     * Webhook Unificado: Recebimento e conciliação de notificações Pix e Boleto
     * 
     * Implementa:
     * 1. Suporte a headers Cora (webhook-event-type e webhook-resource-id)
     * 2. Suporte a payloads padrão Bacen Pix ({ pix: [...] } ou { txid: ... })
     * 3. Dupla checagem mTLS ativa antes da baixa
     * 4. Idempotência estrita: UPDATE condicional (status != 'CONCLUIDA') com verificação de affected_rows() === 0
     */
    public function webhook()
    {
        // Desativa a checagem de CSRF para requisições externas do Banco Cora
        if (isset($this->security)) {
            $this->security->csrf_verify = false;
        }

        // Lê headers enviados pelo Banco Cora
        $headerEventType  = $this->input->get_request_header('webhook-event-type', TRUE);
        $headerResourceId = $this->input->get_request_header('webhook-resource-id', TRUE);

        $rawInput = file_get_contents('php://input');
        $payload  = !empty($rawInput) ? json_decode($rawInput, true) : [];

        // Log de depuração da notificação
        log_activity('Cora Payments Webhook recebido. EventType: ' . ($headerEventType ?: 'None') . ' | Body: ' . substr($rawInput, 0, 300));

        // =========================================================================
        // CENÁRIO 1: NOTIFICAÇÃO DE BOLETO CORA (invoice.paid / invoice.cancelled)
        // =========================================================================
        $isBoletoEvent = ($headerEventType === 'invoice.paid' || $headerEventType === 'invoice.cancelled');
        if (!$isBoletoEvent && isset($payload['event']) && in_array($payload['event'], ['invoice.paid', 'invoice.cancelled', 'INVOICE_PAID'])) {
            $isBoletoEvent   = true;
            $headerEventType = strtolower($payload['event']);
        }

        if ($isBoletoEvent) {
            $coraInvoiceId = $headerResourceId ?: ($payload['resource']['id'] ?? ($payload['data']['id'] ?? ($payload['id'] ?? '')));

            if (!empty($coraInvoiceId)) {
                $this->processar_webhook_boleto($coraInvoiceId, $headerEventType, $payload);
                set_status_header(200);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Boleto webhook processed']);
                return;
            }
        }

        // =========================================================================
        // CENÁRIO 2: NOTIFICAÇÃO PIX PADRÃO BACEN OU CORA
        // =========================================================================
        $pixItems = [];

        if (isset($payload['pix']) && is_array($payload['pix'])) {
            $pixItems = $payload['pix'];
        } elseif (isset($payload['txid'])) {
            $pixItems[] = $payload;
        } elseif (isset($payload['data']['txid'])) {
            $pixItems[] = $payload['data'];
        }

        if (!empty($pixItems)) {
            $this->processar_webhook_pix($pixItems);
            set_status_header(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Pix webhook processed']);
            return;
        }

        // Se nenhum item foi reconhecido, responde 200 para liberar o webhook da Cora
        set_status_header(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ignored', 'message' => 'No actionable transaction found']);
    }

    /**
     * Processamento de eventos Pix recebidos via Webhook
     *
     * @param array $pixItems
     */
    protected function processar_webhook_pix($pixItems)
    {
        foreach ($pixItems as $item) {
            $txid  = $item['txid'] ?? null;
            $e2eid = $item['endToEndId'] ?? ($item['end_to_end_id'] ?? $txid);

            if (empty($txid)) {
                continue;
            }

            // Localiza a transação local
            $transacao = $this->db->where('txid', $txid)
                                  ->get(db_prefix() . 'cora_transactions')->row();

            if (!$transacao) {
                log_activity('Webhook Pix Cora: Transação não localizada no banco para txid: ' . $txid);
                continue;
            }

            if ($transacao->status === 'CONCLUIDA') {
                continue;
            }

            // 1. DUPLA CHECAGEM ATIVA ANTI-FRAUDE VIA MTLS (GET /v1/cob/{txid})
            $consulta = $this->cora_api->consultar_cobranca($txid);

            if (!$consulta || !isset($consulta['status']) || strtoupper($consulta['status']) !== 'CONCLUIDA') {
                log_activity('Alerta Anti-Fraude Cora Pix: Consulta mTLS ativa rejeitou txid ' . $txid . '. Status retornado: ' . ($consulta['status'] ?? 'FALHA'));
                continue;
            }

            // Valor autenticado
            $valor = isset($consulta['valor']['original'])
                ? (float)$consulta['valor']['original']
                : (float)$transacao->amount;

            // 2. CONCORRÊNCIA E IDEMPOTÊNCIA: ATUALIZAÇÃO ATÔMICA CONDICIONAL
            $this->db->where('id', $transacao->id);
            $this->db->where('status !=', 'CONCLUIDA');
            $this->db->update(db_prefix() . 'cora_transactions', [
                'status'  => 'CONCLUIDA',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);

            // Se nenhuma linha foi afetada, outra thread paralela já liquidou
            if ($this->db->affected_rows() === 0) {
                continue;
            }

            // 3. LIQUIDA A FATURA NO PERFEX CRM
            $this->cora_pix_gateway->addPayment([
                'amount'        => $valor,
                'invoiceid'     => $transacao->invoice_id,
                'paymentmode'   => 'cora_pix',
                'paymentmethod' => 'Pix (Cora)',
                'transactionid' => $e2eid,
                'note'          => 'Liquidado via PIX Cora. E2E: ' . $e2eid . ' | TxID: ' . $txid,
                'date'          => date('Y-m-d H:i:s'),
            ]);

            log_activity('Fatura #' . $transacao->invoice_id . ' liquidada via Pix Cora (TxID: ' . $txid . ', E2E: ' . $e2eid . ')');
        }
    }

    /**
     * Processamento de eventos de Boleto Cora (invoice.paid / invoice.cancelled)
     *
     * @param string $coraInvoiceId ID da fatura na Cora
     * @param string $eventType Tipo de evento
     * @param array $payload Payload completo
     */
    protected function processar_webhook_boleto($coraInvoiceId, $eventType, $payload = [])
    {
        $transacao = $this->db->where('cora_invoice_id', $coraInvoiceId)
                              ->get(db_prefix() . 'cora_transactions')->row();

        if (!$transacao) {
            // Tenta localizar por txid caso code tenha sido salvo como txid
            $code = $payload['code'] ?? ($payload['resource']['code'] ?? '');
            if (!empty($code)) {
                $transacao = $this->db->where('txid', $code)->get(db_prefix() . 'cora_transactions')->row();
            }
        }

        if (!$transacao) {
            log_activity('Webhook Boleto Cora: Fatura não localizada para cora_invoice_id: ' . $coraInvoiceId);
            return;
        }

        // Tratamento de cancelamento
        if ($eventType === 'invoice.cancelled') {
            if ($transacao->status !== 'CONCLUIDA') {
                $this->db->where('id', $transacao->id)->update(db_prefix() . 'cora_transactions', [
                    'status' => 'CANCELLED',
                ]);
                log_activity('Boleto Cora ' . $coraInvoiceId . ' marcado como cancelado no banco.');
            }
            return;
        }

        // Se já está liquidada
        if ($transacao->status === 'CONCLUIDA') {
            return;
        }

        // 1. DUPLA CHECAGEM ATIVA ANTI-FRAUDE VIA MTLS (GET /v2/invoices/{id})
        $consulta = $this->cora_api->consultar_fatura($coraInvoiceId);

        if (!$consulta || !isset($consulta['status']) || !in_array(strtoupper($consulta['status']), ['PAID', 'CONCLUIDA'])) {
            log_activity('Alerta Anti-Fraude Cora Boleto: Consulta mTLS rejeitou boleto ' . $coraInvoiceId . '. Status: ' . ($consulta['status'] ?? 'FALHA'));
            return;
        }

        // Valor da fatura (convertido de centavos para reais se necessário)
        $valor = (float)$transacao->amount;
        if (isset($consulta['total_paid']['amount'])) {
            $valor = (float)($consulta['total_paid']['amount'] / 100);
        } elseif (isset($consulta['services'][0]['amount'])) {
            $valor = (float)($consulta['services'][0]['amount'] / 100);
        }

        // 2. CONCORRÊNCIA E IDEMPOTÊNCIA: ATUALIZAÇÃO ATÔMICA CONDICIONAL
        $this->db->where('id', $transacao->id);
        $this->db->where('status !=', 'CONCLUIDA');
        $this->db->update(db_prefix() . 'cora_transactions', [
            'status'  => 'CONCLUIDA',
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        if ($this->db->affected_rows() === 0) {
            return;
        }

        // 3. LIQUIDA A FATURA NO PERFEX CRM
        $this->cora_boleto_gateway->addPayment([
            'amount'        => $valor,
            'invoiceid'     => $transacao->invoice_id,
            'paymentmode'   => 'cora_boleto',
            'paymentmethod' => 'Boleto Bancário (Cora)',
            'transactionid' => $coraInvoiceId,
            'note'          => 'Liquidado via Boleto Bancário Cora. Cora ID: ' . $coraInvoiceId . ' | Barcode: ' . $transacao->barcode,
            'date'          => date('Y-m-d H:i:s'),
        ]);

        log_activity('Fatura #' . $transacao->invoice_id . ' liquidada via Boleto Cora (ID: ' . $coraInvoiceId . ')');
    }
}
