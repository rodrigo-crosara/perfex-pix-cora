<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Script de Instalação e Migração do Módulo Unificado Cora Payments
 * 
 * Cria e atualiza a tabela unificada {db_prefix}cora_transactions para suportar
 * Pix Imediato e Boleto Bancário Híbrido Cora.
 */
$CI = &get_instance();

// 1. Criação / Atualização da tabela {db_prefix}cora_transactions
$tableName = db_prefix() . 'cora_transactions';

if (!$CI->db->table_exists($tableName)) {
    $CI->db->query("CREATE TABLE `{$tableName}` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` INT(11) NOT NULL,
        `type` VARCHAR(10) NOT NULL DEFAULT 'PIX',
        `txid` VARCHAR(64) NOT NULL,
        `cora_invoice_id` VARCHAR(100) DEFAULT NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
        `pix_copia_cola` TEXT DEFAULT NULL,
        `barcode` VARCHAR(100) DEFAULT NULL,
        `pdf_url` TEXT DEFAULT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        `created_at` DATETIME NOT NULL,
        `paid_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `txid` (`txid`),
        KEY `invoice_id` (`invoice_id`),
        KEY `type` (`type`),
        KEY `cora_invoice_id` (`cora_invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
} else {
    // Garante que o campo txid tenha pelo menos VARCHAR(64) para evitar truncamento
    $CI->db->query("ALTER TABLE `{$tableName}` MODIFY `txid` VARCHAR(64) NOT NULL;");

    // Migração de campos se a tabela já existir previamente
    if (!$CI->db->field_exists('type', $tableName)) {
        $CI->db->query("ALTER TABLE `{$tableName}` ADD `type` VARCHAR(10) NOT NULL DEFAULT 'PIX' AFTER `invoice_id`;");
        $CI->db->query("ALTER TABLE `{$tableName}` ADD KEY `type` (`type`);");
    }

    if (!$CI->db->field_exists('cora_invoice_id', $tableName)) {
        $CI->db->query("ALTER TABLE `{$tableName}` ADD `cora_invoice_id` VARCHAR(100) DEFAULT NULL AFTER `txid`;");
        $CI->db->query("ALTER TABLE `{$tableName}` ADD KEY `cora_invoice_id` (`cora_invoice_id`);");
    }

    if (!$CI->db->field_exists('amount', $tableName)) {
        $CI->db->query("ALTER TABLE `{$tableName}` ADD `amount` DECIMAL(15,2) NOT NULL DEFAULT '0.00' AFTER `cora_invoice_id`;");
    }

    if (!$CI->db->field_exists('barcode', $tableName)) {
        $CI->db->query("ALTER TABLE `{$tableName}` ADD `barcode` VARCHAR(100) DEFAULT NULL AFTER `pix_copia_cola`;");
    }

    if (!$CI->db->field_exists('pdf_url', $tableName)) {
        $CI->db->query("ALTER TABLE `{$tableName}` ADD `pdf_url` TEXT DEFAULT NULL AFTER `barcode`;");
    }

    if (!$CI->db->field_exists('status', $tableName)) {
        $CI->db->query("ALTER TABLE `{$tableName}` ADD `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING' AFTER `pdf_url`;");
    }

    if (!$CI->db->field_exists('paid_at', $tableName)) {
        $CI->db->query("ALTER TABLE `{$tableName}` ADD `paid_at` DATETIME DEFAULT NULL AFTER `created_at`;");
    }
}

// 2. Migração retroativa de dados do módulo legado (pix_cora_transactions) caso exista
$legacyTable = db_prefix() . 'pix_cora_transactions';
if ($CI->db->table_exists($legacyTable)) {
    $hasAmount = $CI->db->field_exists('amount', $legacyTable);
    $amountCol = $hasAmount ? 'amount' : "'0.00'";

    $CI->db->query("INSERT IGNORE INTO `{$tableName}` 
        (`invoice_id`, `type`, `txid`, `amount`, `pix_copia_cola`, `status`, `created_at`, `paid_at`)
        SELECT `invoice_id`, 'PIX', `txid`, {$amountCol}, `pix_copia_cola`, `status`, `created_at`, `paid_at`
        FROM `{$legacyTable}`;");
}

// 3. Estrutura e Blindagem de Segurança do Diretório de Certificados mTLS
$certsDir = module_dir_path('cora_payments', 'certs');
if (!is_dir($certsDir)) {
    @mkdir($certsDir, 0750, true);
} else {
    @chmod($certsDir, 0750);
}

// Atualiza permissões de leitura dos certificados caso já existam em disco (0640 para compatibilidade PHP-FPM)
$certFile = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . 'cora_cert.pem';
$keyFile  = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . 'cora_key.key';
if (file_exists($certFile)) {
    @chmod($certFile, 0640);
}
if (file_exists($keyFile)) {
    @chmod($keyFile, 0640);
}

// Grava .htaccess para Apache/LiteSpeed
$htaccessPath = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($htaccessPath)) {
    $htaccessContent = "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($htaccessPath, $htaccessContent);
}

// Grava index.html e index.php para blindagem contra Nginx e outros web servers
$indexHtmlPath = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . 'index.html';
if (!file_exists($indexHtmlPath)) {
    @file_put_contents($indexHtmlPath, "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>");
}

$indexPhpPath = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . 'index.php';
if (!file_exists($indexPhpPath)) {
    @file_put_contents($indexPhpPath, "<?php\ndefined('BASEPATH') or exit('No direct script access allowed');\nheader('HTTP/1.1 403 Forbidden');\nexit('Access denied.');\n");
}

// 4. Endpoint Proxy Liberado Nativamente de CSRF pelo Perfex CRM
// O Perfex CRM mantém a rota 'gateways/.*' liberada de CSRF por padrão em todas as instalações.
// Cria o controller proxy leve em application/controllers/gateways/Cora.php
if (defined('APPPATH')) {
    $gateways_dir = APPPATH . 'controllers/gateways';
    $proxy_file   = $gateways_dir . '/Cora.php';

    if (is_dir($gateways_dir) && is_writable($gateways_dir)) {
        $proxy_code = '<?php
defined("BASEPATH") or exit("No direct script access allowed");

/**
 * Gateway Webhook Proxy Oficial - Cora Payments
 * Rota isenta nativamente de CSRF pelo Perfex CRM: /gateways/cora/webhook
 */
class Cora extends App_Controller
{
    public function webhook()
    {
        require_once(module_dir_path("cora_payments", "controllers/Cora.php"));
        $controller = class_exists("Cora_gateway_controller", false) ? new Cora_gateway_controller() : new Cora();
        $controller->webhook();
    }
}
';
        if (!file_exists($proxy_file) || (file_exists($proxy_file) && strpos(@file_get_contents($proxy_file), 'cora_payments') !== false)) {
            @file_put_contents($proxy_file, $proxy_code);
        }
    }
}

