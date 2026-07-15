<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the Experiments CRUD admin page.
 *
 * Architecture:
 *   Write operations (create, update, toggle, delete) are hooked to admin_init,
 *   which fires BEFORE WordPress outputs any HTML. This allows wp_safe_redirect()
 *   to work correctly — headers have not been sent yet.
 *
 *   Render operations (list, edit) run inside the page callback, after WordPress
 *   has already started outputting the admin HTML wrapper.
 *
 * All write operations follow Post → Redirect → Get:
 *   - Prevents "resubmit form?" browser warnings on refresh
 *   - Passes feedback via ?abtf_notice=key&abtf_type=success|error in the URL
 *   - renderNotice() reads those params and prints the message via admin_notices
 */
class ExperimentsPage
{

    private string $menuSlug  = 'abtf-experiments';
    private string $tableName = '';

    public function __construct()
    {
        add_action('admin_menu',    [$this, 'addMenuPage']);
        add_action('admin_init',    [$this, 'handleWrites']);
        add_action('admin_notices', [$this, 'renderNotice']);
    }

    // -------------------------------------------------------------------------
    // Setup
    // -------------------------------------------------------------------------

    private function table(): string
    {
        if ($this->tableName === '') {
            global $wpdb;
            $this->tableName = $wpdb->prefix . 'ab_test_experiments';
        }
        return $this->tableName;
    }

    private function requirePermission(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to perform this action.', 'nofliq-server-side-ab-testing'),
                403
            );
        }
    }

    public function addMenuPage(): void
    {
        add_menu_page(
            'AB Tests — Experiments',
            'AB Tests',
            'manage_options',
            $this->menuSlug,
            [$this, 'renderPage'],
            'dashicons-chart-line',
            30
        );
    }

    // -------------------------------------------------------------------------
    // Notices
    //
    // Write operations redirect with ?abtf_notice=key&abtf_type=success|error.
    // renderNotice() reads those params on the next GET request and prints the
    // message via the admin_notices hook — which fires at the right point in
    // the WordPress lifecycle, before any page content is output.
    // -------------------------------------------------------------------------

    private const NOTICE_MESSAGES = [
        'created'       => 'Experiment created (paused). Click Resume to activate it.',
        'updated'       => 'Experiment updated successfully.',
        'paused'        => 'Experiment paused.',
        'resumed'       => 'Experiment resumed.',
        'deleted'       => 'Experiment deleted.',
        'duplicate_key' => 'That flag key already exists. Please use a different one.',
        'invalid_field' => 'All fields are required and must not exceed 255 characters.',
        'invalid_id'    => 'Invalid experiment ID.',
        'not_found'     => 'Experiment not found.',
        'db_error'      => 'A database error occurred. Please try again.',
    ];

    private function redirectUrl(array $queryArgs, string $noticeKey, string $noticeType = 'success'): string
    {
        return add_query_arg(
            array_merge($queryArgs, [
                'page'        => $this->menuSlug,
                'abtf_notice' => $noticeKey,
                'abtf_type'   => $noticeType,
            ]),
            admin_url('admin.php')
        );
    }

    public function renderNotice(): void
    {
        $screen = get_current_screen();
        if (!$screen || !str_contains($screen->id, $this->menuSlug)) {
            return;
        }

        $key  = sanitize_key(wp_unslash($_GET['abtf_notice'] ?? ''));
        $type = sanitize_key(wp_unslash($_GET['abtf_type']   ?? 'success'));

        if ($key === '' || !isset(self::NOTICE_MESSAGES[$key])) {
            return;
        }

        $type    = in_array($type, ['success', 'error', 'warning'], true) ? $type : 'success';
        $message = self::NOTICE_MESSAGES[$key];

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    // -------------------------------------------------------------------------
    // Write handlers — hooked to admin_init (no HTML output yet, redirects work)
    // -------------------------------------------------------------------------

    /**
     * Entry point for all write operations.
     * Runs on admin_init — before any HTML is sent to the browser.
     * Bails immediately if we are not on our own admin page.
     */
    public function handleWrites(): void
    {
        // Only act on our own page
        if ((isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '') !== $this->menuSlug) {
            return;
        }

        // POST writes
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $postAction = sanitize_key(wp_unslash($_POST['abtf_action'] ?? ''));

            match ($postAction) {
                'create'         => $this->handleCreate(),
                'update'         => $this->handleUpdate(),
                default          => null,
            };
            return;
        }

        // GET writes
        $action = sanitize_key(wp_unslash($_GET['action'] ?? ''));

        match ($action) {
            'toggle' => $this->handleToggle(),
            'delete' => $this->handleDelete(),
            default  => null,
        };
    }

    private function validateFields(array $fields, int $maxLen = 255): bool
    {
        foreach ($fields as $value) {
            $value = trim((string) $value);
            if ($value === '' || mb_strlen($value) > $maxLen) {
                return false;
            }
        }
        return true;
    }

    private function handleCreate(): void
    {
        global $wpdb;

        $this->requirePermission();
        check_admin_referer('abtf_create_experiment');

        $flagKey   = sanitize_text_field(wp_unslash($_POST['flag_key']   ?? ''));
        $name      = sanitize_text_field(wp_unslash($_POST['name']       ?? ''));
        $selector  = sanitize_text_field(wp_unslash($_POST['selector']   ?? ''));
        $eventName = sanitize_text_field(wp_unslash($_POST['event_name'] ?? ''));
        $eventType = sanitize_text_field(wp_unslash($_POST['event_type'] ?? 'click'));
        $urls      = sanitize_textarea_field(wp_unslash($_POST['urls']   ?? ''));

        if (!$this->validateFields([$flagKey, $name, $selector, $eventName, $eventType, $urls])) {
            wp_safe_redirect($this->redirectUrl([], 'invalid_field', 'error'));
            exit;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table()} WHERE flag_key = %s LIMIT 1",
                $flagKey
            )
        );

        if ($exists) {
            wp_safe_redirect($this->redirectUrl([], 'duplicate_key', 'error'));
            exit;
        }

        $result = $wpdb->insert($this->table(), [
            'flag_key'   => $flagKey,
            'name'       => $name,
            'selector'   => $selector,
            'event_name' => $eventName,
            'event_type' => $eventType,
            'urls'       => $urls,
            // New experiments start paused so the configuration (selector, URLs,
            // flag key) can be reviewed before the experiment goes live. Only
            // active experiments are injected and assigned, so a paused start
            // prevents variant assignments from being cached before verification.
            'status'     => 'paused',
        ]);

        if ($result === false) {
            wp_safe_redirect($this->redirectUrl([], 'db_error', 'error'));
            exit;
        }

        wp_safe_redirect($this->redirectUrl([], 'created'));
        exit;
    }

    private function handleUpdate(): void
    {
        global $wpdb;

        $id = intval($_POST['experiment_id'] ?? 0);

        $this->requirePermission();
        check_admin_referer('abtf_update_experiment_' . $id);

        if ($id <= 0) {
            wp_safe_redirect($this->redirectUrl([], 'invalid_id', 'error'));
            exit;
        }

        $name      = sanitize_text_field(wp_unslash($_POST['name']       ?? ''));
        $selector  = sanitize_text_field(wp_unslash($_POST['selector']   ?? ''));
        $eventName = sanitize_text_field(wp_unslash($_POST['event_name'] ?? ''));
        $eventType = sanitize_text_field(wp_unslash($_POST['event_type'] ?? 'click'));
        $urls      = sanitize_textarea_field(wp_unslash($_POST['urls']   ?? ''));

        if (!$this->validateFields([$name, $selector, $eventName, $eventType, $urls])) {
            wp_safe_redirect($this->redirectUrl(['action' => 'edit', 'id' => $id], 'invalid_field', 'error'));
            exit;
        }

        $result = $wpdb->update(
            $this->table(),
            [
                'name'       => $name,
                'selector'   => $selector,
                'event_name' => $eventName,
                'event_type' => $eventType,
                'urls'       => $urls,
            ],
            ['id' => $id]
        );

        if ($result === false) {
            wp_safe_redirect($this->redirectUrl(['action' => 'edit', 'id' => $id], 'db_error', 'error'));
            exit;
        }

        wp_safe_redirect($this->redirectUrl(['action' => 'edit', 'id' => $id], 'updated'));
        exit;
    }

    private function handleToggle(): void
    {
        global $wpdb;

        $id = intval($_GET['id'] ?? 0);

        $this->requirePermission();
        check_admin_referer('abtf_toggle_' . $id);

        if ($id <= 0) {
            wp_safe_redirect($this->redirectUrl([], 'invalid_id', 'error'));
            exit;
        }

        $experiment = $wpdb->get_row(
            $wpdb->prepare("SELECT status FROM {$this->table()} WHERE id = %d", $id)
        );

        if (!$experiment) {
            wp_safe_redirect($this->redirectUrl([], 'not_found', 'error'));
            exit;
        }

        $newStatus = $experiment->status === 'active' ? 'paused' : 'active';
        $noticeKey = $newStatus === 'active' ? 'resumed' : 'paused';

        $wpdb->update($this->table(), ['status' => $newStatus], ['id' => $id]);

        wp_safe_redirect($this->redirectUrl([], $noticeKey));
        exit;
    }

    private function handleDelete(): void
    {
        global $wpdb;

        $id = intval($_GET['id'] ?? 0);

        $this->requirePermission();
        check_admin_referer('abtf_delete_' . $id);

        if ($id <= 0) {
            wp_safe_redirect($this->redirectUrl([], 'invalid_id', 'error'));
            exit;
        }

        $wpdb->delete($this->table(), ['id' => $id]);

        wp_safe_redirect($this->redirectUrl([], 'deleted'));
        exit;
    }

    // -------------------------------------------------------------------------
    // Render — page callback (WordPress has already output the admin header)
    // -------------------------------------------------------------------------

    /**
     * Page callback registered with add_menu_page().
     * Only handles GET renders — all writes have already been handled (or
     * redirected away) by handleWrites() on admin_init.
     */
    public function renderPage(): void
    {
        $action = sanitize_key(wp_unslash($_GET['action'] ?? ''));

        match ($action) {
            'edit'  => $this->renderEdit(intval($_GET['id'] ?? 0)),
            default => $this->renderList(),
        };
    }

    private function renderList(): void
    {
        global $wpdb;

        $experiments = $wpdb->get_results(
            "SELECT * FROM {$this->table()} ORDER BY created_at DESC"
        );

        $addNewUrl = add_query_arg(
            ['page' => $this->menuSlug],
            admin_url('admin.php')
        ) . '#abtf-add-new';

?>
        <div class="wrap">
            <h1 class="wp-heading-inline">AB Tests — Experiments</h1>
            <a href="<?php echo esc_url($addNewUrl); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped table-view-list" style="margin-top: 16px;">
                <thead>
                    <tr>
                        <th style="width: 180px;">Name</th>
                        <th style="width: 160px;">Flag Key</th>
                        <th style="width: 180px;">Selector / Event</th>
                        <th>URLs</th>
                        <th style="width: 90px;">Status</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($experiments)): ?>
                        <tr>
                            <td colspan="6" style="padding: 16px;">No experiments configured yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($experiments as $exp): ?>
                            <?php
                            $expId       = intval($exp->id);
                            $isActive    = $exp->status === 'active';
                            $toggleLabel = $isActive ? 'Pause' : 'Resume';

                            $editUrl = add_query_arg([
                                'page'   => $this->menuSlug,
                                'action' => 'edit',
                                'id'     => $expId,
                            ], admin_url('admin.php'));

                            $toggleUrl = add_query_arg([
                                'page'     => $this->menuSlug,
                                'action'   => 'toggle',
                                'id'       => $expId,
                                '_wpnonce' => wp_create_nonce('abtf_toggle_' . $expId),
                            ], admin_url('admin.php'));

                            $deleteUrl = add_query_arg([
                                'page'     => $this->menuSlug,
                                'action'   => 'delete',
                                'id'       => $expId,
                                '_wpnonce' => wp_create_nonce('abtf_delete_' . $expId),
                            ], admin_url('admin.php'));
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($exp->name); ?></strong></td>
                                <td><code><?php echo esc_html($exp->flag_key); ?></code></td>
                                <td>
                                    <code><?php echo esc_html($exp->selector); ?></code><br>
                                    <small style="color: #666;">
                                        <?php echo esc_html($exp->event_name); ?>
                                        &middot;
                                        <?php echo esc_html($exp->event_type); ?>
                                    </small>
                                </td>
                                <td style="word-break: break-all;"><?php echo esc_html($exp->urls); ?></td>
                                <td><?php echo wp_kses_post($this->statusBadge($exp->status)); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($editUrl); ?>"
                                        class="button button-small">Edit</a>
                                    &nbsp;
                                    <a href="<?php echo esc_url($toggleUrl); ?>"
                                        class="button button-small">
                                        <?php echo esc_html($toggleLabel); ?>
                                    </a>
                                    &nbsp;
                                    <a href="<?php echo esc_url($deleteUrl); ?>"
                                        class="button button-small"
                                        style="color: #a00; border-color: #a00;"
                                        onclick="return confirm('Delete this experiment? This cannot be undone.');">
                                        Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <br>

            <?php $this->renderCreateForm(); ?>

        </div>
    <?php
    }

    private function renderEdit(int $id): void
    {
        global $wpdb;

        if ($id <= 0) {
            $this->renderList();
            return;
        }

        $exp = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id)
        );

        if (!$exp) {
            $this->renderList();
            return;
        }

        $listUrl = add_query_arg(['page' => $this->menuSlug], admin_url('admin.php'));

    ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                Edit Experiment: <?php echo esc_html($exp->name); ?>
            </h1>
            <a href="<?php echo esc_url($listUrl); ?>" class="page-title-action">← Back to list</a>
            <hr class="wp-header-end">

            <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px; margin-top: 16px;">
                <form method="post" action="">
                    <?php wp_nonce_field('abtf_update_experiment_' . intval($exp->id)); ?>
                    <input type="hidden" name="abtf_action" value="update">
                    <input type="hidden" name="experiment_id" value="<?php echo intval($exp->id); ?>">

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label>Flag Key</label></th>
                            <td>
                                <code style="font-size: 14px;"><?php echo esc_html($exp->flag_key); ?></code>
                                <p class="description">Flag key cannot be changed after creation.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_name">Name (Internal)</label></th>
                            <td>
                                <input name="name" type="text" id="edit_name" class="regular-text"
                                    value="<?php echo esc_attr($exp->name); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_selector">CSS Selector</label></th>
                            <td>
                                <input name="selector" type="text" id="edit_selector" class="regular-text"
                                    value="<?php echo esc_attr($exp->selector); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_event_name">Event Name</label></th>
                            <td>
                                <input name="event_name" type="text" id="edit_event_name" class="regular-text"
                                    value="<?php echo esc_attr($exp->event_name); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_event_type">Event Type</label></th>
                            <td>
                                <input name="event_type" type="text" id="edit_event_type" class="regular-text"
                                    value="<?php echo esc_attr($exp->event_type); ?>" required
                                    placeholder="click, scroll, submit, mouseover...">
                                <p class="description">Any valid DOM event type.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_urls">URLs</label></th>
                            <td>
                                <textarea name="urls" id="edit_urls" class="large-text" rows="3"
                                    required><?php echo esc_textarea($exp->urls); ?></textarea>
                                <p class="description">
                                    Comma-separated. Use <code>*</code> for wildcards (e.g., <code>/blog/*</code>).
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Save Changes', 'primary'); ?>
                </form>
            </div>
        </div>
    <?php
    }

    private function renderCreateForm(): void
    {
    ?>
        <div id="abtf-add-new" style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px;">
            <h2 style="margin-top: 0;">Add New Experiment</h2>
            <form method="post" action="">
                <?php wp_nonce_field('abtf_create_experiment'); ?>
                <input type="hidden" name="abtf_action" value="create">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="name">Name (Internal)</label></th>
                        <td>
                            <input name="name" type="text" id="name" class="regular-text"
                                required placeholder="E.g.: CTA Redesign">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="flag_key">Flag Key</label></th>
                        <td>
                            <input name="flag_key" type="text" id="flag_key" class="regular-text"
                                required placeholder="E.g.: cta_version_test">
                            <p class="description">Must exactly match the key in AB Tasty Flagship.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="selector">CSS Selector</label></th>
                        <td>
                            <input name="selector" type="text" id="selector" class="regular-text"
                                required placeholder="E.g.: .main-btn">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="event_name">Event Name</label></th>
                        <td>
                            <input name="event_name" type="text" id="event_name" class="regular-text"
                                required placeholder="E.g.: main_btn_click">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="event_type">Event Type</label></th>
                        <td>
                            <input name="event_type" type="text" id="event_type" class="regular-text"
                                required placeholder="click, scroll, submit, mouseover..."
                                value="click">
                            <p class="description">Any valid DOM event type.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="urls">URLs</label></th>
                        <td>
                            <textarea name="urls" id="urls" class="large-text" rows="3"
                                required placeholder="/, /talent/*"></textarea>
                            <p class="description">
                                Comma-separated. Use <code>*</code> for wildcards (e.g., <code>/blog/*</code>).
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Create Experiment', 'primary'); ?>
            </form>
        </div>
<?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function statusBadge(string $status): string
    {
        $styles = [
            'active' => 'background: #b8e6bf; color: #125222;',
            'paused' => 'background: #f0e0a0; color: #5a4000;',
        ];

        $style = $styles[$status] ?? 'background: #eee; color: #333;';

        return sprintf(
            '<span style="%s padding: 3px 8px; border-radius: 10px; font-size: 12px;">%s</span>',
            esc_attr($style),
            esc_html($status)
        );
    }
}