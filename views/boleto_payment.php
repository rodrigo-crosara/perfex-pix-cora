<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= html_escape($title ?? 'Boleto Bancário - Banco Cora'); ?></title>
    
    <!-- Perfex CRM / Bootstrap Native Styling -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            background-color: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #334155;
            padding: 30px 15px;
        }
        .boleto-container {
            max-width: 650px;
            margin: 0 auto;
        }
        .boleto-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 25px;
        }
        .boleto-header {
            background: #0f172a;
            color: #ffffff;
            padding: 24px;
            text-align: center;
        }
        .boleto-header h2 {
            margin: 0 0 6px 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .boleto-header p {
            margin: 0;
            color: #94a3b8;
            font-size: 14px;
        }
        .boleto-body {
            padding: 32px 28px;
        }
        .amount-row {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
        }
        .amount-col {
            flex: 1;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            text-align: center;
        }
        .amount-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .amount-val {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }
        .barcode-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 25px;
        }
        .barcode-number {
            font-family: monospace;
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            word-break: break-all;
            margin-bottom: 10px;
            text-align: center;
        }
        .btn-action-primary {
            border-radius: 8px;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.2s ease;
            width: 100%;
            background-color: #0284c7;
            border-color: #0284c7;
            color: #ffffff;
        }
        .btn-action-primary:hover {
            background-color: #0369a1;
            border-color: #0369a1;
            color: #ffffff;
        }
        .btn-action-secondary {
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 600;
            font-size: 14px;
            width: 100%;
            background-color: #f1f5f9;
            border-color: #cbd5e1;
            color: #334155;
            margin-top: 10px;
        }
        .btn-action-secondary:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }
        .btn-action-secondary.copied {
            background-color: #10b981 !important;
            border-color: #10b981 !important;
            color: #ffffff !important;
        }
        .pix-hybrid-box {
            background: #ffffff;
            border: 2px dashed #0284c7;
            border-radius: 14px;
            padding: 20px;
            margin-top: 25px;
            text-align: center;
        }
        .pix-hybrid-title {
            color: #0284c7;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 8px;
        }
        .qrcode-box {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 15px auto;
            width: 180px;
            height: 180px;
        }
        #qrcode img, #qrcode canvas {
            max-width: 100%;
            height: auto;
        }
        .status-tracker {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            border-radius: 8px;
            background-color: #eff6ff;
            color: #1d4ed8;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .pulse-dot {
            width: 10px;
            height: 10px;
            background-color: #3b82f6;
            border-radius: 50%;
            margin-right: 10px;
            animation: pulse-ring 1.5s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(59, 130, 246, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        .success-overlay {
            display: none;
            text-align: center;
            padding: 40px 20px;
        }
        .success-overlay .icon-success {
            font-size: 64px;
            color: #10b981;
            margin-bottom: 16px;
        }
        .btn-back-invoice {
            display: inline-block;
            margin-top: 15px;
            color: #64748b;
            font-size: 14px;
            text-decoration: none;
        }
        .btn-back-invoice:hover {
            color: #0f172a;
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="boleto-container">
    <div class="boleto-card">
        <div class="boleto-header">
            <h2><i class="fas fa-barcode text-primary"></i> Boleto Bancário Cora</h2>
            <p>Fatura #<?= html_escape(format_invoice_number($invoice->id)); ?> &bull; <?= html_escape(get_option('companyname')); ?></p>
        </div>

        <div class="boleto-body" id="payment-view">
            <!-- Indicador de Status Dinâmico -->
            <div class="status-tracker" id="status-indicator">
                <div class="pulse-dot"></div>
                <span>Aguardando pagamento / compensação bancária...</span>
            </div>

            <!-- Resumo Financeiro -->
            <div class="amount-row">
                <div class="amount-col">
                    <div class="amount-label">Valor do Boleto</div>
                    <div class="amount-val">
                        R$ <?= number_format((float)$amount, 2, ',', '.'); ?>
                    </div>
                </div>
                <div class="amount-col">
                    <div class="amount-label">Vencimento</div>
                    <div class="amount-val" style="font-size: 20px; padding-top: 4px;">
                        <?= !empty($invoice->duedate) ? _d($invoice->duedate) : date('d/m/Y'); ?>
                    </div>
                </div>
            </div>

            <!-- Linha Digitável e Código de Barras -->
            <?php if (!empty($barcode)) : ?>
            <div class="barcode-box">
                <div class="amount-label" style="text-align: center; margin-bottom: 8px;">Linha Digitável para Pagamento:</div>
                <div class="barcode-number" id="barcode-text"><?= html_escape($barcode); ?></div>
                <button type="button" id="btn-copy-barcode" class="btn btn-action-secondary">
                    <i class="far fa-copy"></i> Copiar Linha Digitável
                </button>
            </div>
            <?php endif; ?>

            <!-- Botão de Download do PDF Oficial -->
            <?php if (!empty($pdf_url)) : ?>
            <div style="margin-bottom: 25px;">
                <a href="<?= html_escape($pdf_url); ?>" target="_blank" class="btn btn-action-primary" style="display: block; text-align: center;">
                    <i class="fas fa-file-pdf"></i> Visualizar / Baixar Boleto Oficial (PDF)
                </a>
            </div>
            <?php endif; ?>

            <!-- QR Code Pix Embutido (Boleto Híbrido) -->
            <?php if (!empty($pix_copia_cola)) : ?>
            <div class="pix-hybrid-box">
                <div class="pix-hybrid-title"><i class="fab fa-pix"></i> Pague Instantaneamente via Pix</div>
                <p class="text-muted" style="font-size: 13px; margin-bottom: 12px;">
                    Este é um Boleto Híbrido! Você também pode pagar via Pix utilizando o QR Code abaixo para compensação imediata.
                </p>
                <div class="qrcode-box">
                    <div id="qrcode"></div>
                </div>
                <button type="button" id="btn-copy-pix" class="btn btn-action-secondary" style="max-width: 320px; margin: 0 auto; display: block;">
                    <i class="far fa-copy"></i> Copiar Código Pix
                </button>
                <textarea id="pix-raw" style="position: absolute; left: -9999px;"><?= html_escape($pix_copia_cola); ?></textarea>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tela de Sucesso após Liquidação via Webhook -->
        <div class="success-overlay" id="success-view">
            <i class="fas fa-check-circle icon-success"></i>
            <h3 style="font-weight: 700; color: #0f172a; margin-top: 0;">Pagamento Confirmado!</h3>
            <p class="text-muted" style="font-size: 15px;">
                O boleto bancário foi liquidado com sucesso. Redirecionando para a fatura quitada...
            </p>
            <div style="margin-top: 20px;">
                <a href="<?= html_escape($invoice_url); ?>" class="btn btn-primary" style="border-radius: 8px; padding: 10px 24px;">
                    Visualizar Fatura
                </a>
            </div>
        </div>
    </div>

    <div class="text-center">
        <a href="<?= html_escape($invoice_url); ?>" class="btn-back-invoice">
            <i class="fas fa-arrow-left"></i> Voltar aos detalhes da fatura
        </a>
    </div>
</div>

<!-- Scripts de Suporte -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var pixPayload = <?= json_encode($pix_copia_cola ?? ''); ?>;
        var checkStatusUrl = <?= json_encode($check_status_url); ?>;
        var redirectUrl = <?= json_encode($invoice_url); ?>;

        // 1. QR Code Pix do Boleto Híbrido
        var qrcodeContainer = document.getElementById("qrcode");
        if (typeof QRCode !== "undefined" && pixPayload && qrcodeContainer) {
            new QRCode(qrcodeContainer, {
                text: pixPayload,
                width: 170,
                height: 170,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        }

        // 2. Copiar Linha Digitável
        var btnCopyBarcode = document.getElementById("btn-copy-barcode");
        var barcodeText = document.getElementById("barcode-text");
        if (btnCopyBarcode && barcodeText) {
            btnCopyBarcode.addEventListener("click", function() {
                var text = barcodeText.innerText.trim();
                copyToClipboard(text, btnCopyBarcode, '<i class="fas fa-check"></i> Linha Digitável Copiada!');
            });
        }

        // 3. Copiar Pix Copia e Cola
        var btnCopyPix = document.getElementById("btn-copy-pix");
        if (btnCopyPix && pixPayload) {
            btnCopyPix.addEventListener("click", function() {
                copyToClipboard(pixPayload, btnCopyPix, '<i class="fas fa-check"></i> Código Pix Copiado!');
            });
        }

        function copyToClipboard(content, buttonElement, successHtml) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(content).then(function() {
                    applySuccess();
                }).catch(function() {
                    fallbackCopy(content, applySuccess);
                });
            } else {
                fallbackCopy(content, applySuccess);
            }

            function applySuccess() {
                var originalHtml = buttonElement.innerHTML;
                buttonElement.classList.add("copied");
                buttonElement.innerHTML = successHtml;
                setTimeout(function() {
                    buttonElement.classList.remove("copied");
                    buttonElement.innerHTML = originalHtml;
                }, 3000);
            }
        }

        function fallbackCopy(text, callback) {
            var tempInput = document.createElement("textarea");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            try {
                document.execCommand('copy');
                if (callback) callback();
            } catch (err) {
                alert("Por favor copie manualmente.");
            }
            document.body.removeChild(tempInput);
        }

        // 4. Polling em segundo plano
        var pollingInterval = setInterval(function() {
            fetch(checkStatusUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data && data.paid === true) {
                    clearInterval(pollingInterval);
                    document.getElementById("payment-view").style.display = "none";
                    document.getElementById("success-view").style.display = "block";

                    setTimeout(function() {
                        window.location.href = data.redirect_url || redirectUrl;
                    }, 2000);
                }
            })
            .catch(function(error) {
                // Silencia falhas de conexão temporárias
            });
        }, 4000);
    });
</script>

</body>
</html>
