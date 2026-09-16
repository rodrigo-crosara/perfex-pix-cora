<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Script de Instalação e Migração do Módulo Pix Cora para Perfex CRM
 */
$CI = &get_instance();

// 1. Criação da tabela de transações Pix Cora se não existir
if (!$CI->db->table_exists(db_prefix() . 'pix_cora_transactions')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "pix_cora_transactions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` INT(11) NOT NULL,
        `txid` VARCHAR(35) NOT NULL,
        `pix_copia_cola` TEXT NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'ATIVA',
        `created_at` DATETIME NOT NULL,
        `paid_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `txid` (`txid`),
        KEY `invoice_id` (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
}

// 2. Criação do diretório seguro certs/ e garantia do arquivo de proteção .htaccess
$certsDir = module_dir_path('pix_cora', 'certs');
if (!is_dir($certsDir)) {
    @mkdir($certsDir, 0700, true);
}

$htaccessPath = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($htaccessPath)) {
    $htaccessContent = "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($htaccessPath, $htaccessContent);
}

$indexHtmlPath = rtrim($certsDir, '/\\') . DIRECTORY_SEPARATOR . 'index.html';
if (!file_exists($indexHtmlPath)) {
    @file_put_contents($indexHtmlPath, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>');
}
