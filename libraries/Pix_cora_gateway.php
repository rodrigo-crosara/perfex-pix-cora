<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Pix_cora_gateway
 * 
 * Gateway de Pagamento Pix Direto com Banco Cora para Perfex CRM.
 * Implementa autenticação mTLS (Mutual TLS), geração de cobrança Pix imediata (v1/cob),
 * consulta de cobrança anti-fraude (GET v1/cob/{txid}) e integração nativa com faturamento.
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
                'info'          => '<p class="text-muted">Cole aqui o conteúdo textual completo do certificado público (incluindo as linhas -----BEGIN CERTIFICATE----- e -----END CERTIFICATE-----). O conteúdo é salvo criptografado no banco de dados.</p>',
                'rows'          => 6,
            ],
            [
                'name'          => 'key_content',
                'type'          => 'textarea',
                'label'         => 'Chave Privada mTLS (.key)',
                'info'          => '<p class="text-muted">Cole aqui o conteúdo textual da sua chave privada (incluindo as linhas -----BEGIN RSA PRIVATE KEY----- ou -----BEGIN PRIVATE KEY-----). O conteúdo é salvo criptografado no banco de dados.</p>',
                'rows'          => 6,
            ],
            [
                'name'          => 'sandbox',
                'type'          => 'yes_no',
                'default_value' => 0,
                'label'         => 'Ambiente de Testes (Sandbox / Stage)',
                'info'          => '<p class="text-muted">Ative caso utilize credenciais do ambiente de homologação (stage.cora.com.br). Deixe desativado para Produção.</p>',
            ],
            [
                'name'          => 'expiration_minutes',
                'type'          => 'input',
                'label'         => 'Tempo de Expiração do Pix (em minutos)',
                'default_value' => '1440',
                'info'          => '<p class="text-muted">Padrão: 1440 minutos (24 horas).</p>',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'settings_paymentmethod_currencies',
                'default_value' => 'BRL',
            ],
            [
                'name'          => 'webhook_url_info',
                'type'          => 'input',
                'label'         => 'URL do Webhook para configurar no Banco Cora',
                'default_value' => site_url('pix_cora/pix/webhook'),
                'disabled'      => true,
                'info'          => '<p class="text-info"><i class="fa fa-info-circle"></i> Cadastre esta exata URL no portal Cora Developers para conciliação automática com verificação anti-fraude.</p>',
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
     * Obtém o caminho do diretório seguro de certificados
     * Utiliza um token criptográfico único por instalação para blindagem contra Nginx
     *
     * @return string
     */
    public function get_secure_certs_dir()
    {
        $token = get_option('pix_cora_secure_token');
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
            add_option('pix_cora_secure_token', $token);
        }

        $certsDir = module_dir_path('pix_cora', 'certs_' . $token);
        if (!is_dir($certsDir)) {
            @mkdir($certsDir, 0700, true);
            @file_put_contents($certsDir . DIRECTORY_SEPARATOR . '.htaccess', "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n");
            @file_put_contents($certsDir . DIRECTORY_SEPARATOR . 'index.html', '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>');
            @file_put_contents($certsDir . DIRECTORY_SEPARATOR . 'index.php', "<?php\ndefined('BASEPATH') or exit('No direct script access allowed');\nheader('HTTP/1.1 403 Forbidden');\nexit('Access denied.');\n");
        }

        return $certsDir;
    }

    /**
     * Sincroniza os conteúdos dos certificados salvando-os em arquivos físicos locais
     * dentro de pasta segura aleatória com permissão 0600.
     * Suporta dados criptografados com a chave do sistema Perfex CRM.
     *
     * @return array Array com os caminhos absolutos ['cert' => $certPath, 'key' => $keyPath]
     * @throws Exception Caso os certificados não estejam configurados ou não possam ser salvos
     */
    public function sync_certificates()
    {
        $certRaw = trim($this->getSetting('cert_content') ?? '');
        $keyRaw  = trim($this->getSetting('key_content') ?? '');

        if (empty($certRaw) || empty($keyRaw)) {
            throw new Exception('Certificado mTLS (.pem) ou Chave Privada (.key) não configurados nas opções do gateway Pix Cora.');
        }

        // Se o conteúdo estiver encriptado via CI encryption, decripta
        $certContent = $certRaw;
        $keyContent  = $keyRaw;

        if (isset($this->ci->encryption)) {
            if (strpos($certRaw, '-----BEGIN') === false) {
                $decryptedCert = $this->ci->encryption->decrypt($certRaw);
                if ($decryptedCert !== false && strpos($decryptedCert, '-----BEGIN') !== false) {
                    $certContent = $decryptedCert;
                }
            }
            if (strpos($keyRaw, '-----BEGIN') === false) {
                $decryptedKey = $this->ci->encryption->decrypt($keyRaw);
                if ($decryptedKey !== false && strpos($decryptedKey, '-----BEGIN') !== false) {
                    $keyContent = $decryptedKey;
                }
            }
        }

        $certsDir = $this->get_secure_certs_dir();
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
     * 2. Anti-Fraude no Webhook (Dupla Checagem)
     * Realiza uma chamada GET /v1/cob/{txid} autenticada via mTLS na própria API da Cora
     * para confirmar se o status consta de fato como CONCLUIDA no banco antes de dar baixa.
     *
     * @param string $txid Identificador da cobrança Pix
     * @return array|null Dados da cobrança na API Cora ou null se erro
     */
    public function get_charge($txid)
    {
        try {
            $token = $this->get_access_token();
            $certs = $this->sync_certificates();

            $url = $this->get_base_url() . '/v1/cob/' . urlencode($txid);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_HTTPGET        => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSLCERT        => $certs['cert'],
                CURLOPT_SSLKEY         => $certs['key'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                log_activity('Anti-Fraude Cora: Falha de conexão cURL ao consultar txid ' . $txid . ': ' . $curlError);
                return null;
            }

            if ($httpCode === 200) {
                return json_decode($response, true);
            }

            log_activity('Anti-Fraude Cora: Cobrança não retornou 200 (HTTP ' . $httpCode . '): ' . $response);
            return null;
        } catch (Exception $e) {
            log_activity('Anti-Fraude Cora: Exceção ao consultar txid ' . $txid . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cria uma cobrança imediata via Pix (PUT /v1/cob/{txid})
     *
     * @param object $invoice Objeto da fatura do Perfex CRM
     * @param float $amount Valor a ser cobrado (saldo restante ou parcial)
     * @return array Dados da transação criada com txid e pix_copia_cola
     * @throws Exception
     */
    public function create_charge($invoice, $amount)
    {
        $chavePix = trim($this->getSetting('chave_pix') ?? '');
        if (empty($chavePix)) {
            throw new Exception('Chave Pix não configurada nas configurações do gateway.');
        }

        // Cenário 1: Validação prévia de CPF / CNPJ do Pagador
        $vat = '';
        if (isset($invoice->client->vat) && !empty($invoice->client->vat)) {
            $vat = preg_replace('/\D/', '', $invoice->client->vat);
        }

        if (empty($vat) || (strlen($vat) !== 11 && strlen($vat) !== 14)) {
            throw new Exception('CPF ou CNPJ válido do pagador não encontrado no cadastro do cliente. O Banco Cora exige o documento fiscal para emissão do Pix.');
        }

        $token = $this->get_access_token();
        $certs = $this->sync_certificates();

        // Geração de txid alfanumérico único entre 26 e 35 caracteres
        $txid = 'CORA' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));

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

        // Cenário 4: Valor enviado à Cora é rigorosamente o $amount (saldo restante / pagamento parcial)
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

        // Se 14 dígitos é CNPJ, se 11 dígitos é CPF
        if (strlen($vat) === 14) {
            $payload['devedor']['cnpj'] = $vat;
        } else {
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
            $pixCopiaECola = $resJson['textoImagemQRcode'] ?? '';
        }

        if (empty($pixCopiaECola)) {
            throw new Exception('O Banco Cora gerou a cobrança, mas não retornou o código Pix Copia e Cola.');
        }

        // Persistência com amount para suporte a pagamentos parciais
        $this->ci->db->insert(db_prefix() . 'pix_cora_transactions', [
            'invoice_id'     => (int)$invoice->id,
            'txid'           => $txid,
            'amount'         => (float)$amount,
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
     *
     * @param array $data Dados contendo 'invoiceid', 'amount', 'invoice'
     */
    public function process_payment($data)
    {
        $invoice = $data['invoice'];
        // Cenário 4: Garantir que o valor utilizado seja $data['amount'] (saldo restante ou parcial)
        $amount  = (float)$data['amount'];

        // Cenário 2: Validação de moeda da fatura (apenas BRL é suportado no Pix Bacen)
        $currencyName = '';
        if (isset($invoice->currency_name) && !empty($invoice->currency_name)) {
            $currencyName = $invoice->currency_name;
        } elseif (isset($invoice->currency)) {
            $currencyObj = get_currency($invoice->currency);
            if ($currencyObj && isset($currencyObj->name)) {
                $currencyName = $currencyObj->name;
            }
        }
        if (!empty($currencyName) && strtoupper(trim($currencyName)) !== 'BRL') {
            set_alert('warning', 'O pagamento via Pix está disponível exclusivamente para faturas na moeda BRL (Real).');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // Cenário 1: Cliente sem CPF/CNPJ cadastrado
        $vat = '';
        if (isset($invoice->client->vat) && !empty($invoice->client->vat)) {
            $vat = preg_replace('/\D/', '', $invoice->client->vat);
        }
        if (empty($vat) || (strlen($vat) !== 11 && strlen($vat) !== 14)) {
            set_alert('warning', 'O cliente desta fatura não possui um CPF (11 dígitos) ou CNPJ (14 dígitos) válido cadastrado. O Banco Cora exige o documento do pagador para emitir o Pix.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        try {
            // Verifica se já existe uma transação Pix ATIVA com o mesmo valor gerada recentemente
            $this->ci->db->where('invoice_id', $invoice->id);
            $this->ci->db->where('status', 'ATIVA');
            $this->ci->db->where('amount', $amount);
            $this->ci->db->order_by('id', 'DESC');
            $existing = $this->ci->db->get(db_prefix() . 'pix_cora_transactions')->row();

            if ($existing) {
                $createdAt = strtotime($existing->created_at);
                // Reutiliza se tiver menos de 12 horas
                if ((time() - $createdAt) < (12 * 3600)) {
                    redirect(site_url('pix_cora/pix/pay/' . $invoice->id . '/' . $existing->txid));
                    return;
                }
            }

            // Cria nova cobrança Pix com o valor específico solicitado
            $charge = $this->create_charge($invoice, $amount);

            set_alert('success', 'Código Pix gerado com sucesso! Efetue o pagamento via QR Code ou Copia e Cola.');
            redirect(site_url('pix_cora/pix/pay/' . $invoice->id . '/' . $charge['txid']));
        } catch (Exception $e) {
            log_activity('Falha no processamento Pix Cora para Fatura #' . $invoice->id . ': ' . $e->getMessage());
            set_alert('danger', 'Não foi possível gerar a cobrança Pix: ' . $e->getMessage());
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }
    }
}
