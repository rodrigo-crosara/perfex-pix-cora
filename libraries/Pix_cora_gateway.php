<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Pix_cora_gateway
 * 
 * Gateway de Pagamento Pix Direto com Banco Cora para Perfex CRM.
 * Implementa autenticação mTLS (Mutual TLS), geração de cobrança Pix imediata (v1/cob),
 * consulta ativa anti-fraude (GET v1/cob/{txid}), diagnóstico em tempo real de certificados,
 * gravação otimizada de certificados e controle rígido de concorrência e moedas.
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

    /**
     * URL da API dinamicamente ajustada com base no ambiente (Sandbox vs Produção)
     *
     * @var string
     */
    public $api_base_url;

    public function __construct()
    {
        parent::__construct();

        $this->setId('pix_cora');
        $this->setName('Pix Banco Cora');

        // 4. Alternador de Ambiente: Ajuste dinâmico da URL base
        $is_sandbox = (bool)$this->getSetting('sandbox');
        $this->api_base_url = $is_sandbox ? self::STAGE_BASE_URL : self::PROD_BASE_URL;

        // 1. URL do Webhook pronta para copiar
        $webhook_url = site_url('pix_cora/pix/webhook');

        // 3. Diagnóstico Automático dos Certificados para exibição nas configurações
        $diag = $this->diagnosticar_certificados();

        $certInfo = '<p class="text-muted">Cole o conteúdo textual completo do certificado público (.pem ou .crt), incluindo as linhas -----BEGIN CERTIFICATE----- e -----END CERTIFICATE-----.</p>';
        if (!empty($this->getSetting('cert_content'))) {
            if ($diag['cert_valido']) {
                $certInfo .= '<div class="alert alert-success mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-check-circle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
            } else {
                $certInfo .= '<div class="alert alert-danger mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-exclamation-triangle"></i> <strong>' . html_escape($diag['cert_mensagem']) . '</strong></div>';
            }
        }

        $keyInfo = '<p class="text-muted">Cole o conteúdo textual da sua chave privada (.key), incluindo as linhas -----BEGIN RSA PRIVATE KEY----- ou -----BEGIN PRIVATE KEY-----.</p>';
        if (!empty($this->getSetting('key_content'))) {
            if ($diag['key_valida']) {
                $keyInfo .= '<div class="alert alert-success mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-check-circle"></i> <strong>' . html_escape($diag['key_mensagem']) . '</strong></div>';
            } else {
                $keyInfo .= '<div class="alert alert-danger mtop10" style="margin-bottom:0; padding:8px 12px;"><i class="fa fa-exclamation-triangle"></i> <strong>' . html_escape($diag['key_mensagem']) . '</strong></div>';
            }
        }

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
                'info'          => $certInfo,
                'rows'          => 6,
            ],
            [
                'name'          => 'key_content',
                'type'          => 'textarea',
                'label'         => 'Chave Privada mTLS (.key)',
                'info'          => $keyInfo,
                'rows'          => 6,
            ],
            [
                'name'          => 'sandbox',
                'type'          => 'yes_no',
                'label'         => 'Ambiente de Testes (Sandbox / Homologação)',
                'default_value' => 0,
                'info'          => '<p class="text-muted">Ative para utilizar a URL de testes (https://matls-clients.stage.cora.com.br). Desative para Produção (https://matls-clients.api.cora.com.br).</p>',
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
                'name'             => 'webhook_url_display',
                'type'             => 'input',
                'label'            => 'URL do Webhook (Copie e cole no painel da Cora)',
                'default_value'    => $webhook_url,
                'field_attributes' => ['readonly' => 'readonly', 'onclick' => 'this.select();'],
                'info'             => '<p class="text-info"><i class="fa fa-info-circle"></i> Clique no campo acima para selecionar e copiar a URL oficial de conciliação para o portal Cora Developers.</p>',
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
        $is_sandbox = (bool)$this->getSetting('sandbox');
        $this->api_base_url = $is_sandbox ? self::STAGE_BASE_URL : self::PROD_BASE_URL;
        return $this->api_base_url;
    }

    /**
     * 3. Diagnóstico Automático do Certificado e Chave Privada
     * Analisa a estrutura e a validade temporal do certificado e valida o formato da chave RSA
     *
     * @return array
     */
    public function diagnosticar_certificados()
    {
        $certRaw = trim($this->getSetting('cert_content') ?? '');
        $keyRaw  = trim($this->getSetting('key_content') ?? '');

        $result = [
            'cert_valido'   => false,
            'cert_mensagem' => 'Certificado não configurado.',
            'key_valida'    => false,
            'key_mensagem'  => 'Chave privada não configurada.',
            'expira_em'     => null,
        ];

        // Análise do Certificado Público
        if (!empty($certRaw)) {
            $certContent = $certRaw;
            if (isset($this->ci->encryption) && strpos($certRaw, '-----BEGIN') === false) {
                $decrypted = $this->ci->encryption->decrypt($certRaw);
                if ($decrypted !== false && strpos($decrypted, '-----BEGIN') !== false) {
                    $certContent = $decrypted;
                }
            }

            $parsed = @openssl_x509_parse($certContent);
            if ($parsed && isset($parsed['validTo_time_t'])) {
                $validTo = date('d/m/Y H:i:s', $parsed['validTo_time_t']);
                $result['expira_em'] = $validTo;

                if (time() > $parsed['validTo_time_t']) {
                    $result['cert_valido'] = false;
                    $result['cert_mensagem'] = 'Certificado EXPIRADO em ' . $validTo . '! Emita um novo certificado no portal Cora.';
                } else {
                    $result['cert_valido'] = true;
                    $issuer = $parsed['issuer']['CN'] ?? ($parsed['issuer']['O'] ?? 'Banco Cora');
                    $result['cert_mensagem'] = 'Certificado válido até: ' . $validTo . ' (Emissor: ' . $issuer . ')';
                }
            } else {
                $result['cert_valido'] = false;
                $result['cert_mensagem'] = 'Formato de certificado inválido. Certifique-se de incluir as tags -----BEGIN CERTIFICATE----- e -----END CERTIFICATE-----.';
            }
        }

        // Análise da Chave Privada
        if (!empty($keyRaw)) {
            $keyContent = $keyRaw;
            if (isset($this->ci->encryption) && strpos($keyRaw, '-----BEGIN') === false) {
                $decrypted = $this->ci->encryption->decrypt($keyRaw);
                if ($decrypted !== false && strpos($decrypted, '-----BEGIN') !== false) {
                    $keyContent = $decrypted;
                }
            }

            $hasHeader = (strpos($keyContent, 'BEGIN RSA PRIVATE KEY') !== false || strpos($keyContent, 'BEGIN PRIVATE KEY') !== false);
            $pkey = @openssl_pkey_get_private($keyContent);

            if ($hasHeader && $pkey !== false) {
                $result['key_valida'] = true;
                $result['key_mensagem'] = 'Chave Privada RSA válida e compatível com OpenSSL.';
            } else {
                $result['key_valida'] = false;
                $result['key_mensagem'] = 'Formato de chave privada incorreto. A chave deve conter -----BEGIN RSA PRIVATE KEY----- ou -----BEGIN PRIVATE KEY-----.';
            }
        }

        return $result;
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
     * 3. Otimização de I/O na Gravação dos Certificados
     * Escreve os arquivos apenas se não existirem ou se o conteúdo no banco tiver mudado
     * (verificado via md5_file vs md5 do conteúdo), evitando escrita desnecessária a cada requisição.
     *
     * @return array Caminhos dos certificados ['cert' => $cert_file, 'key' => $key_file]
     * @throws Exception Caso os campos estejam vazios
     */
    public function get_cert_paths()
    {
        $certsDir = $this->get_secure_certs_dir();
        $certFile = $certsDir . DIRECTORY_SEPARATOR . 'certificate.pem';
        $keyFile  = $certsDir . DIRECTORY_SEPARATOR . 'private_key.key';

        $certContent = trim($this->getSetting('cert_content') ?? '');
        $keyContent  = trim($this->getSetting('key_content') ?? '');

        if (empty($certContent) || empty($keyContent)) {
            throw new Exception('Certificado mTLS (.pem) ou Chave Privada (.key) não configurados nas opções do gateway Pix Cora.');
        }

        // Suporte à decriptação caso o Perfex tenha salvo encriptado
        if (isset($this->ci->encryption)) {
            if (strpos($certContent, '-----BEGIN') === false) {
                $decryptedCert = $this->ci->encryption->decrypt($certContent);
                if ($decryptedCert !== false && strpos($decryptedCert, '-----BEGIN') !== false) {
                    $certContent = $decryptedCert;
                }
            }
            if (strpos($keyContent, '-----BEGIN') === false) {
                $decryptedKey = $this->ci->encryption->decrypt($keyContent);
                if ($decryptedKey !== false && strpos($decryptedKey, '-----BEGIN') !== false) {
                    $keyContent = $decryptedKey;
                }
            }
        }

        // Escreve o certificado apenas se não existir ou se o hash md5 mudou
        if (!file_exists($certFile) || md5_file($certFile) !== md5($certContent)) {
            @file_put_contents($certFile, $certContent);
            @chmod($certFile, 0600);
        }

        // Escreve a chave privada apenas se não existir ou se o hash md5 mudou
        if (!file_exists($keyFile) || md5_file($keyFile) !== md5($keyContent)) {
            @file_put_contents($keyFile, $keyContent);
            @chmod($keyFile, 0600);
        }

        return ['cert' => $certFile, 'key' => $keyFile];
    }

    /**
     * Alias de compatibilidade para get_cert_paths()
     */
    public function sync_certificates()
    {
        return $this->get_cert_paths();
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

        $certs = $this->get_cert_paths();
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
     * Alias de get_access_token() para compatibilidade
     *
     * @return string
     * @throws Exception
     */
    public function get_token()
    {
        return $this->get_access_token();
    }

    /**
     * 2. Dupla Checagem Ativa Anti-Fraude (GET /v1/cob/{txid})
     * Consulta diretamente a API Cora autenticada com certificados mTLS
     * para comprovar o status real da cobrança antes de efetivar qualquer baixa.
     *
     * @param string $txid Identificador da cobrança Pix
     * @return array|false Dados da cobrança ou false em caso de falha/rejeição
     */
    public function consultar_cobranca($txid)
    {
        try {
            $token = $this->get_access_token();
            if (!$token) {
                return false;
            }

            $paths = $this->get_cert_paths();
            $ch = curl_init($this->get_base_url() . '/v1/cob/' . urlencode($txid));

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET        => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_SSLCERT        => $paths['cert'],
                CURLOPT_SSLKEY         => $paths['key'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 15,
            ]);

            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                log_activity('Anti-Fraude Cora: Falha cURL ao consultar txid ' . $txid . ': ' . $curlError);
                return false;
            }

            return ($httpCode === 200) ? json_decode($res, true) : false;
        } catch (Exception $e) {
            log_activity('Anti-Fraude Cora: Exceção ao consultar txid ' . $txid . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Alias para consultar_cobranca($txid)
     */
    public function get_charge($txid)
    {
        return $this->consultar_cobranca($txid);
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

        // Validação Fiscal: Cliente sem Documento
        $doc = preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            throw new Exception('O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para emitir o Pix.');
        }

        $token = $this->get_access_token();
        $certs = $this->get_cert_paths();

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

        if (strlen($doc) === 14) {
            $payload['devedor']['cnpj'] = $doc;
        } else {
            $payload['devedor']['cpf'] = $doc;
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
        $amount  = (float)$data['amount'];

        // 4. Bloqueio de Moeda Estrangeira: O Pix opera exclusivamente em BRL
        if ($invoice->currency_name !== 'BRL') {
            set_alert('warning', 'O Pix está disponível apenas para faturas emitidas em BRL (R$).');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        // 4. Validação Fiscal: Cliente sem Documento (CPF 11 ou CNPJ 14)
        $doc = preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            set_alert('danger', 'O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para emitir o Pix.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
            return;
        }

        try {
            // Verifica se já existe uma transação Pix ATIVA recente para o mesmo valor
            $this->ci->db->where('invoice_id', $invoice->id);
            $this->ci->db->where('status', 'ATIVA');
            $this->ci->db->where('amount', $amount);
            $this->ci->db->order_by('id', 'DESC');
            $existing = $this->ci->db->get(db_prefix() . 'pix_cora_transactions')->row();

            if ($existing) {
                $createdAt = strtotime($existing->created_at);
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
