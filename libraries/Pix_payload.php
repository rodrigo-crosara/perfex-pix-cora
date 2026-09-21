<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Pix_payload
 * 
 * Helper em PHP puro para geração de BR Code estático (EMVCo / Padrão Bacen)
 * para o Modo Pix Manual sem API do módulo Cora Payments.
 * 
 * Funcionalidades:
 * - Montagem de elementos TLV (Tag-Length-Value) no padrão oficial EMVCo.
 * - Sanitização rigorosa de caracteres (remoção de acentos e símbolos não permitidos), 100% compatível com PHP 8.1, 8.2, 8.3 e 8.4 (sem uso de utf8_encode).
 * - Truncamento estrito nos limites de tamanho do Bacen (Nome máx 25, Cidade máx 15, TxID máx 25).
 * - Formatação e validação de chaves Pix (CPF, CNPJ, Telefone com DDI +55, E-mail e Chave Aleatória).
 * - Cálculo do checksum CRC16-CCITT-FALSE (polinômio 0x1021, init 0xFFFF).
 * - Suporte nativo à renderização visual de QR Code em SVG sem dependências externas pesadas.
 */
class Pix_payload
{
    // Constantes dos campos EMVCo / BR Code (Banco Central do Brasil)
    const ID_PAYLOAD_FORMAT_INDICATOR          = '00';
    const ID_POINT_OF_INITIATION_METHOD        = '01';
    const ID_MERCHANT_ACCOUNT_INFORMATION      = '26';
    const ID_MERCHANT_ACCOUNT_INFORMATION_GUI  = '00';
    const ID_MERCHANT_ACCOUNT_INFORMATION_KEY  = '01';
    const ID_MERCHANT_ACCOUNT_INFORMATION_DESC = '02';
    const ID_MERCHANT_CATEGORY_CODE            = '52';
    const ID_TRANSACTION_CURRENCY              = '53';
    const ID_TRANSACTION_AMOUNT                = '54';
    const ID_COUNTRY_CODE                      = '58';
    const ID_MERCHANT_NAME                     = '59';
    const ID_MERCHANT_CITY                     = '60';
    const ID_ADDITIONAL_DATA_FIELD_TEMPLATE    = '62';
    const ID_ADDITIONAL_DATA_FIELD_TXID        = '05';
    const ID_CRC16                             = '63';

    /**
     * Formata um elemento TLV (Tag-Length-Value)
     *
     * @param string $id Identificador EMVCo de 2 dígitos
     * @param string $value Conteúdo do campo
     * @return string
     */
    public static function emv($id, $value)
    {
        $len = str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT);
        return $id . $len . $value;
    }

    /**
     * Remove acentos e caracteres não-ASCII de forma 100% compatível com PHP 8.2+
     * Não utiliza utf8_encode() ou utf8_decode() (descontinuadas no PHP 8.2 e removidas no PHP 8.4).
     *
     * @param string $string
     * @return string
     */
    public static function remove_accents($string)
    {
        $map = [
            'á'=>'a', 'à'=>'a', 'ã'=>'a', 'â'=>'a', 'ä'=>'a',
            'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
            'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
            'ó'=>'o', 'ò'=>'o', 'õ'=>'o', 'ô'=>'o', 'ö'=>'o',
            'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u',
            'ç'=>'c', 'ñ'=>'n',
            'Á'=>'A', 'À'=>'A', 'Ã'=>'A', 'Â'=>'A', 'Ä'=>'A',
            'É'=>'E', 'È'=>'E', 'Ê'=>'E', 'Ë'=>'E',
            'Í'=>'I', 'Ì'=>'I', 'Î'=>'I', 'Ï'=>'I',
            'Ó'=>'O', 'Ò'=>'O', 'Õ'=>'O', 'Ô'=>'O', 'Ö'=>'O',
            'Ú'=>'U', 'Ù'=>'U', 'Û'=>'U', 'Ü'=>'U',
            'Ç'=>'C', 'Ñ'=>'N',
            'º'=>'', 'ª'=>'', '°'=>'', '§'=>'',
        ];
        $clean = strtr((string)$string, $map);

        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
            if ($trans !== false) {
                $clean = $trans;
            }
        }

        return $clean;
    }

    /**
     * Sanitiza texto para os campos Merchant Name (máx 25) e Merchant City (máx 15)
     * Mantém apenas letras maiúsculas, números e espaços simples.
     * Trunca rigorosamente de acordo com os limites do Bacen para evitar rejeição no app do banco.
     *
     * @param string $text
     * @param int $maxLength
     * @param string $fallback
     * @return string
     */
    public static function sanitize_text($text, $maxLength = 25, $fallback = 'RECEBEDOR')
    {
        $clean = self::remove_accents((string)$text);
        $clean = preg_replace('/[^A-Za-z0-9 ]/', '', $clean);
        $clean = preg_replace('/\s+/', ' ', (string)$clean);
        $clean = strtoupper(trim((string)$clean));

        if (empty($clean)) {
            $clean = $fallback;
        }

        if (function_exists('mb_substr')) {
            $clean = mb_substr($clean, 0, $maxLength, 'UTF-8');
        } else {
            $clean = substr($clean, 0, $maxLength);
        }

        return substr($clean, 0, $maxLength);
    }

    /**
     * Detecta automaticamente o tipo da Chave Pix com base no formato
     *
     * @param string $key
     * @return string 'email', 'aleatoria', 'cnpj', 'cpf', 'phone'
     */
    public static function detect_key_type($key)
    {
        $key = trim((string)$key);
        if (strpos($key, '@') !== false) {
            return 'email';
        }
        // UUID v4 format (8-4-4-4-12)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return 'aleatoria';
        }
        $digits = preg_replace('/\D/', '', $key);
        if (strlen($digits) === 14) {
            return 'cnpj';
        }
        if (strpos($key, '+') === 0) {
            return 'phone';
        }
        if (strlen($digits) === 11) {
            // Se possui pontuação de CPF (ex: 123.456.789-00)
            if (strpos($key, '.') !== false || strpos($key, '-') !== false) {
                return 'cpf';
            }
            // Celular brasileiro com DDD (ex: 61986314955) possui 11 dígitos e o 3º dígito é 9
            if (substr($digits, 2, 1) === '9') {
                return 'phone';
            }
            return 'cpf';
        }
        if (strlen($digits) === 10) {
            return 'phone';
        }
        return 'cnpj';
    }

    /**
     * Higieniza e formata a chave Pix conforme a regulamentação do Bacen
     *
     * @param string $key Chave informada
     * @param string $type Tipo: 'auto', 'cpf', 'cnpj', 'phone'/'telefone', 'email', 'aleatoria'
     * @return string
     */
    public static function sanitize_key($key, $type = 'auto')
    {
        $key  = trim((string)$key);
        $type = strtolower(trim((string)$type));

        if (empty($type) || $type === 'auto') {
            $type = self::detect_key_type($key);
        }

        switch ($type) {
            case 'cpf':
            case 'cnpj':
                // Mantém apenas dígitos (11 dígitos para CPF, 14 dígitos para CNPJ)
                return preg_replace('/\D/', '', $key);

            case 'phone':
            case 'telefone':
                // Padrão internacional E.164: +55 seguido de DDD e 9 dígitos (ex: +5511999998888)
                $digits = preg_replace('/\D/', '', $key);
                if (strlen($digits) >= 10 && strpos($digits, '55') !== 0) {
                    $digits = '55' . $digits;
                }
                return '+' . $digits;

            case 'email':
                return strtolower(trim($key));

            case 'aleatoria':
            case 'evp':
            default:
                return trim($key);
        }
    }

    /**
     * Sanitiza o identificador da transação (txid) no Pix estático
     * Máximo de 25 caracteres alfanuméricos ([A-Za-z0-9]), sem espaços.
     * Caso vazio, o padrão Bacen determina utilizar '***'.
     *
     * @param string $txid
     * @return string
     */
    public static function sanitize_txid($txid)
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$txid);
        if (empty($clean)) {
            return '***';
        }

        if (function_exists('mb_substr')) {
            $clean = mb_substr($clean, 0, 25, 'UTF-8');
        }

        return substr($clean, 0, 25);
    }

    /**
     * Cálculo do Checksum CRC16-CCITT-FALSE (Padrão Bacen / EMVCo)
     * Polinômio: 0x1021, Valor inicial: 0xFFFF, Sem reflexão, XOR final: 0x0000.
     *
     * @param string $payload Payload Pix sem os 4 caracteres finais do CRC
     * @return string 4 caracteres hexadecimais em maiúsculas (ex: "E8D5")
     */
    public static function calculate_crc16($payload)
    {
        // O cálculo deve incidir sobre toda a cadeia acrescida de "6304"
        $payloadWithTag = $payload . self::ID_CRC16 . '04';

        $polinomio = 0x1021;
        $resultado = 0xFFFF;
        $length    = strlen($payloadWithTag);

        for ($offset = 0; $offset < $length; $offset++) {
            $resultado ^= (ord($payloadWithTag[$offset]) << 8);
            for ($bitwise = 0; $bitwise < 8; $bitwise++) {
                if (($resultado <<= 1) & 0x10000) {
                    $resultado ^= $polinomio;
                }
                $resultado &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($resultado), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Monta o código BR Code Pix completo (Copia e Cola)
     *
     * @param string $pixKey Chave Pix
     * @param string $keyType Tipo de chave ('cpf', 'cnpj', 'phone', 'email', 'aleatoria')
     * @param float|string $amount Valor da fatura
     * @param string $txid Identificador da transação (máx 25 caracteres alfanuméricos)
     * @param string $merchantName Nome do titular recebedor (máx 25 caracteres)
     * @param string $merchantCity Cidade do titular recebedor (máx 15 caracteres)
     * @param string $description Descrição opcional (máx 25 caracteres)
     * @return string Payload BR Code final pronto para leitura e cópia
     */
    public static function generate_payload(
        $pixKey,
        $keyType,
        $amount,
        $txid,
        $merchantName,
        $merchantCity,
        $description = ''
    ) {
        if (empty($keyType) || $keyType === 'auto') {
            $keyType = self::detect_key_type($pixKey);
        }
        $cleanKey  = self::sanitize_key($pixKey, $keyType);
        $cleanName = self::sanitize_text($merchantName, 25, 'RECEBEDOR');
        $cleanCity = self::sanitize_text($merchantCity, 15, 'SAO PAULO');
        $cleanTxid = self::sanitize_txid($txid);

        // 00: Payload Format Indicator (versão 01 do padrão EMVCo)
        $payload = self::emv(self::ID_PAYLOAD_FORMAT_INDICATOR, '01');

        // 01: Point of Initiation Method (11 = QR Code estático reutilizável com valor)
        $payload .= self::emv(self::ID_POINT_OF_INITIATION_METHOD, '11');

        // 26: Merchant Account Information (GUI br.gov.bcb.pix + Chave + Descrição opcional)
        $accountInfo = self::emv(self::ID_MERCHANT_ACCOUNT_INFORMATION_GUI, 'br.gov.bcb.pix');
        $accountInfo .= self::emv(self::ID_MERCHANT_ACCOUNT_INFORMATION_KEY, $cleanKey);
        if (!empty($description)) {
            $cleanDesc = substr(self::sanitize_text($description, 25, ''), 0, 25);
            if (!empty($cleanDesc)) {
                $accountInfo .= self::emv(self::ID_MERCHANT_ACCOUNT_INFORMATION_DESC, $cleanDesc);
            }
        }
        $payload .= self::emv(self::ID_MERCHANT_ACCOUNT_INFORMATION, $accountInfo);

        // 52: Merchant Category Code (0000 = Padrão Bacen)
        $payload .= self::emv(self::ID_MERCHANT_CATEGORY_CODE, '0000');

        // 53: Transaction Currency (986 = Real Brasileiro / BRL ISO 4217)
        $payload .= self::emv(self::ID_TRANSACTION_CURRENCY, '986');

        // 54: Transaction Amount (Valor decimal formatado com ponto: "150.00")
        if ((float)$amount > 0) {
            $payload .= self::emv(self::ID_TRANSACTION_AMOUNT, number_format((float)$amount, 2, '.', ''));
        }

        // 58: Country Code (BR)
        $payload .= self::emv(self::ID_COUNTRY_CODE, 'BR');

        // 59: Merchant Name (Nome do titular em maiúsculas sem acentos, máx 25 caracteres)
        $payload .= self::emv(self::ID_MERCHANT_NAME, $cleanName);

        // 60: Merchant City (Cidade em maiúsculas sem acentos, máx 15 caracteres)
        $payload .= self::emv(self::ID_MERCHANT_CITY, $cleanCity);

        // 62: Additional Data Field Template (TxID alfanumérico)
        $additionalData = self::emv(self::ID_ADDITIONAL_DATA_FIELD_TXID, $cleanTxid);
        $payload .= self::emv(self::ID_ADDITIONAL_DATA_FIELD_TEMPLATE, $additionalData);

        // 63: CRC16 Checksum
        $crc = self::calculate_crc16($payload);

        return $payload . self::ID_CRC16 . '04' . $crc;
    }

    /**
     * Renderiza o QR Code em SVG caso a biblioteca nativa do Perfex CRM (TCPDF2DBarcode) esteja disponível
     *
     * @param string $payload Código Copia e Cola
     * @param int $size Tamanho em pixels/módulos
     * @return string|null Código SVG ou null se biblioteca não estiver presente
     */
    public static function render_svg($payload, $size = 6)
    {
        if (class_exists('TCPDF2DBarcode')) {
            try {
                $barcode = new TCPDF2DBarcode($payload, 'QRCODE,M');
                return $barcode->getBarcodeSVG($size, $size, '#0f172a');
            } catch (Exception $e) {
                return null;
            }
        }
        return null;
    }
}
