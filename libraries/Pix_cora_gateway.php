<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Pix_cora_gateway
 * 
 * Gateway de Pagamento Pix Direto com Banco Cora para Perfex CRM.
 * Implementa autenticação mTLS (Mutual TLS), geração de cobrança Pix imediata (v1/cob)
 * e integração com o sistema de faturamento e pagamentos do Perfex CRM.
 */
class Pix_cora_gateway extends App_gateway
{
    /**
     * URL base de produção da API mTLS da Cora
     */
    const PROD_BASE_URL = 'https://matls-clients.api.cora.com.br';

    /**
     * URL base do ambiente de testes (Sandbox/Stage) da API mTLS da Cora
     */
    const STAGE_BASE_URL = 'https://matls-clients.stage.cora.com.br';

    public function __construct()
    {
        parent::__construct();

        $this->setId('pix_cora');
        $this->setName('Pix Banco Cora');

        /**
         * Configuração dos campos visíveis na aba Configurações > Gateways de Pagamento
         */
        $this->setSettings([
            [
                'name'  => 'client_id',
                'type'  => 'input',
                'label' => 'Client ID (Cora)',
                'info'  => '<p class="text-muted">Client ID fornecido no painel de desenvolvedor do Banco Cora.</p>',
            ],
            [
                'name'  => 'chave_pix',
                'type'  => 'input',
                'label' => 'Chave Pix (Cora)',
                'info'  => '<p class="text-muted">Chave Pix cadastrada na sua conta Cora (CNPJ, E-mail, Telefone ou Aleatória).</p>',
            ],
            [
                'name'          => 'cert_content',
                'type'          => 'textarea',
                'label'         => 'Certificado mTLS (.pem ou .crt)',
                'info'          => '<p class="text-muted">Cole aqui o conteúdo textual completo do seu certificado público (incluindo as linhas -----BEGIN CERTIFICATE----- e -----END CERTIFICATE-----).</p>',
                'rows'          => 6,
            ],
            [
                'name'          => 'key_content',
                'type'          => 'textarea',
                'label'         => 'Chave Privada mTLS (.key)',
                'info'          => '<p class="text-muted">Cole aqui o conteúdo textual da sua chave privada (incluindo as linhas -----BEGIN RSA PRIVATE KEY----- ou -----BEGIN PRIVATE KEY-----).</p>',
                'rows'          => 6,
            ],
            [
                'name'          => 'currencies',
                'label'         => 'settings_paymentmethod_currencies',
                'default_value' => 'BRL',
            ],
            [
                'name'          => 'sandbox',
                'type'          => 'yes_no',
                'default_value' => 0,
                'label'         => 'Ambiente de Testes (Sandbox / Stage)',
                'info'          => '<p class="text-muted">Ative caso utilize credenciais do ambiente de homologação do Banco Cora.</p>',
            ],
            [
                'name'          => 'expiration_minutes',
                'type'          => 'input',
                'label'         => 'Tempo de Expiração do Pix (em minutos)',
                'default_value' => '1440',
                'info'          => '<p class="text-muted">Padrão: 1440 minutos (24 horas).</p>',
            ],
            [
                'name'  => 'webhook_url_info',
                'type'  => 'input',
                'label' => 'URL do Webhook para configurar no Banco Cora',
                'default_value' => site_url('pix_cora/pix/webhook'),
                'disabled' => true,
                'info'  => '<p class="text-info"><i class="fa fa-info-circle"></i> Cadastre esta exata URL no portal Cora Developers para conciliação automática.</p>',
            ],
        ]);
    }

    /**
     * Retorna a URL base de acordo com o ambiente configurado
     * 
     * @return string
     */
    public function get_base_url()
    {
        return ((int)$this->getSetting('sandbox') === 1) ? self::STAGE_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * Sincroniza os conteúdos dos campos de texto cert_content e key_content
     * salvando-os em arquivos físicos locais em certs/ com permissão 0600.
     *
     * @return array Array com os caminhos absolutos ['cert' => $certPath, 'key' => $keyPath]
     * @throws Exception Caso os certificados não estejam configurados ou não possam ser salvos
     */
    public function sync_certificates()
    {
        $certContent = trim($this->getSetting('cert_content') ?? '');
        $keyContent  = trim($this->getSetting('key_content') ?? '');

        if (empty($certContent) || empty($keyContent)) {
            throw new Exception('Certificado mTLS (.pem) ou Chave Privada (.key) não configurados nas opções do gateway Pix Cora.');
        }

        $certsDir = module_dir_path('pix_cora', 'certs');
        if (!is_dir($certsDir)) {
            if (!@mkdir($certsDir, 0700, true)) {
                throw new Exception('Falha ao criar o diretório protegido de certificados: ' . $certsDir);
            }
        }

        $certPath = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . 'cora_cert.pem';
        $keyPath  = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . 'cora_key.key';

        // Grava o certificado se o arquivo não existir ou se o conteúdo mudou
        if (!file_exists($certPath) || file_get_contents($certPath) !== $certContent) {
            file_put_contents($certPath, $certContent);
            @chmod($certPath, 0600);
        }

        // Grava a chave privada se o arquivo não existir ou se o conteúdo mudou
        if (!file_exists($keyPath) || file_get_contents($keyPath) !== $keyContent) {
            file_put_contents($keyPath, $keyContent);
            @chmod($keyPath, 0600);
        }

        return [
            'cert' => $certPath,
            'key'  => $keyPath,
        ];
    }

    /**
     * Executa a autenticação mTLS OAuth2 Client Credentials junto à Cora
     *
     * @return string Token de acesso (Bearer Token)
     * @throws Exception
     */
    public function get_access_token()
    {
        $clientId = trim($this->getSetting('client_id') ?? '');
        if (empty($clientId)) {
            throw new Exception('Client ID não configurado no módulo Pix Banco Cora.');
        }

        $certs = $this->sync_certificates();
        $tokenUrl = $this->get_base_url() . '/token';

        $postFields = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id'  => $clientId,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $tokenUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT        => $certs['cert'],
            CURLOPT_SSLKEY         => $certs['key'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_activity('Erro cURL na autenticação Cora mTLS: ' . $curlError);
            throw new Exception('Erro de conexão mTLS com Banco Cora: ' . $curlError);
        }

        $json = json_decode($response, true);

        if ($httpCode !== 200 || !isset($json['access_token'])) {
            $msg = $json['message'] ?? ($json['error_description'] ?? 'Resposta inválida do servidor de autenticação Cora');
            log_activity('Falha no token mTLS Cora (HTTP ' . $httpCode . '): ' . $response);
            throw new Exception('Erro de autenticação mTLS no Banco Cora: ' . $msg);
        }

        return $json['access_token'];
    }

    /**
     * Cria uma cobrança imediata via Pix (PUT /v1/cob/{txid})
     *
     * @param object $invoice Objeto da fatura do Perfex CRM
     * @param float $amount Valor a ser cobrado
     * @return array Dados da transação criada com txid e pix_copia_cola
     * @throws Exception
     */
    public function create_charge($invoice, $amount)
    {
        $chavePix = trim($this->getSetting('chave_pix') ?? '');
        if (empty($chavePix)) {
            throw new Exception('Chave Pix não configurada nas configurações do gateway.');
        }

        $token = $this->get_access_token();
        $certs = $this->sync_certificates();

        // Geração de txid alfanumérico único entre 26 e 35 caracteres
        // 'CORA' (4) + YmdHis (14) + 12 caracteres hexadecimais aleatórios = 30 caracteres
        $txid = 'CORA' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));

        // Trata dinamicamente o documento fiscal do pagador
        $vat = '';
        if (isset($invoice->client->vat) && !empty($invoice->client->vat)) {
            $vat = preg_replace('/\D/', '', $invoice->client->vat);
        }

        $clientName = '';
        if (isset($invoice->client->company) && !empty($invoice->client->company)) {
            $clientName = trim($invoice->client->company);
        } elseif (isset($invoice->clientid)) {
            $clientName = get_company_name($invoice->clientid);
        }
        if (empty($clientName)) {
            $clientName = 'Cliente Fatura #' . $invoice->id;
        }

        $expirationMinutes = (int)($this->getSetting('expiration_minutes') ?: 1440);
        $expirationSeconds = $expirationMinutes * 60;

        // Montagem do payload padrão Bacen aceito pelo Banco Cora
        $payload = [
            'calendario' => [
                'expiracao' => $expirationSeconds,
            ],
            'devedor' => [
                'nome' => mb_substr($clientName, 0, 200, 'UTF-8'),
            ],
            'valor' => [
                'original' => number_format((float)$amount, 2, '.', ''),
            ],
            'chave' => $chavePix,
            'solicitacaoPagador' => 'Fatura #' . format_invoice_number($invoice->id),
        ];

        // Se o documento tiver 14 dígitos é CNPJ, se tiver 11 é CPF
        if (strlen($vat) === 14) {
            $payload['devedor']['cnpj'] = $vat;
        } elseif (strlen($vat) === 11) {
            $payload['devedor']['cpf'] = $vat;
        }

        $url = $this->get_base_url() . '/v1/cob/' . $txid;
        $jsonPayload = json_encode($payload);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT        => $certs['cert'],
            CURLOPT_SSLKEY         => $certs['key'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_activity('Erro cURL ao emitir Pix Cora (txid: ' . $txid . '): ' . $curlError);
            throw new Exception('Erro de conexão ao criar cobrança Pix na Cora: ' . $curlError);
        }

        $resJson = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            $errorDetail = $resJson['mensagem'] ?? ($resJson['message'] ?? ($resJson['detail'] ?? 'Erro desconhecido retornado pela API Cora'));
            log_activity('Erro API Cora Cobrança (HTTP ' . $httpCode . '): ' . $response);
            throw new Exception('Banco Cora rejeitou a cobrança Pix: ' . $errorDetail);
        }

        // Obtém o código Pix Copia e Cola
        $pixCopiaECola = $resJson['pixCopiaECola'] ?? ($resJson['qrcode'] ?? ($resJson['emv'] ?? ''));

        if (empty($pixCopiaECola) && isset($resJson['loc']['id'])) {
            // Em implementações Bacen onde o loc é retornado separado, tenta obter o payload
            $pixCopiaECola = $resJson['textoImagemQRcode'] ?? '';
        }

        if (empty($pixCopiaECola)) {
            throw new Exception('O Banco Cora criou a cobrança, mas não retornou o código Pix Copia e Cola.');
        }

        // Persistência no banco de dados do Perfex CRM
        $this->ci->db->insert(db_prefix() . 'pix_cora_transactions', [
            'invoice_id'     => (int)$invoice->id,
            'txid'           => $txid,
            'pix_copia_cola' => $pixCopiaECola,
            'status'         => 'ATIVA',
            'created_at'     => date('Y-m-d H:i:s'),
            'paid_at'        => null,
        ]);

        return [
            'txid'           => $txid,
            'pix_copia_cola' => $pixCopiaECola,
            'invoice_id'     => $invoice->id,
            'amount'         => $amount,
        ];
    }

    /**
     * Processa a solicitação de pagamento disparada pelo Perfex CRM
     * quando o cliente opta por pagar a fatura com o Pix Banco Cora.
     *
     * @param array $data Dados contendo 'invoiceid', 'amount', 'invoice'
     */
    public function process_payment($data)
    {
        $invoice = $data['invoice'];
        $amount  = (float)$data['amount'];

        try {
            // Verifica se já existe uma transação Pix ATIVA gerada nos últimos minutos com o mesmo valor
            $this->ci->db->where('invoice_id', $invoice->id);
            $this->ci->db->where('status', 'ATIVA');
            $this->ci->db->order_by('id', 'DESC');
            $existing = $this->ci->db->get(db_prefix() . 'pix_cora_transactions')->row();

            if ($existing) {
                // Se a transação foi criada há menos de 12 horas, podemos reutilizá-la
                $createdAt = strtotime($existing->created_at);
                if ((time() - $createdAt) < (12 * 3600)) {
                    redirect(site_url('pix_cora/pix/pay/' . $invoice->id . '/' . $existing->txid));
                    return;
                }
            }

            // Cria uma nova cobrança Pix na Cora
            $charge = $this->create_charge($invoice, $amount);

            set_alert('success', 'Cobrança Pix gerada com sucesso! Efetue o pagamento lendo o QR Code ou copiando o código.');
            redirect(site_url('pix_cora/pix/pay/' . $invoice->id . '/' . $charge['txid']));
        } catch (Exception $e) {
            log_activity('Falha no processamento Pix Cora para Fatura #' . $invoice->id . ': ' . $e->getMessage());
            set_alert('danger', 'Não foi possível gerar a cobrança Pix: ' . $e->getMessage());
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }
    }
}
