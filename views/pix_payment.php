<?php defined('BASEPATH') or exit('No direct script access allowed'); 
$isManual = !empty($is_manual);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= html_escape($title ?? 'Pagamento Pix'); ?></title>
    
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
        .pix-container {
            max-width: 620px;
            margin: 0 auto;
        }
        .pix-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 25px;
        }
        .pix-header {
            background: #0f172a;
            color: #ffffff;
            padding: 24px;
            text-align: center;
        }
        .pix-header h2 {
            margin: 0 0 6px 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .pix-header p {
            margin: 0;
            color: #94a3b8;
            font-size: 14px;
        }
        .pix-body {
            padding: 32px 28px;
        }
        .pix-amount-badge {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            margin-bottom: 20px;
        }
        .pix-amount-title {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .pix-amount-val {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }
        .qrcode-box {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 15px;
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: 14px;
            margin: 0 auto 25px auto;
            width: 250px;
            height: 250px;
        }
        #qrcode img, #qrcode canvas, #qrcode svg {
            max-width: 100%;
            height: auto;
            margin: 0 auto;
            display: block;
        }
        .copy-group {
            margin-bottom: 25px;
        }
        .copy-input {
            font-family: monospace;
            font-size: 12px;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            resize: none;
            word-break: break-all;
            padding: 10px;
            color: #475569;
        }
        .btn-copy {
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
        .btn-copy:hover {
            background-color: #0369a1;
            border-color: #0369a1;
            color: #ffffff;
        }
        .btn-copy.copied {
            background-color: #10b981 !important;
            border-color: #10b981 !important;
            color: #ffffff;
        }
        .btn-whatsapp {
            background-color: #25d366;
            border-color: #25d366;
            color: #ffffff;
            transition: all 0.2s ease;
        }
        .btn-whatsapp:hover, .btn-whatsapp:focus {
            background-color: #1ea952;
            border-color: #1ea952;
            color: #ffffff;
        }
        .btn-email-action {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #334155;
            transition: all 0.2s ease;
        }
        .btn-email-action:hover, .btn-email-action:focus {
            background-color: #e2e8f0;
            color: #0f172a;
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
            text-align: center;
        }
        .status-tracker.manual-tracker {
            background-color: #fffbeb;
            color: #92400e;
            border: 1px solid #fef3c7;
        }
        .pulse-dot {
            width: 10px;
            height: 10px;
            background-color: #3b82f6;
            border-radius: 50%;
            margin-right: 10px;
            flex-shrink: 0;
            animation: pulse-ring 1.5s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }
        .pulse-dot.manual-dot {
            background-color: #f59e0b;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(59, 130, 246, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        .instruction-steps {
            background-color: #f8fafc;
            border-radius: 12px;
            padding: 16px 20px;
            margin-top: 20px;
            border: 1px solid #f1f5f9;
        }
        .instruction-steps h4 {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            margin-top: 0;
            margin-bottom: 12px;
        }
        .instruction-steps ol {
            padding-left: 20px;
            margin-bottom: 0;
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
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
        .beneficiary-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }
        .beneficiary-card .card-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .beneficiary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            margin-bottom: 4px;
        }
        .beneficiary-row:last-child {
            margin-bottom: 0;
        }
    </style>
</head>
<body>

<div class="pix-container">
    <div class="pix-card">
        <div class="pix-header">
            <h2>
                <i class="fab fa-pix text-primary"></i> 
                <?= $isManual ? 'Pagamento via Pix' : 'Pagamento via Pix Banco Cora'; ?>
            </h2>
            <p>Fatura #<?= html_escape(format_invoice_number($invoice->id)); ?> &bull; <?= html_escape(get_option('companyname')); ?></p>
        </div>

        <div class="pix-body" id="payment-view">
            <!-- Indicador de Status -->
            <?php if ($isManual): ?>
                <div class="status-tracker manual-tracker" id="status-indicator">
                    <div class="pulse-dot manual-dot"></div>
                    <span>Aguardando pagamento e envio do comprovante (Baixa manual)</span>
                </div>
            <?php else: ?>
                <div class="status-tracker" id="status-indicator">
                    <div class="pulse-dot"></div>
                    <span>Aguardando confirmação do pagamento...</span>
                </div>
            <?php endif; ?>

            <!-- Resumo do Valor -->
            <div class="pix-amount-badge">
                <div class="pix-amount-title">Valor a Pagar</div>
                <div class="pix-amount-val">
                    R$ <?= number_format((float)$amount, 2, ',', '.'); ?>
                </div>
            </div>

            <!-- Dados do Titular / Favorecido (No Modo Manual) -->
            <?php if ($isManual && (!empty($merchant_name) || !empty($pix_key))): ?>
                <div class="beneficiary-card">
                    <div class="card-label">
                        <i class="fas fa-university"></i> Dados da Conta de Destino
                    </div>
                    <?php if (!empty($merchant_name)): ?>
                        <div class="beneficiary-row">
                            <span class="text-muted">Titular:</span>
                            <strong><?= html_escape($merchant_name); ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($pix_key)): ?>
                        <div class="beneficiary-row">
                            <span class="text-muted">Chave Pix (<?= strtoupper(html_escape($key_type ?? 'CHAVE')); ?>):</span>
                            <strong style="user-select: all;"><?= html_escape($pix_key); ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="beneficiary-row">
                        <span class="text-muted">Identificador (TxID):</span>
                        <code style="font-size: 12px;"><?= html_escape($txid); ?></code>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Box do QR Code -->
            <div class="qrcode-box">
                <div id="qrcode"><?= !empty($qr_code_svg) ? $qr_code_svg : ''; ?></div>
            </div>

            <!-- Campo Pix Copia e Cola -->
            <div class="copy-group">
                <label for="pix-copia-cola" class="text-muted" style="font-size: 13px; font-weight: 600;">
                    Código Pix Copia e Cola:
                </label>
                <textarea id="pix-copia-cola" class="form-control copy-input" rows="3" readonly><?= html_escape($pix_copia_cola); ?></textarea>
                
                <button type="button" id="btn-copy" class="btn btn-copy" style="margin-top: 10px;">
                    <i class="far fa-copy"></i> Copiar Código Pix
                </button>
            </div>

            <!-- Seção de Contingência: Alerta e Ações de Comprovante no Modo Manual -->
            <?php if ($isManual): ?>
                <div class="alert alert-warning" style="border-radius: 10px; font-size: 13px; line-height: 1.6; border: 1px solid #fde68a; background-color: #fffbeb; color: #92400e; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong> Este pagamento opera com <strong>baixa manual</strong>. Após transferir ou pagar pelo aplicativo do seu banco, favor enviar o comprovante com o número da sua fatura para que nossa equipe financeira realize a conciliação bancária e a quitação no sistema.
                </div>

                <?php if (!empty($instructions)): ?>
                    <div class="instruction-steps" style="margin-top: 0; margin-bottom: 20px; border-left: 4px solid #0284c7;">
                        <h4 style="color: #0f172a; margin-top: 0; margin-bottom: 8px;">
                            <i class="fas fa-file-invoice text-info"></i> Instruções para Confirmação:
                        </h4>
                        <div style="font-size: 13px; color: #475569; line-height: 1.6; white-space: pre-line;"><?= html_escape($instructions); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Botões de Contato Direto para Envio do Comprovante -->
                <?php
                $invoiceNum     = format_invoice_number($invoice->id);
                $valorFormatado = number_format((float)$amount, 2, ',', '.');
                $waText         = rawurlencode("Olá! Segue o comprovante de pagamento via Pix da Fatura #{$invoiceNum} no valor de R$ {$valorFormatado}.");
                $cleanWa        = !empty($whatsapp) ? preg_replace('/\D/', '', $whatsapp) : '';
                if (!empty($cleanWa) && strlen($cleanWa) >= 10 && strpos($cleanWa, '55') !== 0) {
                    $cleanWa = '55' . $cleanWa;
                }
                ?>
                <div style="margin-bottom: 22px;">
                    <?php if (!empty($cleanWa)): ?>
                        <a href="https://api.whatsapp.com/send?phone=<?= $cleanWa; ?>&text=<?= $waText; ?>" target="_blank" class="btn btn-whatsapp" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; font-weight: 600; font-size: 15px; padding: 12px 20px; border-radius: 8px; text-decoration: none; margin-bottom: 10px;">
                            <i class="fab fa-whatsapp" style="font-size: 18px;"></i> Enviar Comprovante pelo WhatsApp
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($company_email)): ?>
                        <?php 
                        $mailSubject = rawurlencode("Comprovante de Pagamento - Fatura #{$invoiceNum}");
                        $mailBody    = rawurlencode("Olá,\n\nSegue anexo o comprovante de pagamento via Pix referente à Fatura #{$invoiceNum} no valor de R$ {$valorFormatado}.\n\nObrigado!");
                        ?>
                        <a href="mailto:<?= html_escape($company_email); ?>?subject=<?= $mailSubject; ?>&body=<?= $mailBody; ?>" class="btn btn-email-action" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; font-weight: 600; font-size: 14px; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
                            <i class="fas fa-envelope"></i> Enviar Comprovante por E-mail
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Instruções Gerais de Pagamento -->
            <div class="instruction-steps">
                <h4><i class="fas fa-info-circle text-info"></i> Como pagar pelo aplicativo do seu banco:</h4>
                <ol>
                    <li>Abra o aplicativo do seu banco de preferência no celular.</li>
                    <li>Selecione a área <strong>Pix</strong> e clique em <strong>Pagar com QR Code</strong> ou <strong>Pix Copia e Cola</strong>.</li>
                    <li>Escaneie a imagem do QR Code acima ou cole o código copiado.</li>
                    <li>Confira o valor e o nome do favorecido e confirme o pagamento.</li>
                    <?php if ($isManual): ?>
                        <li>Salve o comprovante da transação e envie para nosso financeiro via WhatsApp ou E-mail acima.</li>
                    <?php else: ?>
                        <li>Aguarde alguns segundos nesta tela. A baixa ocorrerá automaticamente após a compensação!</li>
                    <?php endif; ?>
                </ol>
            </div>
        </div>

        <!-- Tela de Sucesso após Liquidação (Automática via Webhook ou Baixa Manual no CRM) -->
        <div class="success-overlay" id="success-view">
            <i class="fas fa-check-circle icon-success"></i>
            <h3 style="font-weight: 700; color: #0f172a; margin-top: 0;">Pagamento Confirmado!</h3>
            <p class="text-muted" style="font-size: 15px;">
                Seu pagamento foi localizado e confirmado no sistema. Redirecionando para a fatura liquidada...
            </p>
            <div style="margin-top: 20px;">
                <a href="<?= html_escape($invoice_url); ?>" class="btn btn-primary" style="border-radius: 8px; padding: 10px 24px; font-weight: 600;">
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
        var pixPayload = <?= json_encode($pix_copia_cola); ?>;
        var checkStatusUrl = <?= json_encode($check_status_url); ?>;
        var redirectUrl = <?= json_encode($invoice_url); ?>;

        // 1. Renderiza o QR Code dinâmico caso não tenha SVG gerado no backend
        var qrcodeContainer = document.getElementById("qrcode");
        if (typeof QRCode !== "undefined" && pixPayload && (!qrcodeContainer.hasChildNodes() || qrcodeContainer.innerHTML.trim() === "")) {
            new QRCode(qrcodeContainer, {
                text: pixPayload,
                width: 220,
                height: 220,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        }

        // 2. Ação de Copiar Código Pix com feedback e fallback universal HTTP/HTTPS
        var btnCopy = document.getElementById("btn-copy");
        var copyInput = document.getElementById("pix-copia-cola");

        function mostrarFeedbackCopiado() {
            var originalHtml = btnCopy.innerHTML;
            btnCopy.classList.add("copied");
            btnCopy.innerHTML = '<i class="fas fa-check"></i> Código Pix Copiado!';
            setTimeout(function() {
                btnCopy.classList.remove("copied");
                btnCopy.innerHTML = originalHtml;
            }, 3500);
        }

        function copiarPix(texto) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(texto)
                    .then(mostrarFeedbackCopiado)
                    .catch(function() {
                        fallbackCopiar(texto);
                    });
            } else {
                fallbackCopiar(texto);
            }
        }

        function fallbackCopiar(texto) {
            var inputTemp = document.createElement("textarea");
            inputTemp.value = texto;
            inputTemp.style.position = "fixed";
            inputTemp.style.left = "-9999px";
            document.body.appendChild(inputTemp);
            inputTemp.focus();
            inputTemp.select();
            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    mostrarFeedbackCopiado();
                } else {
                    alert('Não foi possível copiar automaticamente. Selecione e copie o código manualmente.');
                }
            } catch (err) {
                alert('Não foi possível copiar automaticamente. Selecione e copie o código manualmente.');
            }
            document.body.removeChild(inputTemp);
        }

        btnCopy.addEventListener("click", function() {
            if (copyInput) {
                copyInput.select();
                copyInput.setSelectionRange(0, 99999);
            }
            copiarPix(pixPayload);
        });

        // 3. Polling em segundo plano para detecção em tempo real (Webhook ou Baixa Manual no CRM)
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
                // Silencia falhas temporárias de rede
            });
        }, 4000);
    });
</script>

</body>
</html>
