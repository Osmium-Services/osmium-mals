<?php

declare(strict_types=1);

namespace Osmium\Services\Mals\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Mals\Models\MalsCart;

/**
 * Mals cart settings - full-page form POST/redirect, same shape as the Turnstile settings.
 *
 * Routes:
 *   - index() → /admin/settings/mals/  (GET shows the form, POST saves it)
 */
class MalsController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/mals.json.php';

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $this->data['admin']['config']['mals'] = (array) MalsCart::get();
        $this->data['admin']['settingsSaved'] = $_SESSION['mals_settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['mals_settings_error'] ?? false;
        unset($_SESSION['mals_settings_saved'], $_SESSION['mals_settings_error']);

        $this->setView('mals/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) {
            $_SESSION['mals_settings_error'] = 'Invalid form submission. Please try again.';
            $this->redirect('settings/mals/');
        }

        $enabled = isset($_POST['enabled']);
        $cartUserId = \trim($_POST['cart_user_id'] ?? '');
        $addToCartUrl = \trim($_POST['add_to_cart_url'] ?? '');
        $checkoutUrl = \trim($_POST['checkout_url'] ?? '');
        $hideZeroItems = isset($_POST['hide_zero_items']);

        $error = $this->validate($enabled, $cartUserId, $addToCartUrl, $checkoutUrl);
        if ($error !== null) {
            $_SESSION['mals_settings_error'] = $error;
            $this->redirect('settings/mals/');
        }

        $this->saveConfig($enabled, $cartUserId, $addToCartUrl, $checkoutUrl, $hideZeroItems);

        $this->admin->model->changelog->log(description: 'Updated Mals cart settings', recordType: 'settings');

        MalsCart::clearCache();

        $_SESSION['mals_settings_saved'] = true;
        $this->redirect('settings/mals/');
    }

    private function validate(bool $enabled, string $cartUserId, string $addToCartUrl, string $checkoutUrl): ?string
    {
        if (!$enabled) return null;

        if ($cartUserId === '') return 'Enter the cart user ID from your Mals account';
        if (!\str_starts_with($addToCartUrl, 'https://')) return 'The add-to-cart URL must start with https://';
        if (!\str_starts_with($checkoutUrl, 'https://')) return 'The checkout URL must start with https://';
        if (!\str_contains($checkoutUrl, '{CARTUSERID}')) return 'The checkout URL must contain {CARTUSERID}';

        return null;
    }

    private function saveConfig(bool $enabled, string $cartUserId, string $addToCartUrl, string $checkoutUrl, bool $hideZeroItems): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        if (!\is_dir($dir)) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['mals' => [
                'enabled' => $enabled,
                'cartUserID' => $cartUserId,
                'addToCartURL' => $addToCartUrl,
                'checkoutURL' => $checkoutUrl,
                'hideZeroItems' => $hideZeroItems,
            ]],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, "<?php exit(); ?>\n" . $json . "\n");
    }
}
