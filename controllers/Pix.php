<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controller Público Pix Banco Cora
 * 
 * Responsável por:
 * 1. Exibir a tela de pagamento com QR Code dinâmico e código Copia e Cola.
 * 2. Fornecer endpoint JSON para polling de status pelo cliente.
 * 3. Processar webhooks de conciliação automática enviados pelo Banco Cora.
 */
class Pix extends App_Controller
{
    public function __construct()
    {
        parent::__construct();

        // Carrega models essenciais do Perfex CRM
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
            set_alert('info', 'Esta fatura já foi paga e baixada no sistema.');
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

        $data = [
            'title'            => 'Pagamento Pix - Fatura #' . format_invoice_number($invoice->id),
            'invoice'          => $invoice,
            'transaction'      => $transaction,
            'pix_copia_cola'   => $transaction->pix_copia_cola,
            'amount'           => $invoice->total_left_to_pay ?? $invoice->total,
            'check_status_url' => site_url('pix_cora/pix/check_status/' . $invoice->id . '/' . $txid),
            'invoice_url'      => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ];

        $this->load->view('pix_cora/payment', $data);
    }

    /**
     * Endpoint AJAX para polling do frontend
     * Verifica se o pagamento já foi recebido e liquidado pelo Webhook
     * 
     * @param int $invoice_id
     * @param string $txid
     */
    public function check_status($invoice_id = null, $txid = null)
    {
        $this->output->set_content_type('application/json', 'utf-8');

        if (empty($invoice_id) || empty($txid)) {
            $this->output->set_output(json_encode(['paid' => false, 'error' => 'Parâmetros inválidos']));
            return;
        }

        $invoice_id = (int)$invoice_id;
        $invoice = $this->invoices_model->get($invoice_id);

        $this->db->where('invoice_id', $invoice_id);
        $this->db->where('txid', $txid);
        $transaction = $this->db->get(db_prefix() . 'pix_cora_transactions')->row();

        $isPaid = false;
        if (($transaction && $transaction->status === 'CONCLUIDA') || ($invoice && (int)$invoice->status === 2)) {
            $isPaid = true;
        }

        $redirectUrl = $invoice ? site_url('invoice/' . $invoice->id . '/' . $invoice->hash) : site_url();

        $this->output->set_output(json_encode([
            'paid'         => $isPaid,
            'status'       => $transaction ? $transaction->status : 'INEXISTENTE',
            'redirect_url' => $redirectUrl,
        ]));
    }

    /**
     * Endpoint receptor do Webhook do Banco Cora
     * Processa notificações em lote ou individuais enviadas pela Cora/Bacen
     */
    public function webhook()
    {
        // Desativa checagem de CSRF para o payload recebido do webhook
        if (isset($this->security)) {
            $this->security->csrf_verify = false;
        }

        $rawInput = file_get_contents('php://input');

        if (empty($rawInput)) {
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json', 'utf-8')
                ->set_output(json_encode(['status' => 'error', 'message' => 'Empty payload']));
            return;
        }

        $data = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            log_activity('Webhook Pix Cora com JSON malformado: ' . $rawInput);
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json', 'utf-8')
                ->set_output(json_encode(['status' => 'error', 'message' => 'Invalid JSON']));
            return;
        }

        // Identifica e normaliza a lista de pagamentos Pix recebidos
        $pixItems = [];

        if (isset($data['pix']) && is_array($data['pix'])) {
            // Padrão Bacen (array de pix recebidos)
            $pixItems = $data['pix'];
        } elseif (isset($data['txid'])) {
            // Payload simples/unitário com dados diretos
            $pixItems[] = $data;
        } elseif (isset($data['data']['txid'])) {
            // Envelope de evento da Cora
            $pixItems[] = $data['data'];
        }

        if (empty($pixItems)) {
            // Notificação de handshake ou evento sem pagamentos
            $this->output
                ->set_status_header(200)
                ->set_content_type('application/json', 'utf-8')
                ->set_output(json_encode(['status' => 'ignored', 'message' => 'No pix items found']));
            return;
        }

        $processedCount = 0;

        foreach ($pixItems as $item) {
            $txid = $item['txid'] ?? null;
            $valor = $item['valor'] ?? ($item['value'] ?? null);
            $endToEndId = $item['endToEndId'] ?? ($item['end_to_end_id'] ?? null);

            if (empty($txid)) {
                continue;
            }

            // Localiza a transação local pelo txid
            $this->db->where('txid', $txid);
            $transaction = $this->db->get(db_prefix() . 'pix_cora_transactions')->row();

            if (!$transaction) {
                log_activity('Webhook Pix Cora: Transação não localizada para txid: ' . $txid);
                continue;
            }

            // Verifica idempotência: se já foi concluída, não faz baixa duplicada
            if ($transaction->status === 'CONCLUIDA') {
                $processedCount++;
                continue;
            }

            $invoice = $this->invoices_model->get($transaction->invoice_id);
            if (!$invoice) {
                log_activity('Webhook Pix Cora: Fatura #' . $transaction->invoice_id . ' não encontrada.');
                continue;
            }

            $paymentAmount = !empty($valor) ? (float)$valor : (float)$invoice->total;

            // Efetua a baixa oficial da fatura no Perfex CRM via addPayment do gateway
            $paymentData = [
                'amount'        => $paymentAmount,
                'invoiceid'     => (int)$transaction->invoice_id,
                'paymentmode'   => 'pix_cora',
                'paymentmethod' => 'Pix Banco Cora',
                'transactionid' => !empty($endToEndId) ? $endToEndId : $txid,
                'note'          => 'Pagamento Pix recebido via Webhook Cora. EndToEndId: ' . ($endToEndId ?: 'N/A') . ' | TxID: ' . $txid,
                'date'          => date('Y-m-d H:i:s'),
            ];

            $paymentId = $this->pix_cora_gateway->addPayment($paymentData);

            if ($paymentId) {
                // Atualiza a tabela de transações do módulo para status CONCLUIDA
                $this->db->where('id', $transaction->id);
                $this->db->update(db_prefix() . 'pix_cora_transactions', [
                    'status'  => 'CONCLUIDA',
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);

                log_activity('Pagamento Pix Banco Cora liquidado com sucesso para Fatura #' . $transaction->invoice_id . ' (TxID: ' . $txid . ')');
                $processedCount++;
            } else {
                log_activity('Falha ao registrar pagamento no Perfex CRM para Fatura #' . $transaction->invoice_id . ' (TxID: ' . $txid . ')');
            }
        }

        $this->output
            ->set_status_header(200)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode([
                'status'    => 'success',
                'processed' => $processedCount,
            ]));
    }
}
