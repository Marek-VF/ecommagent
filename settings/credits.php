<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/bootstrap.php';
require_once __DIR__ . '/../credits.php';

auth_require_login();

$pdo = auth_pdo();
$currentUser = auth_user();

if ($currentUser === null || !isset($currentUser['id'])) {
    auth_logout();
    auth_redirect('/auth/login.php');
}

$userId = (int) $currentUser['id'];
$config = auth_config();

$paypalConfig = $config['paypal'] ?? [];
$paypalClientId = isset($paypalConfig['client_id']) && is_string($paypalConfig['client_id']) ? $paypalConfig['client_id'] : '';
$paypalCurrency = isset($paypalConfig['currency']) && is_string($paypalConfig['currency'])
    ? strtoupper($paypalConfig['currency'])
    : 'EUR';

$availablePackages = [];
$packageRows = get_credit_packages($pdo);
foreach ($packageRows as $package) {
    $packageId = isset($package['package_key']) ? (string) $package['package_key'] : '';
    if ($packageId === '') {
        continue;
    }

    $amount = isset($package['price']) ? (float) $package['price'] : 0.0;
    $credits = isset($package['credits']) ? (float) $package['credits'] : 0.0;
    $currency = isset($package['currency']) && is_string($package['currency']) && $package['currency'] !== ''
        ? strtoupper($package['currency'])
        : $paypalCurrency;
    $label = isset($package['label']) && is_string($package['label']) && $package['label'] !== ''
        ? $package['label']
        : sprintf('%s Credits', number_format($credits, 0));

    if ($amount <= 0 || $credits <= 0) {
        continue;
    }

    $availablePackages[] = [
        'id'        => $packageId,
        'label'     => $label,
        'amount'    => $amount,
        'credits'   => $credits,
        'currency'  => $currency,
        'amount_display'  => number_format($amount, 2, ',', '.') . ' ' . $currency,
        'credits_display' => number_format($credits, 0, ',', '.') . ' Credits',
    ];
}

$statement = $pdo->prepare('SELECT credits_balance FROM users WHERE id = :id LIMIT 1');
$statement->execute(['id' => $userId]);
$creditsBalance = $statement->fetchColumn();

$creditsBalance = is_numeric($creditsBalance) ? (float) $creditsBalance : 0.0;

// Credits für die Anzeige auf 2 Nachkommastellen formatieren
$formattedCredits = number_format($creditsBalance, 2, ',', '.');

$activePage = 'credits';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits - Ecomm Agent</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Roboto+Mono:wght@400;500&display=swap"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="settings.css">
</head>
<body>
    <div class="settings-app">
        <header class="settings-header">
            <h1>Einstellungen</h1>
            <a class="btn-primary app__status-row-button settings-back-button" href="../index.php">Zurück</a>
        </header>
        <div class="settings-main">
            <nav class="settings-nav" aria-label="Einstellungsnavigation">
                <a
                    class="settings-nav-item<?php echo $activePage === 'profile' ? ' active' : ''; ?>"
                    href="index.php"
                >Profil</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'industry' ? ' active' : ''; ?>"
                    href="industry.php"
                >Branche</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image' ? ' active' : ''; ?>"
                    href="image.php"
                >Seitenverhältnis</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'image_variants' ? ' active' : ''; ?>"
                    href="image_variants.php"
                >Bildvarianten</a>
                <a
                    class="settings-nav-item<?php echo $activePage === 'credits' ? ' active' : ''; ?>"
                    href="credits.php"
                >Credits</a>
            </nav>
            <div class="settings-content">
                <div class="settings-card credits-card">
                    <div class="credits-card-header">
                        <div>
                            <h2 class="settings-section-title">Credits</h2>
                            <p class="settings-section-subtitle">
                                Hier sehen Sie Ihren aktuellen Credit-Kontostand für die Nutzung der KI-Workflows.
                            </p>
                        </div>
                    </div>
                    <div class="credits-balance-panel">
                        <div class="credits-label">Aktueller Kontostand</div>
                        <div class="credits-value"><?php echo htmlspecialchars($formattedCredits, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    </div>
                    <div class="credits-purchase-panel">
                        <div class="credits-card-header">
                            <div>
                                <h3 class="credits-subtitle">Credits aufladen</h3>
                                <p class="settings-section-subtitle">
                                    Wählen Sie ein Paket und zahlen Sie sicher per PayPal.
                                </p>
                            </div>
                        </div>
                        <?php if (!empty($availablePackages)) : ?>
                            <div class="credit-packages-grid">
                                <?php foreach ($availablePackages as $package) : ?>
                                    <button
                                        type="button"
                                        class="credit-package-card"
                                        data-package-id="<?php echo htmlspecialchars($package['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                    >
                                        <div class="credit-package-label"><?php echo htmlspecialchars($package['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                        <div class="credit-package-credits"><?php echo htmlspecialchars($package['credits_display'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                        <div class="credit-package-amount"><?php echo htmlspecialchars($package['amount_display'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div id="credit-message" class="credit-message" aria-live="polite"></div>
                            <div id="paypal-button-container" class="paypal-button-container"></div>
                        <?php else : ?>
                            <p class="settings-section-subtitle">Aktuell sind keine Credit-Pakete konfiguriert.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php if ($paypalClientId !== '') : ?>
        <script src="https://www.paypal.com/sdk/js?client-id=<?php echo rawurlencode($paypalClientId); ?>&currency=<?php echo urlencode($paypalCurrency); ?>&intent=capture"></script>
    <?php endif; ?>
    <script>
        (() => {
            const packages = <?php echo json_encode($availablePackages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            const packageButtons = Array.from(document.querySelectorAll('.credit-package-card'));
            const messageBox = document.getElementById('credit-message');
            const balanceLabel = document.querySelector('.credits-value');
            const paypalContainer = document.getElementById('paypal-button-container');
            let selectedPackage = null;

            const packageMap = packages.reduce((map, pkg) => {
                if (pkg && pkg.id) {
                    map[pkg.id] = pkg;
                }
                return map;
            }, {});

            function showMessage(text, type = 'info') {
                if (!messageBox) {
                    return;
                }

                messageBox.textContent = text;
                messageBox.className = `credit-message credit-message--${type}`;
            }

            function updateBalanceDisplay(newBalance) {
                if (!balanceLabel || typeof newBalance !== 'number') {
                    return;
                }

                const formatted = newBalance.toLocaleString('de-DE', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
                balanceLabel.textContent = formatted;
            }

            packageButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    packageButtons.forEach((btn) => btn.classList.remove('selected'));
                    button.classList.add('selected');
                    selectedPackage = button.dataset.packageId || null;
                    if (selectedPackage && packageMap[selectedPackage]) {
                        showMessage(`Ausgewählt: ${packageMap[selectedPackage].label}`, 'info');
                    }
                });
            });

            if (typeof paypal === 'undefined') {
                if (paypalContainer) {
                    if (<?php echo $paypalClientId === '' ? 'true' : 'false'; ?>) {
                        paypalContainer.textContent = 'PayPal ist nicht konfiguriert.';
                    } else {
                        showMessage('PayPal konnte nicht geladen werden. Bitte versuchen Sie es erneut.', 'error');
                    }
                }
                return;
            }

            paypal.Buttons({
                style: {
                    layout: 'vertical',
                    color: 'gold',
                    shape: 'rect',
                    label: 'paypal',
                },
                createOrder: () => {
                    if (!selectedPackage || !packageMap[selectedPackage]) {
                        showMessage('Bitte wählen Sie zuerst ein Paket aus.', 'error');
                        return Promise.reject(new Error('no_package_selected'));
                    }

                    showMessage('PayPal-Bestellung wird erstellt ...', 'info');

                    return fetch('../api/paypal/create-order.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ package_id: selectedPackage }),
                    })
                        .then((response) => response.json().then((data) => ({ status: response.status, data })))
                        .then(({ status, data }) => {
                            if (!data || !data.ok || !data.orderID) {
                                const error = data && data.error ? data.error : 'create_failed';
                                showMessage('PayPal-Bestellung konnte nicht erstellt werden.', 'error');
                                return Promise.reject(new Error(error));
                            }

                            if (status >= 400) {
                                showMessage('PayPal-Bestellung konnte nicht erstellt werden.', 'error');
                                return Promise.reject(new Error('http_error'));
                            }

                            return data.orderID;
                        })
                        .catch((error) => {
                            showMessage('PayPal-Bestellung konnte nicht erstellt werden.', 'error');
                            return Promise.reject(error);
                        });
                },
                onApprove: (data) => {
                    showMessage('Zahlung wird bestätigt ...', 'info');

                    return fetch('../api/paypal/capture-order.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ orderID: data.orderID }),
                    })
                        .then((response) => response.json().then((payload) => ({ status: response.status, payload })))
                        .then(({ status, payload }) => {
                            if (!payload || !payload.ok) {
                                const errorText = payload && payload.error ? payload.error : 'capture_failed';
                                showMessage('Die Zahlung konnte nicht abgeschlossen werden.', 'error');
                                return Promise.reject(new Error(errorText));
                            }

                            if (typeof payload.balance === 'number') {
                                updateBalanceDisplay(payload.balance);
                            }

                            showMessage('Credits wurden erfolgreich gutgeschrieben.', 'success');
                            return payload;
                        })
                        .catch((error) => {
                            showMessage('Die Zahlung konnte nicht abgeschlossen werden.', 'error');
                            return Promise.reject(error);
                        });
                },
                onError: () => {
                    showMessage('Es ist ein Fehler bei PayPal aufgetreten. Bitte versuchen Sie es erneut.', 'error');
                },
                onCancel: () => {
                    showMessage('Zahlung abgebrochen.', 'info');
                },
            }).render('#paypal-button-container');
        })();
    </script>
</body>
</html>
