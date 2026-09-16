<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Script de Instalação e Migração do Módulo Pix Cora para Perfex CRM
 */
$CI = &get_instance();

// 1. Criação da tabela de transações Pix Cora com suporte a valor (pagamento parcial)
if (!$CI->db->table_exists(db_prefix() . 'pix_cora_transactions')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "pix_cora_transactions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` INT(11) NOT NULL,
        `txid` VARCHAR(35) NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
        `pix_copia_cola` TEXT NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'ATIVA',
        `created_at` DATETIME NOT NULL,
        `paid_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `txid` (`txid`),
        KEY `invoice_id` (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
} else {
    // Migração: adiciona a coluna amount caso a tabela já exista de versão anterior
    if (!$CI->db->field_exists('amount', db_prefix() . 'pix_cora_transactions')) {
        $CI->db->query('ALTER TABLE `' . db_prefix() . "pix_cora_transactions` ADD `amount` DECIMAL(15,2) NOT NULL DEFAULT '0.00' AFTER `txid`;");
    }
}

// 2. Proteção Avançada contra Vazamento de Certificados (Compatível com Nginx e Apache)
// Gera um token aleatório criptográfico único por instalação para o diretório de certificados
$secureToken = get_option('pix_cora_secure_token');
if (empty($secureToken)) {
    $secureToken = bin2hex(random_bytes(16));
    add_option('pix_cora_secure_token', $secureToken);
}

$secureDir = module_dir_path('pix_cora', 'certs_' . $secureToken);
if (!is_dir($secureDir)) {
    @mkdir($secureDir, 0700, true);
}

// Grava .htaccess para Apache/LiteSpeed
$htaccessPath = rtrim($secureDir, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($htaccessPath)) {
    $htaccessContent = "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($htaccessPath, $htaccessContent);
}

// Grava index.html e index.php para blindagem total contra Nginx e outros web servers
$indexHtmlPath = rtrim($secureDir, '/\\') . DIRECTORY_SEPARATOR . 'index.html';
if (!file_exists($indexHtmlPath)) {
    @file_put_contents($indexHtmlPath, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>');
}

$indexPhpPath = rtrim($secureDir, '/\\') . DIRECTORY_SEPARATOR . 'index.php';
if (!file_exists($indexPhpPath)) {
    @file_put_contents($indexPhpPath, "<?php\ndefined('BASEPATH') or exit('No direct script access allowed');\nheader('HTTP/1.1 403 Forbidden');\nexit('Access denied.');\n");
}
