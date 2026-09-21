<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controller Cora
 * 
 * Controlador oficial unificado do módulo Cora Payments para Perfex CRM.
 * 
 * Responsabilidades:
 * 1. Webhook Unificado: Recebe e concilia notificações Pix (Padrão Bacen) e Boletos (Cora v2),
 *    com dupla checagem mTLS ativa e concorrência atômica rigorosa.
 * 2. Tela de Pagamento Pix (pay): Exibição de QR Code e Copia e Cola.
 * 3. Tela e Download de Boleto (boleto/download_boleto): Exibição de boleto, código de barras e link de PDF.
 * 4. Polling assíncrono (check_status): Atualização em tempo real na tela do cliente.
 * 5. Teste de Conexão Administrativo (test_connection): Validação instantânea de certificados e OAuth2.
 */
class Cora extends App_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('invoices_model');
        $this->load->model('clients_model');

        // Carrega helper central de API
        $this->load->library('cora_payments/cora_api');
    }

    /**
     * Resolve parâmetros da rota pública suportando formatos com hash ou legados:
     * - Formato Seguro: ($invoice_id, $hash, $txid)
     * - Formato Legado: ($invoice_id, $txid, null)
     *
     * @param int|string $invoice_id
     * @param string|null $hash_or_txid
     * @param string|null $txid
     * @return array [$invoice_id, $hash, $txid]
     */
    protected function resolve_route_params($invoice_id, $hash_or_txid = null, $txid = null)
    {
        $invoice_id = (int)$invoice_id;
        $hash       = null;
        $cleanTxid  = null;

        if (!empty($txid)) {
            $hash      = (string)$hash_or_txid;
            $cleanTxid = (string)$txid;
        } else {
            $cleanTxid = (string)$hash_or_txid;
        }

        return [$invoice_id, $hash, $cleanTxid];
    }

    /**
     * Valida as restrições de acesso à fatura usando a função nativa do Perfex CRM
     * e o hash de segurança para prevenir IDOR e enumeração de dados de clientes.
     *
     * @param int $invoice_id
     * @param string|null $hash
     * @return object
     */
    protected function validate_invoice_access($invoice_id, $hash = null)
    {
        if (empty($invoice_id)) {
            show_404();
            exit;
        }

        $invoice = $this->invoices_model->get((int)$invoice_id);
        if (!$invoice) {
            show_404();
            exit;
        }

        // Se o hash foi fornecido na URL, aplica check_invoice_restrictions nativo do Perfex CRM
        if (!empty($hash)) {
            if (function_exists('check_invoice_restrictions')) {
                check_invoice_restrictions($invoice->id, $hash);
            } elseif ($invoice->hash !== $hash) {
                show_404();
                exit;
            }
        }

        return $invoice;
    }

    /**
     * Tela de pagamento Pix com QR Code dinâmico e código Copia e Cola
     * Protegido contra enumeração de faturas (IDOR) via hash
     *
     * @param int|string $invoice_id
     * @param string|null $hash_or_txid
     * @param string|null $txid
     */
    public function pay($invoice_id = null, $hash_or_txid = null, $txid = null)
    {
        list($invoice_id, $hash, $txid) = $this->resolve_route_params($invoice_id, $hash_or_txid, $txid);

        if (empty($invoice_id) || empty($txid)) {
            show_404();
            return;
        }

        $invoice = $this->validate_invoice_access($invoice_id, $hash);

        $statusDraft = defined('Invoices_model::STATUS_DRAFT') ? Invoices_model::STATUS_DRAFT : 6;
        if ((int)$invoice->status === (int)$statusDraft) {
            set_alert('warning', 'Esta fatura ainda se encontra em rascunho e não pode receber pagamentos.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
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

        if (in_array(strtoupper($transaction->status), ['PAID', 'CONCLUIDA'])) {
            set_alert('success', 'O pagamento já foi confirmado com sucesso!');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        $amountToPay = !empty($transaction->amount) && (float)$transaction->amount > 0
            ? (float)$transaction->amount
            : (float)($invoice->total_left_to_pay ?? $invoice->total);

        // Identifica o modo de operação configurado no gateway Pix
        $this->load->library('cora_payments/cora_pix_gateway');
        $operationMode = $this->cora_pix_gateway->getSetting('operation_mode') ?: 'api_cora';
        $isManual      = ($operationMode === 'manual');

        $merchantName = trim((string)$this->cora_pix_gateway->getSetting('pix_manual_merchant_name'));
        if (empty($merchantName)) {
            $merchantName = get_option('companyname') ?: 'EMPRESA';
        }

        $pixKey = trim((string)$this->cora_pix_gateway->getSetting('pix_manual_key'));
        if (empty($pixKey)) {
            $pixKey = trim((string)$this->cora_pix_gateway->getSetting('chave_pix'));
        }

        $keyType      = $this->cora_pix_gateway->getSetting('pix_manual_key_type') ?: 'cnpj';
        $instructions = $this->cora_pix_gateway->getSetting('pix_manual_instructions');
        $whatsapp     = trim((string)$this->cora_pix_gateway->getSetting('pix_manual_whatsapp'));
        $companyEmail = get_option('invoice_company_email') ?: get_option('company_email');

        // Tenta renderizar SVG nativo como fallback de alta fidelidade
        if (!class_exists('Pix_payload', false)) {
            $this->load->library('cora_payments/pix_payload');
        }
        $qrCodeSvg = Pix_payload::render_svg($transaction->pix_copia_cola, 6);

        $data = [
            'title'            => 'Pagamento Pix - Fatura #' . format_invoice_number($invoice->id),
            'invoice'          => $invoice,
            'transaction'      => $transaction,
            'txid'             => $txid,
            'pix_copia_cola'   => $transaction->pix_copia_cola,
            'amount'           => $amountToPay,
            'check_status_url' => site_url('cora_payments/cora/check_status/' . $invoice->id . '/' . $invoice->hash . '/' . $txid),
            'invoice_url'      => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
            'is_manual'        => $isManual,
            'merchant_name'    => $merchantName,
            'merchant_city'    => $this->cora_pix_gateway->getSetting('pix_manual_merchant_city') ?: 'SAO PAULO',
            'pix_key'          => $pixKey,
            'key_type'         => $keyType,
            'instructions'     => $instructions,
            'whatsapp'         => $whatsapp,
            'company_email'    => $companyEmail,
            'qr_code_svg'      => $qrCodeSvg,
        ];

        $this->load->view('cora_payments/pix_payment', $data);
    }

    /**
     * Tela de exibição de Boleto Bancário Híbrido (com opção de download, linha digitável e Pix)
     * Protegido contra enumeração de faturas (IDOR) via hash
     *
     * @param int|string $invoice_id
     * @param string|null $hash_or_txid
     * @param string|null $txid
     */
    public function boleto($invoice_id = null, $hash_or_txid = null, $txid = null)
    {
        list($invoice_id, $hash, $txid) = $this->resolve_route_params($invoice_id, $hash_or_txid, $txid);

        if (empty($invoice_id) || empty($txid)) {
            show_404();
            return;
        }

        $invoice = $this->validate_invoice_access($invoice_id, $hash);

        $this->db->where('invoice_id', $invoice_id);
        $this->db->where('txid', $txid);
        $transaction = $this->db->get(db_prefix() . 'cora_transactions')->row();

        if (!$transaction) {
            show_error('Boleto bancário não encontrado para esta fatura.', 404);
            return;
        }

        $statusDraft = defined('Invoices_model::STATUS_DRAFT') ? Invoices_model::STATUS_DRAFT : 6;
        if ((int)$invoice->status === (int)$statusDraft) {
            set_alert('warning', 'Esta fatura ainda se encontra em rascunho e não pode receber pagamentos.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        if ((int)$invoice->status === 2 || in_array(strtoupper($transaction->status), ['PAID', 'CONCLUIDA'])) {
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
            'digitable_line'   => $transaction->barcode,
            'pdf_url'          => $transaction->pdf_url,
            'pix_copia_cola'   => $transaction->pix_copia_cola,
            'amount'           => $amountToPay,
            'check_status_url' => site_url('cora_payments/cora/check_status/' . $invoice->id . '/' . $invoice->hash . '/' . $txid),
            'invoice_url'      => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ];

        $this->load->view('cora_payments/boleto_payment', $data);
    }

    /**
     * Alias para visualização do boleto bancário (boleto_view)
     *
     * @param int|string $invoice_id
     * @param string|null $hash_or_txid
     * @param string|null $txid
     */
    public function boleto_view($invoice_id = null, $hash_or_txid = null, $txid = null)
    {
        return $this->boleto($invoice_id, $hash_or_txid, $txid);
    }

    /**
     * Download seguro do PDF do Boleto Oficial da Cora
     *
     * @param int|string $invoice_id
     * @param string|null $hash_or_txid
     * @param string|null $txid
     */
    public function download_boleto($invoice_id = null, $hash_or_txid = null, $txid = null)
    {
        list($invoice_id, $hash, $txid) = $this->resolve_route_params($invoice_id, $hash_or_txid, $txid);

        if (empty($invoice_id) || empty($txid)) {
            show_404();
            return;
        }

        $this->validate_invoice_access($invoice_id, $hash);

        $this->db->where('invoice_id', (int)$invoice_id);
        $this->db->where('txid', $txid);
        $transaction = $this->db->get(db_prefix() . 'cora_transactions')->row();

        if (!$transaction || empty($transaction->pdf_url)) {
            show_error('PDF do boleto indisponível ou não localizado.', 404);
            return;
        }

        // Validação anti Open Redirect / SSRF
        if (!filter_var($transaction->pdf_url, FILTER_VALIDATE_URL) || stripos($transaction->pdf_url, 'https://') !== 0) {
            show_error('URL de PDF do boleto inválida ou insegura.', 400);
            return;
        }

        redirect($transaction->pdf_url);
    }

    /**
     * Endpoint de Polling assíncrono para telas de pagamento (Pix e Boleto)
     * Retorna JSON indicando se o pagamento já foi compensado e URL de retorno.
     * Exige estritamente a combinação invoice_id, hash (se fornecido) e txid para prevenir BOLA/IDOR.
     *
     * @param int|string $invoice_id
     * @param string|null $hash_or_txid
     * @param string|null $txid
     */
    public function check_status($invoice_id = null, $hash_or_txid = null, $txid = null)
    {
        header('Content-Type: application/json; charset=utf-8');

        list($invoice_id, $hash, $txid) = $this->resolve_route_params($invoice_id, $hash_or_txid, $txid);

        if (empty($invoice_id) || empty($txid)) {
            set_status_header(400);
            echo json_encode([
                'paid'  => false,
                'error' => 'Parâmetros obrigatórios ausentes (invoice_id e txid são requeridos).',
            ]);
            exit;
        }

        $this->load->model('invoices_model');
        $invoice = $this->invoices_model->get($invoice_id);

        if (!$invoice) {
            set_status_header(404);
            echo json_encode([
                'paid'  => false,
                'error' => 'Fatura não encontrada.',
            ]);
            exit;
        }

        // Se o hash foi fornecido, valida contra acesso indevido/IDOR
        if (!empty($hash) && $invoice->hash !== $hash) {
            set_status_header(403);
            echo json_encode([
                'paid'  => false,
                'error' => 'Acesso não autorizado para esta fatura.',
            ]);
            exit;
        }

        // 1. Busca estrita da transação vinculada à fatura e txid informados
        $transacao = $this->db->where('txid', $txid)
                              ->where('invoice_id', $invoice_id)
                              ->get(db_prefix() . 'cora_transactions')
                              ->row();

        if (!$transacao) {
            set_status_header(404);
            echo json_encode([
                'paid'  => false,
                'error' => 'Transação não localizada para esta fatura.',
            ]);
            exit;
        }

        // 2. Consulta o status real da fatura no core do Perfex CRM (STATUS_PAID = 2) ou na transação
        $statusPaid = defined('Invoices_model::STATUS_PAID') ? Invoices_model::STATUS_PAID : 2;
        $isPaid     = ((int)$invoice->status === (int)$statusPaid || in_array(strtoupper($transacao->status), ['CONCLUIDA', 'PAID']));

        echo json_encode([
            'paid'         => $isPaid,
            'status'       => $transacao->status,
            'type'         => $transacao->type,
            'redirect_url' => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ]);
        exit;
    }

    /**
     * Validação administrativa instantânea de conexão mTLS e credenciais
     * Acionado pelo botão "Testar Conexão mTLS com a Cora" na aba de configurações.
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
                $isSandbox = (int)$this->cora_api->get_credential('sandbox', 'cora_pix') === 1;
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
            $msg = $e->getMessage();
            if (strpos($msg, 'cURL error 58') !== false || strpos($msg, 'unable to set private key') !== false) {
                $msg .= ' (Dica de Permissão Linux: Verifique se o processo PHP/PHP-FPM possui permissão de leitura nos arquivos da pasta certs/. O módulo define 0640 automaticamente, mas confirme se o proprietário (chown) da pasta pertence ao usuário do servidor web/PHP-FPM).';
            }
            echo json_encode([
                'success' => false,
                'message' => 'Falha de comunicação mTLS com a Cora: ' . $msg,
            ]);
        }
        exit;
    }

    /**
     * 3. Webhook Unificado: Recebimento de Pix e Boleto
     * 
     * Trata simultaneamente os dois formatos na mesma URL:
     * - Pix Bacen: lista $payload['pix'] com txid, valor e endToEndId
     * - Boleto Cora v2: eventos Cora ('invoice.paid', 'INVOICE_PAID') com conversão de centavos
     */
    public function webhook()
    {
        // Desativa a checagem de CSRF para requisições externas do Banco Cora
        if (isset($this->security)) {
            $this->security->csrf_verify = false;
        }

        $rawInput = file_get_contents('php://input');
        $payload  = json_decode($rawInput, true);

        // Suporte adicional caso a notificação chegue via HTTP headers da Cora
        $headerEventType  = $this->input->get_request_header('webhook-event-type', TRUE);
        $headerResourceId = $this->input->get_request_header('webhook-resource-id', TRUE);

        if (!$payload) {
            if (!empty($headerEventType) && !empty($headerResourceId)) {
                $payload = [
                    'event' => $headerEventType,
                    'data'  => ['id' => $headerResourceId],
                ];
            } else {
                set_status_header(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'message' => 'Payload vazio ou JSON inválido']);
                return;
            }
        }

        // Validação opcional de Token de Webhook (se configurado)
        $configuredToken = get_option('cora_payments_webhook_token') ?: get_option('payment_gateway_cora_pix_webhook_token');
        if (!empty($configuredToken)) {
            $incomingToken = $this->input->get_request_header('X-Webhook-Token', TRUE) 
                ?: ($this->input->get_request_header('token', TRUE) 
                ?: $this->input->get('token'));
            if ($incomingToken !== $configuredToken) {
                log_activity('Cora Webhook: Token de autenticação inválido ou ausente.');
                set_status_header(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized webhook']);
                return;
            }
        }

        log_activity('Cora Payments Webhook recebido: ' . substr($rawInput ?: json_encode($payload), 0, 300));

        // =========================================================================
        // CENÁRIO A: Notificação de PIX (Padrão Bacen)
        // =========================================================================
        if (!empty($payload['pix']) && is_array($payload['pix'])) {
            $this->load->library('cora_payments/cora_pix_gateway');
            foreach ($payload['pix'] as $item) {
                $txid   = $item['txid'] ?? null;
                $valor  = $item['valor'] ?? null;
                $e2e    = $item['endToEndId'] ?? ($item['end_to_end_id'] ?? null);
                if (!empty($txid)) {
                    $this->process_pix_payment($txid, $valor, $e2e);
                }
            }
            set_status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'success', 'message' => 'Pix webhook processed']);
            return;
        } elseif (!empty($payload['txid'])) {
            $this->load->library('cora_payments/cora_pix_gateway');
            $this->process_pix_payment($payload['txid'], $payload['valor'] ?? null, $payload['endToEndId'] ?? null);
            set_status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'success', 'message' => 'Pix single webhook processed']);
            return;
        }

        // =========================================================================
        // CENÁRIO B: Notificação de Boleto (Cora v2)
        // =========================================================================
        $event = $payload['event_type'] ?? ($payload['event'] ?? ($headerEventType ?? ''));

        // Suporte ao teste de fogo e pings de webhook (Ex: test.ping, ping, endpoint.test)
        if (in_array($event, ['test.ping', 'ping', 'endpoint.test'])) {
            log_activity('Cora Payments Webhook: Teste de conectividade recebido com sucesso (' . $event . ')');
            set_status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'success', 'message' => 'Webhook endpoint operational']);
            return;
        }

        if (in_array($event, ['invoice.paid', 'INVOICE_PAID'])) {
            $this->load->library('cora_payments/cora_boleto_gateway');
            $cora_id = $payload['data']['id'] ?? ($payload['resource']['id'] ?? ($headerResourceId ?? ($payload['id'] ?? '')));
            
            // Converte centavos de volta para BRL (Cora envia em centavos: ex. 25000 = R$ 250,00)
            $raw_amount = $payload['data']['paid_amount'] ?? ($payload['data']['total_amount'] ?? ($payload['data']['amount'] ?? null));
            $amount = ($raw_amount !== null) ? (((float) $raw_amount) / 100) : null;

            if (!empty($cora_id)) {
                $this->process_boleto_payment($cora_id, $amount);
                set_status_header(200);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'success', 'message' => 'Boleto webhook processed']);
                return;
            }
        } elseif ($event === 'invoice.cancelled') {
            $cora_id = $payload['data']['id'] ?? ($payload['resource']['id'] ?? ($headerResourceId ?? ($payload['id'] ?? '')));
            if (!empty($cora_id)) {
                $this->db->where('cora_invoice_id', $cora_id)
                         ->where_not_in('status', ['PAID', 'CONCLUIDA'])
                         ->update(db_prefix() . 'cora_transactions', ['status' => 'CANCELLED']);
                log_activity('Boleto Cora ' . $cora_id . ' cancelado via Webhook.');
            }
            set_status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'success', 'message' => 'Boleto cancellation processed']);
            return;
        }

        // Responde 200 caso não seja nenhum evento crítico
        set_status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ignored', 'message' => 'No actionable transaction']);
    }

    /**
     * Processamento de pagamento Pix com Dupla Checagem mTLS e Idempotência Estrita
     *
     * @param string $txid
     * @param mixed $valor
     * @param string|null $e2eid
     * @return bool Retorna true se liquidou a fatura nesta execução, false se ignorado ou já liquidado
     */
    protected function process_pix_payment($txid, $valor = null, $e2eid = null)
    {
        // 0. Proteção Anti-Fraude Modo Pix Manual:
        // Transações de Modo Manual possuem TxID gerado com prefixo 'FAT' (ex: FAT123abcd...)
        // e não possuem registro de cobrança imediata na API Cora. Elas NUNCA devem ser liquidadas via Webhook!
        if (strpos($txid, 'FAT') === 0) {
            log_activity('Segurança Cora Pix: Tentativa rejeitada de liquidação via webhook de transação Pix Manual (TxID: ' . $txid . '). Transações manuais exigem conferência e baixa manual no CRM.');
            return false;
        }

        // 1. Localiza a transação local mais recente
        $transacao = $this->db->where('txid', $txid)
                              ->order_by('id', 'DESC')
                              ->get(db_prefix() . 'cora_transactions')
                              ->row();

        if (!$transacao) {
            log_activity('Webhook Pix Cora: Transação não localizada no banco para txid: ' . $txid);
            return false;
        }

        // Se for transação Pix Manual cadastrada sem cora_invoice_id e com prefixo FAT, rejeita
        if (empty($transacao->cora_invoice_id) && strpos($transacao->txid, 'FAT') === 0) {
            log_activity('Segurança Cora Pix: Webhook rejeitado para transação local Pix Manual (ID: ' . $transacao->id . ', TxID: ' . $txid . ')');
            return false;
        }

        if (in_array(strtoupper($transacao->status), ['PAID', 'CONCLUIDA'])) {
            return false;
        }

        // 2. DUPLA CHECAGEM ATIVA ANTI-FRAUDE VIA MTLS (GET /v1/cob/{txid})
        $consulta = $this->cora_api->consultar_cobranca($txid);

        if (!$consulta || !isset($consulta['status']) || !in_array(strtoupper($consulta['status']), ['CONCLUIDA', 'PAID'])) {
            log_activity('Alerta Anti-Fraude Cora Pix: Consulta mTLS ativa rejeitou txid ' . $txid . '. Status: ' . ($consulta['status'] ?? 'FALHA'));
            return false;
        }

        // Valor autenticado
        $valorFinal = !empty($valor) ? (float)$valor : (isset($consulta['valor']['original']) ? (float)$consulta['valor']['original'] : (float)$transacao->amount);
        $e2eFinal   = $e2eid ?: ($consulta['pix'][0]['endToEndId'] ?? $txid);

        // 3. IDEMPOTÊNCIA E CONCORRÊNCIA: ATUALIZAÇÃO ATÔMICA CONDICIONAL PADRONIZADA COM 'PAID'
        $this->db->where('id', $transacao->id);
        $this->db->where('status !=', 'PAID');
        $this->db->update(db_prefix() . 'cora_transactions', [
            'status'  => 'PAID',
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        // Se nenhuma linha foi alterada, outra thread paralela já liquidou
        if ($this->db->affected_rows() === 0) {
            log_activity('Idempotência Cora Pix: Transação já liquidada por processo concorrente (TxID: ' . $txid . ')');
            return false;
        }

        // 4. LIQUIDAÇÃO SEGURA DA FATURA NO PERFEX CRM
        $this->cora_pix_gateway->addPayment([
            'amount'        => $valorFinal,
            'invoiceid'     => $transacao->invoice_id,
            'paymentmode'   => 'cora_pix',
            'paymentmethod' => 'Pix (Cora)',
            'transactionid' => $e2eFinal,
            'note'          => 'Liquidado via PIX Cora. E2E: ' . $e2eFinal . ' | TxID: ' . $txid,
            'date'          => date('Y-m-d H:i:s'),
        ]);

        log_activity('Fatura #' . $transacao->invoice_id . ' liquidada via Pix Cora (TxID: ' . $txid . ', E2E: ' . $e2eFinal . ')');
        return true;
    }

    /**
     * Processamento de pagamento Boleto com Dupla Checagem mTLS e Idempotência Estrita
     *
     * @param string $cora_id ID do boleto na Cora (inv_...)
     * @param float|null $amount Valor em reais
     * @return bool Retorna true se liquidou a fatura nesta execução, false se ignorado ou já liquidado
     */
    protected function process_boleto_payment($cora_id, $amount = null)
    {
        // 1. Localiza a transação local mais recente
        $transacao = $this->db->where('cora_invoice_id', $cora_id)
                              ->order_by('id', 'DESC')
                              ->get(db_prefix() . 'cora_transactions')
                              ->row();

        if (!$transacao) {
            log_activity('Webhook Boleto Cora: Fatura não localizada para cora_id: ' . $cora_id);
            return false;
        }

        if (in_array(strtoupper($transacao->status), ['PAID', 'CONCLUIDA'])) {
            return false;
        }

        // 2. DUPLA CHECAGEM ATIVA ANTI-FRAUDE VIA MTLS (GET /v2/invoices/{id})
        $consulta = $this->cora_api->consultar_fatura($cora_id);

        if (!$consulta || !isset($consulta['status']) || !in_array(strtoupper($consulta['status']), ['PAID', 'CONCLUIDA'])) {
            log_activity('Alerta Anti-Fraude Cora Boleto: Consulta mTLS rejeitou boleto ' . $cora_id . '. Status: ' . ($consulta['status'] ?? 'FALHA'));
            return false;
        }

        // Valor autenticado em Reais (com conversão estrita de centavos)
        $valorFinal = !empty($amount) && (float)$amount > 0 ? (float)$amount : (float)$transacao->amount;
        if (isset($consulta['total_paid']['amount'])) {
            $valorFinal = ((float)$consulta['total_paid']['amount']) / 100;
        } elseif (isset($consulta['payment_terms']['total_amount'])) {
            $valorFinal = ((float)$consulta['payment_terms']['total_amount']) / 100;
        }

        // 3. IDEMPOTÊNCIA E CONCORRÊNCIA: ATUALIZAÇÃO ATÔMICA CONDICIONAL PADRONIZADA COM 'PAID'
        $this->db->where('id', $transacao->id);
        $this->db->where('status !=', 'PAID');
        $this->db->update(db_prefix() . 'cora_transactions', [
            'status'  => 'PAID',
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        if ($this->db->affected_rows() === 0) {
            log_activity('Idempotência Boleto Cora: Transação já liquidada por processo concorrente (Cora ID: ' . $cora_id . ')');
            return false;
        }

        // 4. LIQUIDAÇÃO SEGURA DA FATURA NO PERFEX CRM
        $this->cora_boleto_gateway->addPayment([
            'amount'        => $valorFinal,
            'invoiceid'     => $transacao->invoice_id,
            'paymentmode'   => 'cora_boleto',
            'paymentmethod' => 'Boleto Bancário (Cora)',
            'transactionid' => $cora_id,
            'note'          => 'Liquidado via Boleto Bancário Cora. Cora ID: ' . $cora_id . ' | Barcode: ' . $transacao->barcode,
            'date'          => date('Y-m-d H:i:s'),
        ]);

        log_activity('Fatura #' . $transacao->invoice_id . ' liquidada via Boleto Cora (ID: ' . $cora_id . ')');
        return true;
    }
}

if (!class_exists('Cora_gateway_controller', false)) {
    /**
     * Alias retrocompatível para garantir total interoperabilidade
     */
    class Cora_gateway_controller extends Cora
    {
    }
}


