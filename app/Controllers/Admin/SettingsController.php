<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Settings;
use App\Models\ActivityLog;
use App\Models\DocumentTemplate;
use App\Models\EmailLog;
use App\Services\MailService;
use App\Services\OpenRouterService;
use App\Services\PayUService;
use App\Services\UploadService;

final class SettingsController extends Controller
{
    /* ================================================================== */
    /* AI settings                                                         */
    /* ================================================================== */

    public function ai(Request $request): void
    {
        $ai = new OpenRouterService();

        $this->view('admin.settings.ai', [
            'title' => 'AI settings',
            'config' => $ai->config(),
            'enabled' => Settings::bool('ai_enabled', true),
            'configured' => $ai->isConfigured(),
            'models' => $ai->suggestedModels(),
            'stats' => (new \App\Models\AiGeneration())->statistics(),
        ], 'layouts.admin');
    }

    public function saveAi(Request $request): void
    {
        $data = $this->validate($request, [
            'openrouter_model' => 'required|max:120',
            'openrouter_base_url' => 'required|url|max:190',
            'ai_temperature' => 'required|numeric|between:0,2',
            'ai_max_tokens' => 'required|integer|between:100,32000',
        ], [], '/admin/ai');

        $apiKey = (string) $request->input('openrouter_api_key', '');

        // An empty field means "keep the stored key".
        if ($apiKey !== '' && !str_starts_with($apiKey, '••')) {
            Settings::set('openrouter_api_key', $apiKey, 'ai');
        }

        Settings::setMany([
            'openrouter_model' => (string) $data['openrouter_model'],
            'openrouter_base_url' => rtrim((string) $data['openrouter_base_url'], '/'),
            'ai_temperature' => (string) $data['ai_temperature'],
            'ai_max_tokens' => (string) $data['ai_max_tokens'],
            'ai_enabled' => $request->boolean('ai_enabled') ? '1' : '0',
        ], 'ai');

        ActivityLog::record(Auth::id(), 'admin.ai_settings_saved');
        $this->success('AI settings saved.');
        $this->redirect('/admin/ai');
    }

    public function testAi(Request $request): void
    {
        $key = (string) $request->input('openrouter_api_key', '');
        $model = (string) $request->input('openrouter_model', '');

        $result = (new OpenRouterService())->testConnection(
            str_starts_with($key, '••') ? null : ($key !== '' ? $key : null),
            $model !== '' ? $model : null
        );

        if ($request->isAjax()) {
            $this->json($result, $result['success'] ? 200 : 502);

            return;
        }

        if ($result['success']) {
            $this->success(sprintf(
                'OpenRouter connection OK (%s, %dms). Reply: %s',
                (string) ($result['model'] ?? ''),
                (int) ($result['latency_ms'] ?? 0),
                (string) ($result['reply'] ?? '')
            ));
        } else {
            $this->error('OpenRouter test failed: ' . $result['message']);
        }

        $this->redirect('/admin/ai');
    }

    /* ================================================================== */
    /* Email settings                                                      */
    /* ================================================================== */

    public function email(Request $request): void
    {
        $mail = new MailService();

        $this->view('admin.settings.email', [
            'title' => 'Email settings',
            'config' => $mail->config(),
            'configured' => $mail->isConfigured(),
            'available' => $mail->isAvailable(),
            'logs' => (new EmailLog())->recent(15),
            'stats' => (new EmailLog())->statistics(),
        ], 'layouts.admin');
    }

    public function saveEmail(Request $request): void
    {
        $data = $this->validate($request, [
            'smtp_host' => 'nullable|max:190',
            'smtp_port' => 'required|integer|between:1,65535',
            'smtp_username' => 'nullable|max:190',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'smtp_from_email' => 'nullable|email|max:190',
            'smtp_from_name' => 'required|max:120',
        ], [], '/admin/email');

        $password = (string) $request->input('smtp_password', '');

        if ($password !== '' && !str_starts_with($password, '••')) {
            Settings::set('smtp_password', $password, 'email');
        }

        Settings::setMany([
            'smtp_host' => (string) ($data['smtp_host'] ?? ''),
            'smtp_port' => (string) $data['smtp_port'],
            'smtp_username' => (string) ($data['smtp_username'] ?? ''),
            'smtp_encryption' => (string) $data['smtp_encryption'],
            'smtp_from_email' => (string) ($data['smtp_from_email'] ?? ''),
            'smtp_from_name' => (string) $data['smtp_from_name'],
        ], 'email');

        ActivityLog::record(Auth::id(), 'admin.email_settings_saved');
        $this->success('Email settings saved.');
        $this->redirect('/admin/email');
    }

    public function testEmail(Request $request): void
    {
        $data = $this->validate($request, ['test_email' => 'required|email'], [], '/admin/email');

        $result = (new MailService())->sendTest((string) $data['test_email'], Auth::id());

        if ($result['success']) {
            $this->success('Test email sent to ' . (string) $data['test_email'] . '.');
        } else {
            $this->error('Test email failed: ' . $result['message']);
        }

        $this->redirect('/admin/email');
    }

    /* ================================================================== */
    /* PayU settings                                                       */
    /* ================================================================== */

    public function payu(Request $request): void
    {
        $payu = new PayUService();

        $this->view('admin.settings.payu', [
            'title' => 'PayU settings',
            'config' => $payu->config(),
            'configured' => $payu->isConfigured(),
            'success_url' => url('billing/payu/success'),
            'failure_url' => url('billing/payu/failure'),
        ], 'layouts.admin');
    }

    public function savePayu(Request $request): void
    {
        $data = $this->validate($request, [
            'payu_mode' => 'required|in:test,live',
            'payu_merchant_key' => 'nullable|max:120',
            'payu_base_url' => 'nullable|max:190',
        ], [], '/admin/payu');

        $salt = (string) $request->input('payu_merchant_salt', '');

        if ($salt !== '' && !str_starts_with($salt, '••')) {
            Settings::set('payu_merchant_salt', $salt, 'payu');
        }

        $key = (string) ($data['payu_merchant_key'] ?? '');
        if ($key !== '' && !str_starts_with($key, '••')) {
            Settings::set('payu_merchant_key', $key, 'payu');
        }

        $mode = (string) $data['payu_mode'];
        $baseUrl = trim((string) ($data['payu_base_url'] ?? ''));

        if ($baseUrl === '') {
            $baseUrl = $mode === 'live' ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment';
        }

        Settings::setMany([
            'payu_mode' => $mode,
            'payu_base_url' => $baseUrl,
        ], 'payu');

        ActivityLog::record(Auth::id(), 'admin.payu_settings_saved', $mode);
        $this->success('PayU settings saved (' . $mode . ' mode).');
        $this->redirect('/admin/payu');
    }

    /* ================================================================== */
    /* Templates                                                           */
    /* ================================================================== */

    public function templates(Request $request): void
    {
        $this->view('admin.settings.templates', [
            'title' => 'Document templates',
            'templates' => (new DocumentTemplate())->allOrdered(),
        ], 'layouts.admin');
    }

    public function toggleTemplate(Request $request): void
    {
        $templates = new DocumentTemplate();
        $template = $templates->find($request->paramInt('id'));

        if ($template === null) {
            $this->error('Template not found.');
            $this->redirect('/admin/templates');

            return;
        }

        if ((int) $template['is_default'] === 1 && (int) $template['is_active'] === 1) {
            $this->error('Choose a different default template before deactivating this one.');
            $this->redirect('/admin/templates');

            return;
        }

        $templates->updateById((int) $template['id'], ['is_active' => (int) $template['is_active'] === 1 ? 0 : 1]);

        $this->success('Template updated.');
        $this->redirect('/admin/templates');
    }

    public function defaultTemplate(Request $request): void
    {
        $templates = new DocumentTemplate();
        $template = $templates->find($request->paramInt('id'));

        if ($template === null) {
            $this->error('Template not found.');
            $this->redirect('/admin/templates');

            return;
        }

        $templates->makeDefault((int) $template['id']);
        Settings::set('default_template', (string) $template['slug'], 'system');

        $this->success((string) $template['name'] . ' is now the default template.');
        $this->redirect('/admin/templates');
    }

    /* ================================================================== */
    /* System settings                                                     */
    /* ================================================================== */

    public function system(Request $request): void
    {
        $this->view('admin.settings.system', [
            'title' => 'System settings',
            'values' => [
                'site_name' => Settings::string('site_name', 'DocuPilot AI'),
                'site_logo' => Settings::string('site_logo'),
                'contact_email' => Settings::string('contact_email'),
                'default_currency' => Settings::string('default_currency', 'INR'),
                'registration_enabled' => Settings::bool('registration_enabled', true),
                'require_email_verification' => Settings::bool('require_email_verification', false),
                'ai_enabled' => Settings::bool('ai_enabled', true),
                'maintenance_mode' => Settings::bool('maintenance_mode', false),
            ],
            'logo_url' => Settings::string('site_logo') === ''
                ? null
                : url('media/logo/' . Settings::string('site_logo')),
            'app_url' => (string) config('app.url'),
            'detected_url' => base_url(),
        ], 'layouts.admin');
    }

    public function saveSystem(Request $request): void
    {
        $data = $this->validate($request, [
            'site_name' => 'required|max:80',
            'contact_email' => 'nullable|email|max:190',
            'default_currency' => 'required|max:3',
        ], [], '/admin/settings');

        $currency = strtoupper((string) $data['default_currency']);

        $file = $request->file('site_logo');

        if ($file !== null) {
            $uploads = new UploadService();
            $result = $uploads->storeLogo($file);

            if (!$result['success']) {
                $this->error($result['error'] ?? 'The logo could not be uploaded.');
                $this->redirect('/admin/settings');

                return;
            }

            $previous = Settings::string('site_logo');
            if ($previous !== '') {
                $uploads->delete($previous);
            }

            Settings::set('site_logo', $result['filename'], 'system');
        }

        Settings::setMany([
            'site_name' => (string) $data['site_name'],
            'contact_email' => (string) ($data['contact_email'] ?? ''),
            'default_currency' => array_key_exists($currency, currencies()) ? $currency : 'INR',
            'registration_enabled' => $request->boolean('registration_enabled') ? '1' : '0',
            'require_email_verification' => $request->boolean('require_email_verification') ? '1' : '0',
            'ai_enabled' => $request->boolean('ai_enabled') ? '1' : '0',
            'maintenance_mode' => $request->boolean('maintenance_mode') ? '1' : '0',
        ], 'system');

        ActivityLog::record(Auth::id(), 'admin.system_settings_saved');
        $this->success('System settings saved.');
        $this->redirect('/admin/settings');
    }

    public function deleteSiteLogo(Request $request): void
    {
        $logo = Settings::string('site_logo');

        if ($logo !== '') {
            (new UploadService())->delete($logo);
            Settings::set('site_logo', '', 'system');
        }

        $this->success('Site logo removed.');
        $this->redirect('/admin/settings');
    }
}
