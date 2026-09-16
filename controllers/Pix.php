<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controller Público Pix Banco Cora
 * 
 * Responsável por:
 * 1. Exibir a tela de pagamento com QR Code dinâmico e código Copia e Cola.
 * 2. Fornecer endpoint JSON de polling em tempo real para a view.
 * 3. Processar webhooks com dupla checagem mTLS na API Cora e conciliação atômica anti-concorrência.
 * 4. Endpoint de validação instantânea de conexão mTLS para administradores no painel.
 */
class Pix extends App_Controller
{
    public function __construct()
    {
        parent::__construct();

        // Carrega models e biblioteca do gateway
        $this->load->model('invoices_model');
        $this->load->library('pix_cora/pix_cora_gateway');
    }

    /**
     * Tela de exibição do pagamento Pix (QR Code e Copia e Cola)
     * 
     * @param int $invoice_id ID da fatura
     * @param string $txid Identificador da transação Pix
     */
    public function pay($invoice_id = null, $txid = null)
    {
        if (empty($invoice_id) || empty($txid)) {
            show_404();
            return;
        }

        $invoice_id = (int)$invoice_id;
        $invoice = $this->invoices_model->get($invoice_id);

        if (!$invoice) {
            show_404();
            return;
        }

        // Se a fatura já estiver paga (Status 2 = STATUS_PAID no Perfex CRM)
        if ((int)$invoice->status === 2) {
            set_alert('info', 'Esta fatura já se encontra liquidada.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // Busca o registro da transação
        $this->db->where('invoice_id', $invoice_id);
        $this->db->where('txid', $txid);
        $transaction = $this->db->get(db_prefix() . 'pix_cora_transactions')->row();

        if (!$transaction) {
            show_error('Transação Pix não encontrada para esta fatura.', 404);
            return;
        }

        // Se o status da transação já for CONCLUIDA
        if ($transaction->status === 'CONCLUIDA') {
            set_alert('success', 'O pagamento deste Pix já foi confirmado com sucesso!');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // Determina o valor exato a ser pago (suporte a pagamento parcial)
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
            'check_status_url' => site_url('pix_cora/pix/check_status/' . $invoice->id . '/' . $txid),
            'invoice_url'      => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ];

        $this->load->view('pix_cora/payment', $data);
    }

    /**
     * Endpoint de Polling para a Tela de Pagamento (payment.php)
     * Rota: GET pix_cora/pix/check_status/{invoice_id}/{txid}
     * O JavaScript da view payment.php consulta essa rota a cada 3 a 5 segundos.
     * Assim que o status for CONCLUIDA ou a fatura estiver paga, retorna JSON confirmando.
     * 
     * @param int $invoice_id
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
                              ->get(db_prefix() . 'pix_cora_transactions')->row();

        $invoice = $this->invoices_model->get((int)$invoice_id);

        $isPaid = false;
        if (($transacao && $transacao->status === 'CONCLUIDA') || ($invoice && (int)$invoice->status === 2)) {
            $isPaid = true;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'paid'         => $isPaid,
            'status'       => $transacao ? $transacao->status : 'INEXISTENTE',
            'redirect_url' => $invoice ? site_url('invoice/' . $invoice->id . '/' . $invoice->hash) : site_url(),
        ]);
        exit;
    }

    /**
     * 2. Botão "Testar Conexão com a Cora" (Validação Instantânea)
     * Permite ao administrador validar no painel se Client ID e Certificados mTLS estão corretos.
     */
    public function test_connection()
    {
        // Validação de permissões de acesso administrativo
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

        // 3. Diagnóstico Automático do Certificado e Chave Privada
        $diag = $this->pix_cora_gateway->diagnosticar_certificados();
        if (!$diag['cert_valido']) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro no Certificado: ' . $diag['cert_mensagem']
            ]);
            exit;
        }

        if (!$diag['key_valida']) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro na Chave Privada: ' . $diag['key_mensagem']
            ]);
            exit;
        }

        // Tenta autenticar via mTLS na API da Cora
        try {
            $token = $this->pix_cora_gateway->get_token();

            if ($token) {
                $isSandbox = (int)$this->pix_cora_gateway->getSetting('sandbox') === 1;
                $ambiente = $isSandbox ? 'Homologação / Stage' : 'Produção';

                echo json_encode([
                    'success' => true,
                    'message' => 'Conexão mTLS bem-sucedida no ambiente ' . $ambiente . '! Certificados válidos e Token OAuth2 gerado pela Cora com sucesso.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Falha na autenticação mTLS. Verifique os certificados e o Client ID.'
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro na validação mTLS: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    /**
     * Processamento do Webhook com Concorrência Atômica e Dupla Checagem Ativa
     */
    public function webhook()
    {
        // Desativa a checagem de CSRF para requisições externas do webhook
        if (isset($this->security)) {
            $this->security->csrf_verify = false;
        }

        $rawInput = file_get_contents('php://input');

        if (empty($rawInput)) {
            set_status_header(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
            return;
        }

        $data = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            log_activity('Webhook Pix Cora com JSON malformado: ' . $rawInput);
            set_status_header(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
            return;
        }

        // Identifica e normaliza os itens de Pix notificados
        $pixItems = [];

        if (isset($data['pix']) && is_array($data['pix'])) {
            $pixItems = $data['pix'];
        } elseif (isset($data['txid'])) {
            $pixItems[] = $data;
        } elseif (isset($data['data']['txid'])) {
            $pixItems[] = $data['data'];
        }

        if (empty($pixItems)) {
            set_status_header(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ignored', 'message' => 'No pix items found']);
            return;
        }

        foreach ($pixItems as $item) {
            $txid  = $item['txid'] ?? null;
            $e2eid = $item['endToEndId'] ?? ($item['end_to_end_id'] ?? $txid);

            if (empty($txid)) {
                continue;
            }

            // Localiza a transação local
            $reg = $this->db->where('txid', $txid)->get(db_prefix() . 'pix_cora_transactions')->row();

            if (!$reg) {
                log_activity('Webhook Pix Cora: Transação não localizada no banco para txid: ' . $txid);
                continue;
            }

            // Se a transação já foi marcada como CONCLUIDA, ignora
            if ($reg->status === 'CONCLUIDA') {
                continue;
            }

            // =========================================================================
            // 2. DUPLA CHECAGEM ATIVA ANTI-FRAUDE (GET /v1/cob/{txid})
            // =========================================================================
            $consulta = $this->pix_cora_gateway->consultar_cobranca($txid);

            if (!$consulta || !isset($consulta['status']) || strtoupper($consulta['status']) !== 'CONCLUIDA') {
                log_activity('Alerta Anti-Fraude Pix Cora: Consulta mTLS ativa rejeitou txid ' . $txid . '. Status na Cora: ' . ($consulta['status'] ?? 'FALHA'));
                continue;
            }

            // Obtém o valor autenticado retornado pela Cora ou o valor da transação
            $valor = isset($consulta['valor']['original']) ? (float)$consulta['valor']['original'] : (float)$reg->amount;

            // =========================================================================
            // 1. CONCORRÊNCIA E IDEMPOTÊNCIA: ATUALIZAÇÃO CONDICIONAL ATÔMICA
            // Apenas a thread que atualizar a linha com sucesso tem permissão para dar baixa
            // =========================================================================
            $this->db->where('txid', $txid);
            $this->db->where('status !=', 'CONCLUIDA');
            $this->db->update(db_prefix() . 'pix_cora_transactions', [
                'status'  => 'CONCLUIDA',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);

            // Se nenhuma linha foi alterada, a transação já foi processada por outra thread paralela
            if ($this->db->affected_rows() === 0) {
                continue;
            }

            // =========================================================================
            // 3. DÁ BAIXA SEGURA NA FATURA NO PERFEX CRM
            // =========================================================================
            $this->pix_cora_gateway->addPayment([
                'amount'        => $valor,
                'invoiceid'     => $reg->invoice_id,
                'paymentmode'   => 'pix_cora',
                'paymentmethod' => 'PIX (Cora)',
                'transactionid' => $e2eid,
                'note'          => 'Liquidado via PIX Direto Cora. E2E: ' . $e2eid . ' | TxID: ' . $txid,
                'date'          => date('Y-m-d H:i:s'),
            ]);

            log_activity('Fatura #' . $reg->invoice_id . ' liquidada com sucesso via Pix Cora (TxID: ' . $txid . ', E2E: ' . $e2eid . ')');
        }

        set_status_header(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        return;
    }
}
