<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= html_escape($title ?? 'Pagamento Pix - Banco Cora'); ?></title>
    
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
            margin-bottom: 25px;
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
        #qrcode img, #qrcode canvas {
            max-width: 100%;
            height: auto;
            margin: 0 auto;
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
    </style>
</head>
<body>

<div class="pix-container">
    <div class="pix-card">
        <div class="pix-header">
            <h2><i class="fab fa-pix text-primary"></i> Pagamento via Pix Banco Cora</h2>
            <p>Fatura #<?= html_escape(format_invoice_number($invoice->id)); ?> &bull; <?= html_escape(get_option('companyname')); ?></p>
        </div>

        <div class="pix-body" id="payment-view">
            <!-- Alerta de Status Dinâmico -->
            <div class="status-tracker" id="status-indicator">
                <div class="pulse-dot"></div>
                <span>Aguardando confirmação do pagamento...</span>
            </div>

            <!-- Resumo do Valor -->
            <div class="pix-amount-badge">
                <div class="pix-amount-title">Valor a Pagar</div>
                <div class="pix-amount-val">
                    R$ <?= number_format((float)$amount, 2, ',', '.'); ?>
                </div>
            </div>

            <!-- Box do QR Code -->
            <div class="qrcode-box">
                <div id="qrcode"></div>
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

            <!-- Instruções -->
            <div class="instruction-steps">
                <h4><i class="fas fa-info-circle text-info"></i> Como pagar pelo aplicativo do seu banco:</h4>
                <ol>
                    <li>Abra o aplicativo do seu banco de preferência.</li>
                    <li>Selecione a opção <strong>Pix</strong> e clique em <strong>Pagar com QR Code</strong> ou <strong>Pix Copia e Cola</strong>.</li>
                    <li>Escaneie a imagem do QR Code ou cole o código copiado.</li>
                    <li>Confira o valor e os dados e confirme. A baixa nesta tela ocorrerá em instantes!</li>
                </ol>
            </div>
        </div>

        <!-- Tela de Sucesso após Liquidação via Webhook -->
        <div class="success-overlay" id="success-view">
            <i class="fas fa-check-circle icon-success"></i>
            <h3 style="font-weight: 700; color: #0f172a; margin-top: 0;">Pagamento Confirmado!</h3>
            <p class="text-muted" style="font-size: 15px;">
                Recebemos seu pagamento com sucesso. Redirecionando para a fatura liquidada...
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
        var pixPayload = <?= json_encode($pix_copia_cola); ?>;
        var checkStatusUrl = <?= json_encode($check_status_url); ?>;
        var redirectUrl = <?= json_encode($invoice_url); ?>;

        // 1. Renderiza o QR Code dinâmico
        var qrcodeContainer = document.getElementById("qrcode");
        if (typeof QRCode !== "undefined" && pixPayload) {
            new QRCode(qrcodeContainer, {
                text: pixPayload,
                width: 220,
                height: 220,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        }

        // 2. Ação de Copiar Código Pix com feedback
        var btnCopy = document.getElementById("btn-copy");
        var copyInput = document.getElementById("pix-copia-cola");

        btnCopy.addEventListener("click", function() {
            copyInput.select();
            copyInput.setSelectionRange(0, 99999);

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(pixPayload).then(function() {
                    applyCopiedState();
                }).catch(function() {
                    fallbackCopy();
                });
            } else {
                fallbackCopy();
            }

            function fallbackCopy() {
                try {
                    document.execCommand('copy');
                    applyCopiedState();
                } catch (err) {
                    alert("Por favor, selecione e copie o código manualmente.");
                }
            }

            function applyCopiedState() {
                var originalHtml = btnCopy.innerHTML;
                btnCopy.classList.add("copied");
                btnCopy.innerHTML = '<i class="fas fa-check"></i> Código Pix Copiado!';
                setTimeout(function() {
                    btnCopy.classList.remove("copied");
                    btnCopy.innerHTML = originalHtml;
                }, 3500);
            }
        });

        // 3. Polling em segundo plano para detecção em tempo real do Webhook
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
                // Silencia erros temporários de rede
            });
        }, 4000);
    });
</script>

</body>
</html>
