<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/models/plexus.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Services\PaymentService;

require_auth();
require_permission('manage_subscriptions');

$authUser = current_auth_user();
$payment  = new PaymentService();
$plans    = $payment->getPlans();

// Handle payment initialisation
$checkoutUrl = null;
$feedbackMsg = '';
$feedbackErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan_id'])) {
    $planId   = (int) $_POST['plan_id'];
    $tenantId = (int) ($_SESSION['tenant_id'] ?? 1);
    $email    = (string) ($authUser['email'] ?? '');
    $reference = $payment->generateReference($tenantId);

    $plan = null;
    foreach ($plans as $p) {
        if ((int) $p['id'] === $planId) {
            $plan = $p; break;
        }
    }

    if (!$plan) {
        $feedbackMsg = 'Invalid plan selected.';
        $feedbackErr = true;
    } else {
        $amountKobo = (int) round((float) $plan['price'] * 100);
        $result     = $payment->initializeTransaction($email, $amountKobo, $reference, [
            'plan_id'   => $planId,
            'tenant_id' => $tenantId,
        ]);

        if ($result && !empty($result['data']['authorization_url'])) {
            header('Location: ' . $result['data']['authorization_url']);
            exit;
        }

        $feedbackMsg = 'Payment gateway initialisation failed. Try again later.';
        $feedbackErr = true;
    }
}
?>
<?php
$pageTitle = 'SBMS Subscription Plans';
$bodyClass = 'auth-body sbms-plexus-body';
$mainClass = 'payments-shell';
$hideSidebar = false;
$viteAssets = ['assets/css/auth.css', 'assets/css/payments.css'];
$viteFooterAssets = ['assets/js/theme.js'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
        <section class="payments-hero auth-panel">
            <img src="<?= e(app_base_url()) ?>/assets/img/logo.svg" alt="SBMS Logo" style="width: 84px; height: 84px; margin-bottom: 20px;">
            <p class="eyebrow">SaaS Subscriptions</p>
            <h1>Choose a plan for your school.</h1>
            <p class="lead">All plans include the full platform. Upgrade or downgrade any time.</p>
            <a href="<?= e(app_base_url()) ?>/index.php" class="btn-secondary dashboard-link" style="margin-top:8px;display:inline-block;">← Back to Home</a>
        </section>

        <?php if ($feedbackMsg !== ''): ?>
        <div class="auth-panel" style="padding:20px 32px">
            <p class="feedback <?= $feedbackErr ? 'error' : 'success' ?>"><?= e($feedbackMsg) ?></p>
        </div>
        <?php endif; ?>

        <section class="plans-grid">
            <?php foreach ($plans as $plan):
                $features = is_string($plan['features']) ? (json_decode($plan['features'], true) ?? []) : [];
                $price    = number_format((float) $plan['price'], 2);
                $maxS     = (int) $plan['max_students'];
                $maxLabel = $maxS === 0 ? 'Unlimited' : number_format($maxS) . ' students';
            ?>
            <article class="plan-card auth-panel">
                <div class="plan-badge"><?= e(ucfirst($plan['billing_cycle'])) ?></div>
                <h2 class="plan-name"><?= e($plan['name']) ?></h2>
                <div class="plan-price">
                    <strong>$<?= e($price) ?></strong>
                    <span>/ <?= e($plan['billing_cycle']) ?></span>
                </div>
                <p class="plan-limit eyebrow" style="margin-top:12px"><?= e($maxLabel) ?></p>
                <ul class="feature-list plan-features">
                    <?php foreach ((array) $features as $feat): ?>
                        <li><?= e((string) $feat) ?></li>
                    <?php endforeach; ?>
                    <li>All core SBMS modules</li>
                    <li>AI risk assessments</li>
                    <li>Mobile API access</li>
                </ul>
                <form method="post">
                    <input type="hidden" name="plan_id" value="<?= e((string) $plan['id']) ?>">
                    <button type="submit" class="btn-primary" style="width:100%;margin-top:24px">
                        Subscribe — $<?= e($price) ?>
                    </button>
                </form>
            </article>
            <?php endforeach; ?>

            <?php if (empty($plans)): ?>
            <div class="auth-panel" style="padding:32px;text-align:center">
                <p class="lead">No active plans configured yet. Add plans to the <code>plans</code> table to get started.</p>
            </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
