<?php
/**
 * Pro per-form field mapping settings.
 *
 * @package CF7_To_HighLevel_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pro Form Settings class.
 *
 * Adds per-form field mapping UI to the CF7 editor's HighLevel tab.
 * Provides dynamic dropdowns for both CF7 fields and HighLevel fields.
 */
class CF7_To_GHL_Pro_Form_Settings {

    /**
     * Single instance.
     *
     * @var CF7_To_GHL_Pro_Form_Settings
     */
    private static $instance = null;

    /**
     * Meta key for storing per-form field mapping.
     *
     * @var string
     */
    private $meta_key = '_cf7_to_ghl_pro_field_mapping';

    /**
     * Get the single instance.
     *
     * @return CF7_To_GHL_Pro_Form_Settings
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'cf7_to_ghl_form_panel', array( $this, 'render_field_mapping_panel' ) );
        add_action( 'wpcf7_save_contact_form', array( $this, 'save_field_mapping' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_cf7_ghl_pro_refresh_fields', array( $this, 'ajax_refresh_ghl_fields' ) );

        // Hide the free plugin's basic field mapping UI — Pro replaces it.
        add_filter( 'cf7_to_ghl_show_free_mapping', '__return_false' );
    }

    /**
     * Get standard HighLevel fields grouped by category.
     *
     * @return array Grouped GHL standard fields.
     */
    public static function get_standard_ghl_fields() {
        return array(
            'Name'    => array(
                'full_name' => 'Full Name (auto-split into first/last)',
                'firstName' => 'First Name',
                'lastName'  => 'Last Name',
            ),
            'Contact' => array(
                'email'       => 'Email',
                'phone'       => 'Phone',
                'companyName' => 'Company Name',
                'website'     => 'Website',
            ),
            'Address' => array(
                'address1'   => 'Address',
                'city'       => 'City',
                'state'      => 'State',
                'postalCode' => 'Postal Code',
                'country'    => 'Country',
            ),
            'Message' => array(
                'message'              => 'Message (saved as custom field)',
                'conversation_message' => 'Message (sent as conversation)',
            ),
            'Other'   => array(
                'source'      => 'Lead Source',
                'tags'        => 'Tags (comma-separated)',
                'gender'      => 'Gender',
                'dateOfBirth' => 'Date of Birth',
                'timezone'    => 'Timezone',
                'assignedTo'  => 'Assigned To (GHL User ID)',
                'dnd'         => 'Do Not Disturb',
            ),
        );
    }

    /**
     * Get all GHL fields including custom fields from the API.
     *
     * Merges standard fields with dynamically fetched custom fields.
     *
     * @return array Grouped GHL fields.
     */
    public static function get_ghl_fields() {
        $fields = self::get_standard_ghl_fields();

        $custom_fields = self::fetch_ghl_custom_fields();

        if ( ! empty( $custom_fields ) ) {
            $custom_group = array();
            foreach ( $custom_fields as $field ) {
                $key  = isset( $field['fieldKey'] ) ? $field['fieldKey'] : '';
                $name = isset( $field['name'] ) ? $field['name'] : $key;
                if ( ! empty( $key ) ) {
                    $custom_group[ '__api_custom__' . $key ] = $name;
                }
            }
            if ( ! empty( $custom_group ) ) {
                $fields['Custom Fields (from HighLevel)'] = $custom_group;
            }
        }

        return $fields;
    }

    /**
     * Fetch custom fields from the GHL API with transient caching.
     *
     * @param bool $force_refresh Whether to bypass the cache.
     * @return array Array of custom field objects, or empty array on failure.
     */
    public static function fetch_ghl_custom_fields( $force_refresh = false ) {
        $transient_key = 'cf7_to_ghl_pro_custom_fields';

        if ( ! $force_refresh ) {
            $cached = get_transient( $transient_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $api_token   = CF7_To_GHL_Settings::get_setting( 'api_token' );
        $location_id = CF7_To_GHL_Settings::get_setting( 'location_id' );

        if ( empty( $api_token ) || empty( $location_id ) ) {
            return array();
        }

        $response = wp_remote_get(
            'https://services.leadconnectorhq.com/locations/' . rawurlencode( $location_id ) . '/customFields',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_token,
                    'Version'       => '2021-07-28',
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || empty( $body ) ) {
            return array();
        }

        $fields = isset( $body['customFields'] ) ? $body['customFields'] : array();

        set_transient( $transient_key, $fields, HOUR_IN_SECONDS );

        return $fields;
    }

    /**
     * AJAX handler for refreshing GHL custom fields.
     */
    public function ajax_refresh_ghl_fields() {
        check_ajax_referer( 'cf7_ghl_pro_refresh_fields', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $api_token   = CF7_To_GHL_Settings::get_setting( 'api_token' );
        $location_id = CF7_To_GHL_Settings::get_setting( 'location_id' );

        if ( empty( $api_token ) || empty( $location_id ) ) {
            wp_send_json_error( array(
                'message' => 'API token or Location ID not configured. Go to Contact > HighLevel to set them up.',
            ) );
        }

        $custom_fields = self::fetch_ghl_custom_fields( true );
        $all_fields    = self::get_ghl_fields();
        $options_html  = self::build_ghl_options_html( $all_fields );

        $message = count( $custom_fields ) > 0
            ? sprintf(
                /* translators: %d: number of custom fields */
                __( '%d custom field(s) loaded from HighLevel.', 'cf7-to-highlevel-pro' ),
                count( $custom_fields )
            )
            : __( 'No custom fields found. You may need to enable the Custom Fields scope in your HighLevel Private Integration.', 'cf7-to-highlevel-pro' );

        wp_send_json_success( array(
            'options_html' => $options_html,
            'custom_count' => count( $custom_fields ),
            'message'      => $message,
        ) );
    }

    /**
     * Build GHL dropdown options HTML.
     *
     * @param array|null $ghl_fields Grouped GHL fields array, or null to fetch.
     * @param string     $selected   Currently selected value.
     * @return string Options HTML.
     */
    public static function build_ghl_options_html( $ghl_fields = null, $selected = '' ) {
        if ( null === $ghl_fields ) {
            $ghl_fields = self::get_ghl_fields();
        }

        $html = '<option value="">' . esc_html__( '-- Select HighLevel Field --', 'cf7-to-highlevel-pro' ) . '</option>';

        foreach ( $ghl_fields as $group_label => $fields ) {
            $html .= '<optgroup label="' . esc_attr( $group_label ) . '">';
            foreach ( $fields as $value => $label ) {
                $sel   = selected( $selected, $value, false );
                $html .= '<option value="' . esc_attr( $value ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
            }
            $html .= '</optgroup>';
        }

        $html .= '<optgroup label="' . esc_attr__( 'Custom', 'cf7-to-highlevel-pro' ) . '">';
        $sel   = selected( $selected, '__custom__', false );
        $html .= '<option value="__custom__"' . $sel . '>' . esc_html__( 'Custom Field (enter key manually)', 'cf7-to-highlevel-pro' ) . '</option>';
        $html .= '</optgroup>';

        return $html;
    }

    /**
     * Get CF7 form fields using scan_form_tags().
     *
     * @param WPCF7_ContactForm $contact_form The contact form object.
     * @return array Array of field names.
     */
    public static function get_cf7_form_fields( $contact_form ) {
        $tags   = $contact_form->scan_form_tags();
        $fields = array();

        $input_basetypes = array(
            'text', 'email', 'tel', 'url', 'number', 'date',
            'textarea', 'select', 'checkbox', 'radio', 'hidden',
            'file', 'quiz',
        );

        foreach ( $tags as $tag ) {
            if ( in_array( $tag->basetype, $input_basetypes, true ) && ! empty( $tag->name ) ) {
                $fields[] = $tag->name;
            }
        }

        return array_unique( $fields );
    }

    /**
     * Enqueue styles on CF7 form editor pages.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        if ( ! in_array( $hook, array( 'toplevel_page_wpcf7', 'contact_page_wpcf7-new' ), true ) ) {
            $screen = get_current_screen();
            if ( ! $screen || 'toplevel_page_wpcf7' !== $screen->id ) {
                return;
            }
        }

        wp_add_inline_style( 'common', '
            .cf7-ghl-pro-mapping { margin-top: 20px; }
            .cf7-ghl-pro-mapping-table { width: 100%; border-collapse: collapse; }
            .cf7-ghl-pro-mapping-table th { text-align: left; padding: 8px; background: #f6f7f7; border: 1px solid #ccd0d4; }
            .cf7-ghl-pro-mapping-table td { padding: 8px; border: 1px solid #ccd0d4; vertical-align: middle; }
            .cf7-ghl-pro-mapping-table input[type="text"],
            .cf7-ghl-pro-mapping-table select { width: 100%; }
            .cf7-ghl-pro-mapping-table .cf7-ghl-pro-custom-key { margin-top: 5px; }
            .cf7-ghl-pro-mapping-table .cf7-ghl-pro-cf7-manual { margin-top: 5px; }
            .cf7-ghl-pro-actions { text-align: center; width: 60px; }
            .cf7-ghl-pro-remove-row { color: #dc3232; cursor: pointer; font-size: 18px; text-decoration: none; }
            .cf7-ghl-pro-remove-row:hover { color: #a00; }
            .cf7-ghl-pro-buttons { margin-top: 10px; }
            .cf7-ghl-pro-custom-key-hint { font-size: 11px; color: #666; margin-top: 3px; font-style: italic; }
            .cf7-ghl-pro-guide { margin-top: 15px; padding: 12px 15px; background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #0073aa; border-radius: 4px; }
            .cf7-ghl-pro-guide h4 { margin: 0 0 8px; font-size: 13px; }
            .cf7-ghl-pro-guide ol { margin: 0; padding-left: 20px; }
            .cf7-ghl-pro-guide ol li { margin-bottom: 4px; font-size: 12px; color: #444; }
            .cf7-ghl-pro-guide code { background: #f0f0f0; padding: 1px 5px; border-radius: 3px; font-size: 11px; }
            .cf7-ghl-pro-refresh-status { margin-left: 10px; font-style: italic; color: #666; font-size: 12px; }
        ' );
    }

    /**
     * Render the field mapping panel in CF7 editor.
     *
     * @param WPCF7_ContactForm $contact_form The contact form object.
     */
    public function render_field_mapping_panel( $contact_form ) {
        $form_id  = $contact_form->id();
        $mappings = get_post_meta( $form_id, $this->meta_key, true );

        if ( ! is_array( $mappings ) ) {
            $mappings = array();
        }

        // If no Pro mapping exists, pre-populate from free plugin's mappings.
        if ( empty( $mappings ) ) {
            $mappings = $this->get_free_mappings_as_pro_format( $form_id );
        }

        $ghl_fields = self::get_ghl_fields();
        $cf7_fields = self::get_cf7_form_fields( $contact_form );

        // Build options HTML for JS dynamic rows (no selection).
        $ghl_options_html = self::build_ghl_options_html( $ghl_fields );

        // Count cached custom fields.
        $custom_field_count = 0;
        $cached = get_transient( 'cf7_to_ghl_pro_custom_fields' );
        if ( is_array( $cached ) ) {
            $custom_field_count = count( $cached );
        }

        $refresh_nonce = wp_create_nonce( 'cf7_ghl_pro_refresh_fields' );
        ?>
        <div class="cf7-ghl-pro-mapping">
            <h3><?php esc_html_e( 'Field Mapping (Pro)', 'cf7-to-highlevel-pro' ); ?></h3>
            <p class="description">
                <?php esc_html_e( 'Map each CF7 form field to a HighLevel contact field. CF7 fields are auto-detected from your saved form template.', 'cf7-to-highlevel-pro' ); ?>
            </p>

            <table class="cf7-ghl-pro-mapping-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'CF7 Field', 'cf7-to-highlevel-pro' ); ?></th>
                        <th><?php esc_html_e( 'HighLevel Field', 'cf7-to-highlevel-pro' ); ?></th>
                        <th class="cf7-ghl-pro-actions"><?php esc_html_e( 'Remove', 'cf7-to-highlevel-pro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ( ! empty( $mappings ) ) :
                        foreach ( $mappings as $index => $row ) :
                            $current_cf7        = isset( $row['cf7_field'] ) ? $row['cf7_field'] : '';
                            $current_ghl        = isset( $row['ghl_field'] ) ? $row['ghl_field'] : '';
                            $current_custom_key = isset( $row['custom_key'] ) ? $row['custom_key'] : '';

                            // CF7 field: detected → select it; otherwise → "Other" + manual input.
                            $cf7_in_list   = in_array( $current_cf7, $cf7_fields, true );
                            $cf7_select    = ( $cf7_in_list || empty( $current_cf7 ) ) ? $current_cf7 : '__other__';
                            $cf7_manual    = $cf7_in_list ? '' : $current_cf7;
                            $show_manual   = ( ! empty( $current_cf7 ) && ! $cf7_in_list );

                            // GHL field: if __api_custom__ value not in dropdown, fall back to __custom__.
                            if ( 0 === strpos( $current_ghl, '__api_custom__' ) ) {
                                $ghl_in_options = false;
                                foreach ( $ghl_fields as $group => $group_fields ) {
                                    if ( isset( $group_fields[ $current_ghl ] ) ) {
                                        $ghl_in_options = true;
                                        break;
                                    }
                                }
                                if ( ! $ghl_in_options ) {
                                    $current_custom_key = substr( $current_ghl, strlen( '__api_custom__' ) );
                                    $current_ghl        = '__custom__';
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <select name="cf7_to_ghl_pro_mapping[<?php echo esc_attr( $index ); ?>][cf7_field_select]"
                                            class="cf7-ghl-pro-cf7-select">
                                        <option value=""><?php esc_html_e( '-- Select CF7 Field --', 'cf7-to-highlevel-pro' ); ?></option>
                                        <?php foreach ( $cf7_fields as $field ) : ?>
                                            <option value="<?php echo esc_attr( $field ); ?>"
                                                    <?php selected( $cf7_select, $field ); ?>>
                                                <?php echo esc_html( $field ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="__other__" <?php selected( $cf7_select, '__other__' ); ?>>
                                            <?php esc_html_e( 'Other (manual entry)', 'cf7-to-highlevel-pro' ); ?>
                                        </option>
                                    </select>
                                    <input type="text"
                                           name="cf7_to_ghl_pro_mapping[<?php echo esc_attr( $index ); ?>][cf7_field_manual]"
                                           class="cf7-ghl-pro-cf7-manual"
                                           value="<?php echo esc_attr( $cf7_manual ); ?>"
                                           placeholder="<?php esc_attr_e( 'Enter field name', 'cf7-to-highlevel-pro' ); ?>"
                                           style="<?php echo $show_manual ? '' : 'display:none;'; ?>" />
                                </td>
                                <td>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_ghl_options_html escapes internally. ?>
                                    <select name="cf7_to_ghl_pro_mapping[<?php echo esc_attr( $index ); ?>][ghl_field]"
                                            class="cf7-ghl-pro-ghl-select">
                                        <?php echo self::build_ghl_options_html( $ghl_fields, $current_ghl ); ?>
                                    </select>
                                    <input type="text"
                                           name="cf7_to_ghl_pro_mapping[<?php echo esc_attr( $index ); ?>][custom_key]"
                                           class="cf7-ghl-pro-custom-key"
                                           value="<?php echo esc_attr( $current_custom_key ); ?>"
                                           placeholder="<?php esc_attr_e( 'e.g. contact.budget_range', 'cf7-to-highlevel-pro' ); ?>"
                                           style="<?php echo ( '__custom__' === $current_ghl ) ? '' : 'display:none;'; ?>" />
                                    <p class="cf7-ghl-pro-custom-key-hint" style="<?php echo ( '__custom__' === $current_ghl ) ? '' : 'display:none;'; ?>">
                                        <?php esc_html_e( 'Enter the field key from HighLevel > Settings > Custom Fields', 'cf7-to-highlevel-pro' ); ?>
                                    </p>
                                </td>
                                <td class="cf7-ghl-pro-actions">
                                    <a href="#" class="cf7-ghl-pro-remove-row" title="<?php esc_attr_e( 'Remove', 'cf7-to-highlevel-pro' ); ?>">&times;</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="cf7-ghl-pro-buttons">
                <button type="button" id="cf7-ghl-pro-add-row" class="button">
                    <?php esc_html_e( 'Add Mapping Row', 'cf7-to-highlevel-pro' ); ?>
                </button>
                <button type="button" id="cf7-ghl-pro-refresh-fields" class="button">
                    <?php esc_html_e( 'Refresh HighLevel Fields', 'cf7-to-highlevel-pro' ); ?>
                </button>
                <?php if ( $custom_field_count > 0 ) : ?>
                    <span class="cf7-ghl-pro-refresh-status">
                        <?php
                        printf(
                            /* translators: %d: number of custom fields */
                            esc_html__( '%d custom field(s) loaded from HighLevel', 'cf7-to-highlevel-pro' ),
                            $custom_field_count
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( empty( $cf7_fields ) ) : ?>
                <p class="description" style="margin-top: 10px; color: #d63638;">
                    <?php esc_html_e( 'No CF7 fields detected. Save your form template first, then the field dropdown will populate automatically.', 'cf7-to-highlevel-pro' ); ?>
                </p>
            <?php endif; ?>

            <div class="cf7-ghl-pro-guide">
                <h4><?php esc_html_e( 'How to use', 'cf7-to-highlevel-pro' ); ?></h4>
                <ol>
                    <li><?php esc_html_e( 'CF7 fields are auto-detected from your saved form template. If you just added fields, save the form first.', 'cf7-to-highlevel-pro' ); ?></li>
                    <li><?php esc_html_e( 'Click "Add Mapping Row" to create a new mapping.', 'cf7-to-highlevel-pro' ); ?></li>
                    <li><?php esc_html_e( 'Select the CF7 field and the corresponding HighLevel field.', 'cf7-to-highlevel-pro' ); ?></li>
                    <li><?php esc_html_e( 'Click "Refresh HighLevel Fields" to load your custom fields from the HighLevel API.', 'cf7-to-highlevel-pro' ); ?></li>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: example key */
                            esc_html__( 'For manual custom fields, select "Custom Field (enter key manually)" and enter the key (e.g. %s).', 'cf7-to-highlevel-pro' ),
                            '<code>contact.budget_range</code>'
                        );
                        ?>
                    </li>
                </ol>
            </div>
        </div>

        <?php $this->render_inline_js( $cf7_fields, $ghl_options_html, $refresh_nonce ); ?>
        <?php
    }

    /**
     * Output inline JavaScript for the field mapping panel.
     *
     * @param array  $cf7_fields       Array of detected CF7 field names.
     * @param string $ghl_options_html GHL dropdown options HTML (no selection).
     * @param string $refresh_nonce    Nonce for the AJAX refresh action.
     */
    private function render_inline_js( $cf7_fields, $ghl_options_html, $refresh_nonce ) {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var ghlOptionsHtml = <?php echo wp_json_encode( $ghl_options_html ); ?>;
            var cf7Fields = <?php echo wp_json_encode( array_values( $cf7_fields ) ); ?>;
            var refreshNonce = <?php echo wp_json_encode( $refresh_nonce ); ?>;

            function buildCf7Options() {
                var html = '<option value=""><?php echo esc_js( __( '-- Select CF7 Field --', 'cf7-to-highlevel-pro' ) ); ?></option>';
                cf7Fields.forEach(function(f) {
                    html += '<option value="' + f + '">' + f + '</option>';
                });
                html += '<option value="__other__"><?php echo esc_js( __( 'Other (manual entry)', 'cf7-to-highlevel-pro' ) ); ?></option>';
                return html;
            }

            // Add new mapping row.
            $(document).on('click', '#cf7-ghl-pro-add-row', function(e) {
                e.preventDefault();
                var index = $('.cf7-ghl-pro-mapping-table tbody tr').length;
                var row = '<tr>' +
                    '<td>' +
                    '<select name="cf7_to_ghl_pro_mapping[' + index + '][cf7_field_select]" class="cf7-ghl-pro-cf7-select">' + buildCf7Options() + '</select>' +
                    '<input type="text" name="cf7_to_ghl_pro_mapping[' + index + '][cf7_field_manual]" class="cf7-ghl-pro-cf7-manual" placeholder="<?php echo esc_attr__( 'Enter field name', 'cf7-to-highlevel-pro' ); ?>" style="display:none;" />' +
                    '</td>' +
                    '<td>' +
                    '<select name="cf7_to_ghl_pro_mapping[' + index + '][ghl_field]" class="cf7-ghl-pro-ghl-select">' + ghlOptionsHtml + '</select>' +
                    '<input type="text" name="cf7_to_ghl_pro_mapping[' + index + '][custom_key]" class="cf7-ghl-pro-custom-key" placeholder="<?php echo esc_attr__( 'e.g. contact.budget_range', 'cf7-to-highlevel-pro' ); ?>" style="display:none;" />' +
                    '<p class="cf7-ghl-pro-custom-key-hint" style="display:none;"><?php echo esc_js( __( 'Enter the field key from HighLevel > Settings > Custom Fields', 'cf7-to-highlevel-pro' ) ); ?></p>' +
                    '</td>' +
                    '<td class="cf7-ghl-pro-actions"><a href="#" class="cf7-ghl-pro-remove-row" title="<?php echo esc_attr__( 'Remove', 'cf7-to-highlevel-pro' ); ?>">&times;</a></td>' +
                    '</tr>';
                $('.cf7-ghl-pro-mapping-table tbody').append(row);
            });

            // Remove mapping row.
            $(document).on('click', '.cf7-ghl-pro-remove-row', function(e) {
                e.preventDefault();
                $(this).closest('tr').remove();
            });

            // CF7 field "Other" toggle.
            $(document).on('change', '.cf7-ghl-pro-cf7-select', function() {
                var manual = $(this).siblings('.cf7-ghl-pro-cf7-manual');
                if ($(this).val() === '__other__') {
                    manual.show().focus();
                } else {
                    manual.hide().val('');
                }
            });

            // GHL field custom key toggle.
            $(document).on('change', '.cf7-ghl-pro-ghl-select', function() {
                var customInput = $(this).siblings('.cf7-ghl-pro-custom-key');
                var customHint = $(this).siblings('.cf7-ghl-pro-custom-key-hint');
                if ($(this).val() === '__custom__') {
                    customInput.show().focus();
                    customHint.show();
                } else {
                    customInput.hide().val('');
                    customHint.hide();
                }
            });

            // Refresh GHL fields via AJAX.
            $(document).on('click', '#cf7-ghl-pro-refresh-fields', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $status = $btn.siblings('.cf7-ghl-pro-refresh-status');
                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Refreshing...', 'cf7-to-highlevel-pro' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'cf7_ghl_pro_refresh_fields',
                    nonce: refreshNonce
                }, function(response) {
                    if (response.success) {
                        ghlOptionsHtml = response.data.options_html;
                        // Update all existing GHL dropdowns, preserving current selections.
                        $('.cf7-ghl-pro-ghl-select').each(function() {
                            var currentVal = $(this).val();
                            $(this).html(response.data.options_html);
                            $(this).val(currentVal);
                        });
                        if ($status.length) {
                            $status.text(response.data.message);
                        } else {
                            $btn.after('<span class="cf7-ghl-pro-refresh-status">' + response.data.message + '</span>');
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Refresh failed - check API settings', 'cf7-to-highlevel-pro' ) ); ?>';
                        if ($status.length) {
                            $status.text(msg);
                        } else {
                            $btn.after('<span class="cf7-ghl-pro-refresh-status">' + msg + '</span>');
                        }
                    }
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Refresh HighLevel Fields', 'cf7-to-highlevel-pro' ) ); ?>');
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Refresh HighLevel Fields', 'cf7-to-highlevel-pro' ) ); ?>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Save per-form field mapping.
     *
     * @param WPCF7_ContactForm $contact_form The contact form object.
     */
    public function save_field_mapping( $contact_form ) {
        if ( ! isset( $_POST['cf7_to_ghl_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( $_POST['cf7_to_ghl_nonce'] ), 'cf7_to_ghl_form_settings' ) ) {
            return;
        }

        $form_id = $contact_form->id();

        if ( ! isset( $_POST['cf7_to_ghl_pro_mapping'] ) || ! is_array( $_POST['cf7_to_ghl_pro_mapping'] ) ) {
            delete_post_meta( $form_id, $this->meta_key );
            return;
        }

        $raw_mappings = wp_unslash( $_POST['cf7_to_ghl_pro_mapping'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $sanitized    = array();

        foreach ( $raw_mappings as $row ) {
            // Resolve CF7 field: use dropdown value unless "Other", then use manual input.
            $cf7_select = isset( $row['cf7_field_select'] ) ? sanitize_text_field( $row['cf7_field_select'] ) : '';
            $cf7_manual = isset( $row['cf7_field_manual'] ) ? sanitize_text_field( $row['cf7_field_manual'] ) : '';
            $cf7_field  = ( '__other__' === $cf7_select ) ? $cf7_manual : $cf7_select;

            // Support legacy format (direct cf7_field text input from pre-1.1.0).
            if ( empty( $cf7_field ) && isset( $row['cf7_field'] ) ) {
                $cf7_field = sanitize_text_field( $row['cf7_field'] );
            }

            $ghl_field  = isset( $row['ghl_field'] ) ? sanitize_text_field( $row['ghl_field'] ) : '';
            $custom_key = isset( $row['custom_key'] ) ? sanitize_text_field( $row['custom_key'] ) : '';

            // Skip empty rows.
            if ( empty( $cf7_field ) || empty( $ghl_field ) ) {
                continue;
            }

            $sanitized[] = array(
                'cf7_field'  => $cf7_field,
                'ghl_field'  => $ghl_field,
                'custom_key' => $custom_key,
            );
        }

        if ( empty( $sanitized ) ) {
            delete_post_meta( $form_id, $this->meta_key );
        } else {
            update_post_meta( $form_id, $this->meta_key, $sanitized );
        }
    }

    /**
     * Get the per-form field mapping for a specific form.
     *
     * @param int $form_id The form ID.
     * @return array|false The field mapping array, or false if not set.
     */
    public static function get_form_mapping( $form_id ) {
        $mapping = get_post_meta( $form_id, '_cf7_to_ghl_pro_field_mapping', true );

        if ( ! is_array( $mapping ) || empty( $mapping ) ) {
            return false;
        }

        return $mapping;
    }

    /**
     * Convert free plugin's field mapping format to Pro format.
     *
     * Checks per-form mapping first, then global settings fallback.
     * Used to pre-populate Pro's mapping table when no Pro mapping exists.
     *
     * @param int $form_id The form ID.
     * @return array Pro-format mapping rows, or empty array.
     */
    private function get_free_mappings_as_pro_format( $form_id ) {
        // Check free plugin's per-form mapping first.
        $free_mapping = get_post_meta( $form_id, '_cf7_to_ghl_field_mapping', true );

        // Fall back to global mapping.
        if ( ! is_array( $free_mapping ) || empty( array_filter( $free_mapping ) ) ) {
            if ( class_exists( 'CF7_To_GHL_Settings' ) ) {
                $free_mapping = CF7_To_GHL_Settings::get_field_mapping();
            }
        }

        if ( ! is_array( $free_mapping ) || empty( array_filter( $free_mapping ) ) ) {
            return array();
        }

        $pro_rows = array();

        // Map free plugin keys to Pro GHL field values.
        $key_to_ghl = array(
            'full_name' => 'full_name',
            'email'     => 'email',
            'phone'     => 'phone',
        );

        foreach ( $key_to_ghl as $free_key => $ghl_field ) {
            if ( ! empty( $free_mapping[ $free_key ] ) ) {
                $pro_rows[] = array(
                    'cf7_field'  => $free_mapping[ $free_key ],
                    'ghl_field'  => $ghl_field,
                    'custom_key' => '',
                );
            }
        }

        // Message maps to the named message option.
        if ( ! empty( $free_mapping['message'] ) ) {
            $pro_rows[] = array(
                'cf7_field'  => $free_mapping['message'],
                'ghl_field'  => 'message',
                'custom_key' => '',
            );
        }

        return $pro_rows;
    }
}
