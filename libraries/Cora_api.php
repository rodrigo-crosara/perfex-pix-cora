<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cora_api
 * 
 * Biblioteca central e unificada de comunicação mTLS e API REST com o Banco Cora.
 * Utilizada simultaneamente pelos gateways Cora Pix e Cora Boleto Híbrido no Perfex CRM.
 * 
 * Funcionalidades:
 * - Gerenciamento e sincronização atômica de certificados mTLS com verificação MD5.
 * - Suporte automático a Sandbox (stage.cora.com.br) e Produção (api.cora.com.br).
 * - Autenticação OAuth2 Client Credentials com cache de token.
 * - Emissão de Pix Imediato Bacen (PUT /v1/cob/{txid}) com valor decimal em string.
 * - Emissão de Boleto Bancário Híbrido com Pix (POST /v2/invoices), valor em centavos (int),
 *   documento aninhado e telefone internacional higienizado para WhatsApp.
 * - Consulta ativa anti-fraude para Pix e Boletos (dupla checagem mTLS).
 * - Compartilhamento inteligente de credenciais em cascata entre gateways.
 * - Diagnóstico em tempo real de certificados OpenSSL.
 */
class Cora_api
{
    const PROD_BASE_URL  = 'https://matls-clients.api.cora.com.br';
    const STAGE_BASE_URL = 'https://matls-clients.stage.cora.com.br';

    /**
     * Instância do CodeIgniter
     * @var object
     */
    protected $ci;

    /**
     * Token de acesso em cache de memória para o ciclo atual
     * @var string|null
     */
    private static $memory_token = null;

    /**
     * Timestamp de expiração do token em memória
     * @var int|null
     */
    private static $memory_token_expires = null;

    public function __construct()
    {
        $this->ci = &get_instance();
    }

    /**
     * 4. Compartilhamento Inteligente de Credenciais mTLS
     * Recupera a credencial em cascata com suporte a prefixos 'payment_gateway_' e 'paymentmethod_'
     * Se vazio no Pix, busca automaticamente no Boleto (ou vice-versa), eliminando digitação duplicada.
     *
     * @param string $key Chave da configuração (ex: 'client_id', 'sandbox', 'cert_content', 'key_content')
     * @param string $preferredGateway 'cora_pix' ou 'cora_boleto'
     * @return string
     */
    public function get_credential($key, $preferredGateway = 'cora_pix')
    {
        $altGateway = ($preferredGateway === 'cora_pix') ? 'cora_boleto' : 'cora_pix';
        $prefixes   = ['payment_gateway_', 'paymentmethod_'];

        // 1. Tenta obter no gateway preferencial
        foreach ($prefixes as $pfx) {
            $val = get_option($pfx . $preferredGateway . '_' . $key);
            if (!empty($val)) {
                return trim($val);
            }
        }

        // 2. Fallback inteligente: Busca na aba do outro gateway
        foreach ($prefixes as $pfx) {
            $val = get_option($pfx . $altGateway . '_' . $key);
            if (!empty($val)) {
                return trim($val);
            }
        }

        // 3. Fallback para opções globais do módulo
        $valGlobal = get_option('cora_payments_' . $key);
        if (!empty($valGlobal)) {
            return trim($valGlobal);
        }

        return '';
    }

    /**
     * Alias de compatibilidade para get_credential
     *
     * @param string $key
     * @param string $preferredGateway
     * @return string
     */
    public function get_setting($key, $preferredGateway = 'cora_pix')
    {
        return $this->get_credential($key, $preferredGateway);
    }

    /**
     * Retorna a URL base de acordo com o ambiente configurado (Sandbox vs Produção)
     *
     * @param string $gateway
     * @return string
     */
    public function get_base_url($gateway = 'cora_pix')
    {
        $is_sandbox = (bool)$this->get_credential('sandbox', $gateway);
        return $is_sandbox ? self::STAGE_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * Retorna o diretório seguro de certificados mTLS do módulo
     *
     * @return string
     */
    public function get_certs_dir()
    {
        $certsDir = module_dir_path('cora_payments', 'certs');
        if (!is_dir($certsDir)) {
            @mkdir($certsDir, 0700, true);
            @file_put_contents($certsDir . DIRECTORY_SEPARATOR . '.htaccess', "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n");
            @file_put_contents($certsDir . DIRECTORY_SEPARATOR . 'index.html', '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>');
            @file_put_contents($certsDir . DIRECTORY_SEPARATOR . 'index.php', "<?php\ndefined('BASEPATH') or exit('No direct script access allowed');\nheader('HTTP/1.1 403 Forbidden');\nexit('Access denied.');\n");
        }
        return rtrim($certsDir, '/\\');
    }

    /**
     * Leitura e sincronização inteligente dos certificados mTLS (.pem e .key).
     * Utiliza verificação de hash MD5 para evitar I/O desnecessário no disco.
     *
     * @param string $gateway
     * @return array ['cert' => caminho_cert, 'key' => caminho_key]
     * @throws Exception
     */
    public function get_cert_paths($gateway = 'cora_pix')
    {
        $certsDir = $this->get_certs_dir();
        $certFile = $certsDir . DIRECTORY_SEPARATOR . 'cora_cert.pem';
        $keyFile  = $certsDir . DIRECTORY_SEPARATOR . 'cora_key.key';

        $certContent = trim($this->get_credential('cert_content', $gateway));
        $keyContent  = trim($this->get_credential('key_content', $gateway));

        // Suporte à descriptografia caso Perfex tenha armazenado encriptado
        if (isset($this->ci->encryption)) {
            if (!empty($certContent) && strpos($certContent, '-----BEGIN') === false) {
                $decrypted = $this->ci->encryption->decrypt($certContent);
                if ($decrypted !== false && strpos($decrypted, '-----BEGIN') !== false) {
                    $certContent = $decrypted;
                }
            }
            if (!empty($keyContent) && strpos($keyContent, '-----BEGIN') === false) {
                $decryptedKey = $this->ci->encryption->decrypt($keyContent);
                if ($decryptedKey !== false && strpos($decryptedKey, '-----BEGIN') !== false) {
                    $keyContent = $decryptedKey;
                }
            }
        }

        // Se ambos os conteúdos de banco estiverem vazios, verifica se existem arquivos físicos
        if (empty($certContent) || empty($keyContent)) {
            if (file_exists($certFile) && file_exists($keyFile)) {
                return ['cert' => $certFile, 'key' => $keyFile];
            }
            throw new Exception('Certificado mTLS (.pem) ou Chave Privada (.key) não configurados nas opções do gateway Cora Payments.');
        }

        // Sincronização inteligente com verificação de hash MD5 (evita gravação em disco se idêntico)
        if (!file_exists($certFile) || md5_file($certFile) !== md5($certContent)) {
            @file_put_contents($certFile, $certContent);
            @chmod($certFile, 0600);
        }

        if (!file_exists($keyFile) || md5_file($keyFile) !== md5($keyContent)) {
            @file_put_contents($keyFile, $keyContent);
            @chmod($keyFile, 0600);
        }

        return ['cert' => $certFile, 'key' => $keyFile];
    }

    /**
     * Diagnóstico Automático do Certificado e Chave Privada
     * Analisa validade, emissor e formato da chave OpenSSL.
     *
     * @param string $gateway
     * @return array
     */
    public function diagnosticar_certificados($gateway = 'cora_pix')
    {
        $certRaw = trim($this->get_credential('cert_content', $gateway));
        $keyRaw  = trim($this->get_credential('key_content', $gateway));

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
                $dec = $this->ci->encryption->decrypt($certRaw);
                if ($dec !== false && strpos($dec, '-----BEGIN') !== false) {
                    $certContent = $dec;
                }
            }

            $parsed = @openssl_x509_parse($certContent);
            if ($parsed && isset($parsed['validTo_time_t'])) {
                $validTo = date('d/m/Y H:i:s', $parsed['validTo_time_t']);
                $result['expira_em'] = $validTo;

                if (time() > $parsed['validTo_time_t']) {
                    $result['cert_valido']   = false;
                    $result['cert_mensagem'] = 'Certificado EXPIRADO em ' . $validTo . '! Gere um novo certificado no portal Cora.';
                } else {
                    $result['cert_valido']   = true;
                    $issuer = $parsed['issuer']['CN'] ?? ($parsed['issuer']['O'] ?? 'Banco Cora');
                    $result['cert_mensagem'] = 'Certificado válido até: ' . $validTo . ' (Emissor: ' . $issuer . ')';
                }
            } else {
                $result['cert_valido']   = false;
                $result['cert_mensagem'] = 'Formato de certificado inválido. Certifique-se de incluir as tags -----BEGIN CERTIFICATE----- e -----END CERTIFICATE-----.';
            }
        }

        // Análise da Chave Privada
        if (!empty($keyRaw)) {
            $keyContent = $keyRaw;
            if (isset($this->ci->encryption) && strpos($keyRaw, '-----BEGIN') === false) {
                $decKey = $this->ci->encryption->decrypt($keyRaw);
                if ($decKey !== false && strpos($decKey, '-----BEGIN') !== false) {
                    $keyContent = $decKey;
                }
            }

            $hasHeader = (strpos($keyContent, 'BEGIN RSA PRIVATE KEY') !== false || strpos($keyContent, 'BEGIN PRIVATE KEY') !== false);
            $pkey = @openssl_pkey_get_private($keyContent);

            if ($hasHeader && $pkey !== false) {
                $result['key_valida']   = true;
                $result['key_mensagem'] = 'Chave Privada RSA válida e compatível com OpenSSL.';
            } else {
                $result['key_valida']   = false;
                $result['key_mensagem'] = 'Formato de chave incorreto. Certifique-se de incluir as tags -----BEGIN RSA PRIVATE KEY----- ou -----BEGIN PRIVATE KEY-----.';
            }
        }

        return $result;
    }

    /**
     * Autenticação OAuth2 Client Credentials com cache de token
     *
     * @param string $gateway
     * @param bool $forceRefresh
     * @return string Bearer Token
     * @throws Exception
     */
    public function get_token($gateway = 'cora_pix', $forceRefresh = false)
    {
        $now = time();

        // 1. Verifica cache em memória
        if (!$forceRefresh && !empty(self::$memory_token) && self::$memory_token_expires > ($now + 60)) {
            return self::$memory_token;
        }

        // 2. Verifica cache em banco de dados
        $cacheKey = 'cora_oauth_token_' . md5($this->get_base_url($gateway) . $this->get_credential('client_id', $gateway));
        if (!$forceRefresh) {
            $cached = get_option($cacheKey);
            if (!empty($cached)) {
                $tokenData = json_decode($cached, true);
                if (is_array($tokenData) && isset($tokenData['token']) && isset($tokenData['expires_at']) && $tokenData['expires_at'] > ($now + 60)) {
                    self::$memory_token = $tokenData['token'];
                    self::$memory_token_expires = $tokenData['expires_at'];
                    return self::$memory_token;
                }
            }
        }

        // 3. Solicita novo token via mTLS
        $clientId = trim($this->get_credential('client_id', $gateway));
        if (empty($clientId)) {
            throw new Exception('Client ID da Cora não configurado. Acesse as configurações do módulo para informar.');
        }

        $certs    = $this->get_cert_paths($gateway);
        $tokenUrl = $this->get_base_url($gateway) . '/token';

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

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_activity('Erro cURL mTLS Cora Auth: ' . $curlError);
            throw new Exception('Erro de conexão mTLS com Banco Cora: ' . $curlError);
        }

        $json = json_decode($response, true);

        if ($httpCode !== 200 || !isset($json['access_token'])) {
            $msg = $json['message'] ?? ($json['error_description'] ?? 'Falha na resposta do servidor de autenticação Cora');
            log_activity('Falha no token mTLS Cora (HTTP ' . $httpCode . '): ' . $response);
            throw new Exception('Erro de autenticação mTLS no Banco Cora: ' . $msg);
        }

        $accessToken = $json['access_token'];
        $expiresIn   = isset($json['expires_in']) ? (int)$json['expires_in'] : 3600;
        $expiresAt   = $now + $expiresIn;

        self::$memory_token         = $accessToken;
        self::$memory_token_expires = $expiresAt;

        // Salva em cache no banco
        update_option($cacheKey, json_encode([
            'token'      => $accessToken,
            'expires_at' => $expiresAt,
        ]));

        return $accessToken;
    }

    /**
     * 1. Emissão de Pix Imediato (PUT /v1/cob/{txid})
     * Padrão Bacen: Valor enviado como string decimal com ponto ("150.50")
     * e chaves diretas ['devedor']['cpf'] ou ['devedor']['cnpj'].
     *
     * @param object $invoice Objeto fatura Perfex
     * @param float $amount Valor da transação
     * @param string|null $txid TxID opcional (se nulo, gerado automaticamente)
     * @return array ['txid' => ..., 'pix_copia_cola' => ...]
     * @throws Exception
     */
    public function criar_pix($invoice, $amount, $txid = null)
    {
        $chavePix = trim($this->get_credential('chave_pix', 'cora_pix'));
        if (empty($chavePix)) {
            throw new Exception('Chave Pix não configurada nas configurações do gateway.');
        }

        // Validação Fiscal: Documento (CPF 11 ou CNPJ 14)
        $doc = preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            throw new Exception('O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para emitir o Pix.');
        }

        $token = $this->get_token('cora_pix');
        $certs = $this->get_cert_paths('cora_pix');

        if (empty($txid)) {
            // TxID alfanumérico único entre 26 e 35 caracteres conforme padrão Bacen
            $txid = 'CORA' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
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

        $expMinutes = (int)($this->get_credential('expiration_minutes', 'cora_pix') ?: 1440);
        $expSeconds = $expMinutes * 60;

        // Padrão Bacen Pix: valor como string decimal com ponto: "150.50"
        $payload = [
            'calendario' => [
                'expiracao' => $expSeconds,
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

        // Padrão Bacen: chaves diretas ['devedor']['cpf'] ou ['devedor']['cnpj']
        if (strlen($doc) > 11) {
            $payload['devedor']['cnpj'] = $doc;
        } else {
            $payload['devedor']['cpf'] = $doc;
        }

        $url = $this->get_base_url('cora_pix') . '/v1/cob/' . $txid;
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

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_activity('Erro cURL Pix Cora (txid: ' . $txid . '): ' . $curlError);
            throw new Exception('Erro de conexão ao criar cobrança Pix na Cora: ' . $curlError);
        }

        $resJson = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            $errorDetail = $resJson['mensagem'] ?? ($resJson['message'] ?? ($resJson['detail'] ?? 'Erro desconhecido retornado pela API Cora'));
            log_activity('Erro API Cora Cobrança Pix (HTTP ' . $httpCode . '): ' . $response);
            throw new Exception('Banco Cora rejeitou a cobrança Pix: ' . $errorDetail);
        }

        $pixCopiaECola = $resJson['pixCopiaECola'] ?? ($resJson['qrcode'] ?? ($resJson['emv'] ?? ''));
        if (empty($pixCopiaECola) && isset($resJson['loc']['id'])) {
            $pixCopiaECola = $resJson['textoImagemQRcode'] ?? '';
        }

        if (empty($pixCopiaECola)) {
            throw new Exception('O Banco Cora gerou a cobrança, mas não retornou o código Pix Copia e Cola.');
        }

        return [
            'txid'           => $txid,
            'pix_copia_cola' => $pixCopiaECola,
            'amount'         => (float)$amount,
            'response'       => $resJson,
        ];
    }

    /**
     * 1 & 2. Emissão de Boleto Bancário Híbrido com Pix (POST /v2/invoices)
     * 
     * Padrão Cora v2:
     * - Valor em centavos como número inteiro: (int) round($amount * 100)
     * - Documento aninhado: ['identity' => $doc, 'type' => 'CPF'|'CNPJ']
     * - Telefone higienizado com DDI 55 nacional para acionamento da régua de WhatsApp da Cora:
     *   'phone' => '5561999998888'
     *
     * @param object $invoice Objeto fatura Perfex
     * @param float $amount Valor da cobrança
     * @param array $customOptions Opções adicionais (multa, juros, dias_cancelamento)
     * @return array
     * @throws Exception
     */
    public function criar_boleto($invoice, $amount, $customOptions = [])
    {
        // 1. Validação Fiscal do Cliente: Documento (CPF 11 ou CNPJ 14)
        $doc = preg_replace('/\D/', '', $invoice->client->vat ?? '');
        if (empty($doc) || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
            throw new Exception('O cadastro do cliente precisa conter um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para emitir o Boleto Bancário.');
        }

        $token = $this->get_token('cora_boleto');
        $certs = $this->get_cert_paths('cora_boleto');

        // Nome do pagador
        $clientName = '';
        if (isset($invoice->client->company) && !empty($invoice->client->company)) {
            $clientName = trim($invoice->client->company);
        } elseif (isset($invoice->clientid)) {
            $clientName = get_company_name($invoice->clientid);
        }
        if (empty($clientName)) {
            $clientName = 'Cliente Fatura #' . $invoice->id;
        }

        // Email do cliente
        $clientEmail = '';
        if (isset($invoice->client->email) && !empty($invoice->client->email)) {
            $clientEmail = trim($invoice->client->email);
        } else {
            // Tenta obter email do contato principal
            if (isset($invoice->clientid)) {
                $primaryContact = $this->ci->clients_model->get_contact(get_primary_contact_user_id($invoice->clientid));
                if ($primaryContact && !empty($primaryContact->email)) {
                    $clientEmail = trim($primaryContact->email);
                }
            }
        }
        if (empty($clientEmail)) {
            $clientEmail = 'financeiro@' . (!empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'cora.com.br');
        }

        // 2. Telefone higienizado para a Régua do WhatsApp da Cora
        $phone = preg_replace('/\D/', '', $invoice->client->phonenumber ?? '');
        if (empty($phone) && isset($primaryContact) && !empty($primaryContact->phonenumber)) {
            $phone = preg_replace('/\D/', '', $primaryContact->phonenumber);
        }
        // Se não tiver DDI 55, inclui automaticamente
        if (!empty($phone) && strlen($phone) >= 10 && substr($phone, 0, 2) !== '55') {
            $phone = '55' . $phone;
        }

        // 1. Objeto Customer: documento aninhado com identity e type
        $customerObj = [
            'name'     => mb_substr($clientName, 0, 150, 'UTF-8'),
            'email'    => $clientEmail,
            'document' => [
                'identity' => $doc,
                'type'     => (strlen($doc) > 11) ? 'CNPJ' : 'CPF',
            ],
            'phone'    => !empty($phone) ? $phone : null,
            'address'  => $this->montar_endereco_cliente($invoice),
        ];

        // 2. Faturas Já Vencidas no Perfex (Tolerância Anti-Erro 422 na Cora)
        $hoje = date('Y-m-d');
        $data_vencimento = !empty($invoice->duedate) ? $invoice->duedate : $hoje;

        // Se a fatura do Perfex estiver vencida, coloca vencimento para o próprio dia (ou D+1)
        if (strtotime($data_vencimento) < strtotime($hoje)) {
            $data_vencimento = $hoje;
        }

        // Condições de Pagamento (Multa e Juros)
        $paymentTerms = [
            'due_date' => $data_vencimento,
        ];

        // Multa por atraso (%) - Validação com fallback 0 para não enviar null
        $rawMulta = isset($customOptions['multa']) && $customOptions['multa'] !== '' 
            ? $customOptions['multa'] 
            : $this->get_credential('multa_percentual', 'cora_boleto');
        $multaPercent = is_numeric($rawMulta) ? (float)$rawMulta : 0.0;

        if ($multaPercent > 0) {
            $paymentTerms['fine'] = [
                'rate' => round($multaPercent, 2),
            ];
        }

        // Juros de mora ao mês (%) - Validação com fallback 0 para não enviar null
        $rawJuros = isset($customOptions['juros']) && $customOptions['juros'] !== '' 
            ? $customOptions['juros'] 
            : $this->get_credential('juros_mensal_percentual', 'cora_boleto');
        $jurosPercent = is_numeric($rawJuros) ? (float)$rawJuros : 0.0;

        if ($jurosPercent > 0) {
            $paymentTerms['interest'] = [
                'rate' => round($jurosPercent, 2),
            ];
        }

        // TxID único para identificação interna da cobrança
        $txid = 'BOL' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

        // 1. Valor em Centavos como número inteiro (Cora v2 exige int em centavos)
        $amountInCents = (int) round($amount * 100);
        $serviceName   = 'Fatura #' . format_invoice_number($invoice->id);

        $payload = [
            'code'            => $txid,
            'customer'        => $customerObj,
            'services'        => [
                [
                    'name'   => mb_substr($serviceName, 0, 100, 'UTF-8'),
                    'amount' => $amountInCents,
                ],
            ],
            'payment_terms'   => $paymentTerms,
            'payment_options' => [
                'BANK_SLIP',
                'PIX',
            ],
        ];

        $url = $this->get_base_url('cora_boleto') . '/v2/invoices';
        $jsonPayload = json_encode($payload);
        $idempotencyKey = $this->generate_uuid();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
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
                'Idempotency-Key: ' . $idempotencyKey,
            ],
            CURLOPT_TIMEOUT        => 35,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_activity('Erro cURL Boleto Cora (Fatura #' . $invoice->id . '): ' . $curlError);
            throw new Exception('Erro de conexão ao emitir boleto no Banco Cora: ' . $curlError);
        }

        $resJson = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            $msg = $resJson['message'] ?? ($resJson['detail'] ?? ($resJson['errors'][0]['message'] ?? 'Erro desconhecido na emissão de boleto Cora'));
            log_activity('Erro API Cora Boleto (HTTP ' . $httpCode . '): ' . $response);
            throw new Exception('Banco Cora rejeitou a emissão do boleto: ' . $msg);
        }

        // Extrai dados retornados pela Cora
        $coraInvoiceId = $resJson['id'] ?? '';
        $barcode       = $resJson['bank_slip']['barcode'] ?? ($resJson['bank_slip']['digitable_line'] ?? '');
        $digitable     = $resJson['bank_slip']['digitable_line'] ?? $barcode;
        $pdfUrl        = $resJson['bank_slip']['url'] ?? '';

        // Pix Copia e Cola embutido no boleto híbrido
        $pixCopiaECola = '';
        if (isset($resJson['payment_options']['pix']['emv'])) {
            $pixCopiaECola = $resJson['payment_options']['pix']['emv'];
        } elseif (isset($resJson['payment_options']['pix']['qrcode'])) {
            $pixCopiaECola = $resJson['payment_options']['pix']['qrcode'];
        }

        if (empty($pdfUrl) && empty($barcode)) {
            throw new Exception('O Banco Cora processou a requisição, mas não retornou os dados do boleto bancário.');
        }

        return [
            'txid'            => $txid,
            'cora_invoice_id' => $coraInvoiceId,
            'barcode'         => $barcode,
            'digitable_line'  => $digitable,
            'pdf_url'         => $pdfUrl,
            'pix_copia_cola'  => $pixCopiaECola,
            'amount'          => (float)$amount,
            'response'        => $resJson,
        ];
    }

    /**
     * Dupla Checagem Ativa Anti-Fraude: Consulta cobrança Pix (GET /v1/cob/{txid})
     *
     * @param string $txid Identificador da cobrança Pix
     * @return array|false
     */
    public function consultar_cobranca($txid)
    {
        try {
            $token = $this->get_token('cora_pix');
            if (!$token) {
                return false;
            }

            $paths = $this->get_cert_paths('cora_pix');
            $url   = $this->get_base_url('cora_pix') . '/v1/cob/' . urlencode($txid);

            $ch = curl_init($url);
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
                CURLOPT_TIMEOUT        => 20,
            ]);

            $res       = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                log_activity('Anti-Fraude Cora Pix: Falha cURL ao consultar txid ' . $txid . ': ' . $curlError);
                return false;
            }

            return ($httpCode === 200) ? json_decode($res, true) : false;
        } catch (Exception $e) {
            log_activity('Anti-Fraude Cora Pix: Exceção ao consultar txid ' . $txid . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Dupla Checagem Ativa Anti-Fraude: Consulta Fatura/Boleto Cora (GET /v2/invoices/{id})
     *
     * @param string $coraInvoiceId ID da fatura na Cora (ex: inv_...)
     * @return array|false
     */
    public function consultar_fatura($coraInvoiceId)
    {
        try {
            $token = $this->get_token('cora_boleto');
            if (!$token) {
                return false;
            }

            $paths = $this->get_cert_paths('cora_boleto');
            $url   = $this->get_base_url('cora_boleto') . '/v2/invoices/' . urlencode($coraInvoiceId);

            $ch = curl_init($url);
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
                CURLOPT_TIMEOUT        => 20,
            ]);

            $res       = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                log_activity('Anti-Fraude Cora Boleto: Falha cURL ao consultar fatura ' . $coraInvoiceId . ': ' . $curlError);
                return false;
            }

            return ($httpCode === 200) ? json_decode($res, true) : false;
        } catch (Exception $e) {
            log_activity('Anti-Fraude Cora Boleto: Exceção ao consultar fatura ' . $coraInvoiceId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cancelamento de Boleto no Banco Cora via DELETE /v2/invoices/{id}
     *
     * @param string $coraInvoiceId ID da fatura na Cora (inv_...)
     * @return bool
     */
    public function cancelar_cobranca($coraInvoiceId)
    {
        try {
            $token = $this->get_token('cora_boleto');
            if (!$token) {
                return false;
            }

            $certs = $this->get_cert_paths('cora_boleto');
            $url   = $this->get_base_url('cora_boleto') . '/v2/invoices/' . urlencode($coraInvoiceId);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSLCERT        => $certs['cert'],
                CURLOPT_SSLKEY         => $certs['key'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => 20,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                log_activity('Erro cURL ao cancelar boleto Cora ' . $coraInvoiceId . ': ' . $curlError);
                return false;
            }

            return ($httpCode === 200 || $httpCode === 204);
        } catch (Exception $e) {
            log_activity('Exceção ao cancelar boleto Cora ' . $coraInvoiceId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 3. Endereço Completo do Cliente para Registro do Boleto (Regulamentação Bacen / CIP)
     * Garante o preenchimento de todos os campos obrigatórios para evitar erro 400/422 na emissão.
     *
     * @param object $invoice
     * @return array
     */
    protected function montar_endereco_cliente($invoice)
    {
        $street = !empty($invoice->billing_street) 
            ? $invoice->billing_street 
            : (!empty($invoice->client->address) ? $invoice->client->address : 'Nao informado');

        $city = !empty($invoice->billing_city) 
            ? $invoice->billing_city 
            : (!empty($invoice->client->city) ? $invoice->client->city : 'Brasilia');

        $stateRaw = !empty($invoice->billing_state) 
            ? $invoice->billing_state 
            : (!empty($invoice->client->state) ? $invoice->client->state : 'DF');
        $state = strtoupper(substr(trim($stateRaw), 0, 2));
        if (empty($state)) {
            $state = 'DF';
        }

        $zipRaw = !empty($invoice->billing_zip) 
            ? $invoice->billing_zip 
            : (!empty($invoice->client->zip) ? $invoice->client->zip : '70000000');
        $postCode = preg_replace('/\D/', '', $zipRaw);
        if (empty($postCode) || strlen($postCode) < 8) {
            $postCode = '70000000';
        }

        // Tenta separar número caso esteja no formato "Rua Nome, 123"
        $number = 'S/N';
        if (preg_match('/,\s*(\d+.*)$/', $street, $matches)) {
            $number = trim($matches[1]);
            $street = trim(preg_replace('/,\s*(\d+.*)$/', '', $street));
        }

        return [
            'street'    => mb_substr($street, 0, 100, 'UTF-8') ?: 'Nao informado',
            'number'    => mb_substr($number, 0, 20, 'UTF-8'),
            'district'  => 'Centro',
            'city'      => mb_substr($city, 0, 60, 'UTF-8'),
            'state'     => $state,
            'post_code' => $postCode,
        ];
    }

    /**
     * Gera um identificador UUID v4 para Idempotency-Key
     *
     * @return string
     */
    protected function generate_uuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
