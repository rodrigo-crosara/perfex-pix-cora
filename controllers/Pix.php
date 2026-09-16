<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controller Público Pix Banco Cora
 * 
 * Responsável por:
 * 1. Exibir a tela de pagamento com QR Code dinâmico e código Copia e Cola.
 * 2. Fornecer endpoint JSON para polling de status pelo cliente.
 * 3. Processar webhooks com dupla checagem anti-fraude via mTLS na API Cora.
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
            'pix_copia_cola'   => $transaction->pix_copia_cola,
            'amount'           => $amountToPay,
            'check_status_url' => site_url('pix_cora/pix/check_status/' . $invoice->id . '/' . $txid),
            'invoice_url'      => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ];

        $this->load->view('pix_cora/payment', $data);
    }

    /**
     * 4. Endpoint de Polling para a Tela de Pagamento
     * Rota: GET pix_cora/pix/check_status/{invoice_id}/{txid}
     * O JavaScript da view consulta essa rota a cada 3 a 5 segundos.
     * Assim que o status for CONCLUIDA ou a fatura estiver paga, retorna paid: true
     * 
     * @param int $invoice_id
     * @param string $txid
     */
    public function check_status($invoice_id = null, $txid = null)
    {
        $this->output->set_content_type('application/json', 'utf-8');

        if (empty($invoice_id) || empty($txid)) {
            $this->output->set_output(json_encode([
                'paid'  => false,
                'error' => 'Parâmetros ausentes'
            ]));
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
     * 2. Webhook com Anti-Fraude (Dupla Checagem)
     * Não confia cegamente no POST recebido. Consulta a API da Cora via mTLS (GET /v1/cob/{txid})
     * para confirmar que a cobrança consta como CONCLUIDA diretamente nos servidores do banco.
     */
    public function webhook()
    {
        // Garante que o CSRF não interfira na requisição externa
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

        // Identifica os itens de Pix notificados
        $pixItems = [];

        if (isset($data['pix']) && is_array($data['pix'])) {
            $pixItems = $data['pix'];
        } elseif (isset($data['txid'])) {
            $pixItems[] = $data;
        } elseif (isset($data['data']['txid'])) {
            $pixItems[] = $data['data'];
        }

        if (empty($pixItems)) {
            $this->output
                ->set_status_header(200)
                ->set_content_type('application/json', 'utf-8')
                ->set_output(json_encode(['status' => 'ignored', 'message' => 'No pix items']));
            return;
        }

        $processedCount = 0;

        foreach ($pixItems as $item) {
            $txid = $item['txid'] ?? null;
            $endToEndId = $item['endToEndId'] ?? ($item['end_to_end_id'] ?? null);

            if (empty($txid)) {
                continue;
            }

            // Localiza a transação local
            $this->db->where('txid', $txid);
            $transaction = $this->db->get(db_prefix() . 'pix_cora_transactions')->row();

            if (!$transaction) {
                log_activity('Webhook Pix Cora: Transação não localizada no banco local para txid: ' . $txid);
                continue;
            }

            // Idempotência: se já foi concluída, não executa baixa duplicada
            if ($transaction->status === 'CONCLUIDA') {
                $processedCount++;
                continue;
            }

            // =========================================================================
            // ANTI-FRAUDE: DUPLA CHECAGEM OBRIGATÓRIA NA API CORA VIA mTLS
            // =========================================================================
            $chargeData = $this->pix_cora_gateway->get_charge($txid);

            if (!$chargeData || !isset($chargeData['status'])) {
                log_activity('Alerta Anti-Fraude Pix Cora: Consulta mTLS falhou ao verificar txid ' . $txid . '. Notificação descartada.');
                continue;
            }

            $coraStatus = strtoupper(trim($chargeData['status']));
            if ($coraStatus !== 'CONCLUIDA') {
                log_activity('Alerta Anti-Fraude Pix Cora: Status da cobrança na API Cora é "' . $coraStatus . '" (não CONCLUIDA). Baixa cancelada para txid: ' . $txid);
                continue;
            }

            // Validação de fatura
            $invoice = $this->invoices_model->get($transaction->invoice_id);
            if (!$invoice) {
                log_activity('Webhook Pix Cora: Fatura #' . $transaction->invoice_id . ' inexistente.');
                continue;
            }

            // Valor autenticado pela API Cora ou registrado na transação
            $officialAmount = 0.0;
            if (isset($chargeData['valor']['original'])) {
                $officialAmount = (float)$chargeData['valor']['original'];
            } elseif (!empty($transaction->amount) && (float)$transaction->amount > 0) {
                $officialAmount = (float)$transaction->amount;
            } else {
                $officialAmount = (float)$invoice->total;
            }

            // Baixa contábil no Perfex CRM
            $paymentData = [
                'amount'        => $officialAmount,
                'invoiceid'     => (int)$transaction->invoice_id,
                'paymentmode'   => 'pix_cora',
                'paymentmethod' => 'Pix Banco Cora',
                'transactionid' => !empty($endToEndId) ? $endToEndId : $txid,
                'note'          => 'Pagamento Pix Banco Cora confirmado via mTLS Anti-Fraude. EndToEndId: ' . ($endToEndId ?: 'N/A') . ' | TxID: ' . $txid,
                'date'          => date('Y-m-d H:i:s'),
            ];

            $paymentId = $this->pix_cora_gateway->addPayment($paymentData);

            if ($paymentId) {
                // Atualiza status da transação local
                $this->db->where('id', $transaction->id);
                $this->db->update(db_prefix() . 'pix_cora_transactions', [
                    'status'  => 'CONCLUIDA',
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);

                log_activity('Pagamento Pix Cora liquidado e verificado com sucesso para Fatura #' . $transaction->invoice_id . ' (TxID: ' . $txid . ')');
                $processedCount++;
            } else {
                log_activity('Falha ao registrar pagamento via addPayment para Fatura #' . $transaction->invoice_id);
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
