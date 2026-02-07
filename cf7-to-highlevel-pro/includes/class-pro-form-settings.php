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

        // Hide the free plugin's basic field mapping UI — Pro replaces it.
        add_filter( 'cf7_to_ghl_show_free_mapping', '__return_false' );
    }

    /**
     * Get available HighLevel fields grouped by category.
     *
     * @return array Grouped GHL fields.
     */
    public static function get_ghl_fields() {
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
            'Other'   => array(
                'source'      => 'Lead Source',
                'tags'        => 'Tags (comma-separated)',
                'gender'      => 'Gender',
                'dateOfBirth' => 'Date of Birth',
            ),
        );
    }

    /**
     * Enqueue scripts on CF7 form editor pages.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        if ( ! in_array( $hook, array( 'toplevel_page_wpcf7', 'contact_page_wpcf7-new' ), true ) ) {
            // Also check for the contact form edit screen.
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
            .cf7-ghl-pro-actions { text-align: center; width: 60px; }
            .cf7-ghl-pro-remove-row { color: #dc3232; cursor: pointer; font-size: 18px; text-decoration: none; }
            .cf7-ghl-pro-remove-row:hover { color: #a00; }
            .cf7-ghl-pro-buttons { margin-top: 10px; }
            .cf7-ghl-pro-detected { margin-top: 10px; padding: 10px; background: #f0f6fc; border: 1px solid #c3d4e0; border-radius: 4px; }
            .cf7-ghl-pro-detected code { background: #e8e8e8; padding: 2px 6px; border-radius: 3px; margin: 2px; display: inline-block; }
            .cf7-ghl-pro-custom-key-hint { font-size: 11px; color: #666; margin-top: 3px; font-style: italic; }
            .cf7-ghl-pro-guide { margin-top: 15px; padding: 12px 15px; background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #0073aa; border-radius: 4px; }
            .cf7-ghl-pro-guide h4 { margin: 0 0 8px; font-size: 13px; }
            .cf7-ghl-pro-guide ol { margin: 0; padding-left: 20px; }
            .cf7-ghl-pro-guide ol li { margin-bottom: 4px; font-size: 12px; color: #444; }
            .cf7-ghl-pro-guide code { background: #f0f0f0; padding: 1px 5px; border-radius: 3px; font-size: 11px; }
        ' );

        wp_add_inline_script( 'jquery-core', $this->get_inline_js() );
    }

    /**
     * Get inline JavaScript for dynamic field mapping rows.
     *
     * @return string JavaScript code.
     */
    private function get_inline_js() {
        $ghl_fields = self::get_ghl_fields();
        $options_html = '<option value="">' . esc_html__( '-- Select HighLevel Field --', 'cf7-to-highlevel-pro' ) . '</option>';

        foreach ( $ghl_fields as $group_label => $fields ) {
            $options_html .= '<optgroup label="' . esc_attr( $group_label ) . '">';
            foreach ( $fields as $value => $label ) {
                $options_html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
            }
            $options_html .= '</optgroup>';
        }
        $options_html .= '<optgroup label="Custom"><option value="__custom__">Custom Field (enter key)</option></optgroup>';

        $options_json = wp_json_encode( $options_html );

        return <<<JS
jQuery(document).ready(function($) {
    var ghlOptions = {$options_json};

    var customKeyHint = '<p class="cf7-ghl-pro-custom-key-hint" style="display:none;">Enter the field key from HighLevel &gt; Settings &gt; Custom Fields</p>';

    // Add new mapping row.
    $(document).on('click', '#cf7-ghl-pro-add-row', function(e) {
        e.preventDefault();
        var index = $('.cf7-ghl-pro-mapping-table tbody tr').length;
        var row = '<tr>' +
            '<td><input type="text" name="cf7_to_ghl_pro_mapping[' + index + '][cf7_field]" value="" placeholder="e.g. your-name" /></td>' +
            '<td><select name="cf7_to_ghl_pro_mapping[' + index + '][ghl_field]" class="cf7-ghl-pro-ghl-select">' + ghlOptions + '</select>' +
            '<input type="text" name="cf7_to_ghl_pro_mapping[' + index + '][custom_key]" class="cf7-ghl-pro-custom-key" placeholder="e.g. contact.budget_range" style="display:none;" />' + customKeyHint + '</td>' +
            '<td class="cf7-ghl-pro-actions"><a href="#" class="cf7-ghl-pro-remove-row" title="Remove">&times;</a></td>' +
            '</tr>';
        $('.cf7-ghl-pro-mapping-table tbody').append(row);
    });

    // Remove mapping row.
    $(document).on('click', '.cf7-ghl-pro-remove-row', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();
    });

    // Toggle custom field key input and hint.
    $(document).on('change', '.cf7-ghl-pro-ghl-select', function() {
        var customInput = $(this).siblings('.cf7-ghl-pro-custom-key');
        var customHint = $(this).siblings('.cf7-ghl-pro-custom-key-hint');
        if ($(this).val() === '__custom__') {
            customInput.show();
            customHint.show();
        } else {
            customInput.hide().val('');
            customHint.hide();
        }
    });

    // Auto-detect CF7 fields from form template.
    $(document).on('click', '#cf7-ghl-pro-auto-detect', function(e) {
        e.preventDefault();
        var formContent = $('#wpcf7-form').val() || '';
        var regex = /\[(?:text|email|tel|url|number|date|textarea|select|checkbox|radio|quiz|file|hidden)[*]?\s+([a-zA-Z0-9_-]+)/g;
        var fields = [];
        var match;
        while ((match = regex.exec(formContent)) !== null) {
            if (fields.indexOf(match[1]) === -1) {
                fields.push(match[1]);
            }
        }

        var container = $('#cf7-ghl-pro-detected-fields');
        if (fields.length === 0) {
            container.html('<p>No fields detected. Save your form template first.</p>').show();
            return;
        }

        var html = '<p><strong>Detected CF7 fields:</strong> ';
        fields.forEach(function(f) {
            html += '<code>' + f + '</code> ';
        });
        html += '</p><p>Click a field name to add it as a new mapping row:</p><p>';
        fields.forEach(function(f) {
            html += '<button type="button" class="button button-small cf7-ghl-pro-add-detected" data-field="' + f + '">' + f + '</button> ';
        });
        html += '</p>';
        container.html(html).show();
    });

    // Add detected field as a new row.
    $(document).on('click', '.cf7-ghl-pro-add-detected', function(e) {
        e.preventDefault();
        var fieldName = $(this).data('field');
        var index = $('.cf7-ghl-pro-mapping-table tbody tr').length;
        var row = '<tr>' +
            '<td><input type="text" name="cf7_to_ghl_pro_mapping[' + index + '][cf7_field]" value="' + fieldName + '" /></td>' +
            '<td><select name="cf7_to_ghl_pro_mapping[' + index + '][ghl_field]" class="cf7-ghl-pro-ghl-select">' + ghlOptions + '</select>' +
            '<input type="text" name="cf7_to_ghl_pro_mapping[' + index + '][custom_key]" class="cf7-ghl-pro-custom-key" placeholder="e.g. contact.budget_range" style="display:none;" />' + customKeyHint + '</td>' +
            '<td class="cf7-ghl-pro-actions"><a href="#" class="cf7-ghl-pro-remove-row" title="Remove">&times;</a></td>' +
            '</tr>';
        $('.cf7-ghl-pro-mapping-table tbody').append(row);
        $(this).prop('disabled', true).css('opacity', '0.5');
    });
});
JS;
    }

    /**
     * Render the field mapping panel in CF7 editor.
     *
     * @param WPCF7_ContactForm $contact_form The contact form object.
     */
    public function render_field_mapping_panel( $contact_form ) {
        $form_id = $contact_form->id();
        $mappings = get_post_meta( $form_id, $this->meta_key, true );

        if ( ! is_array( $mappings ) ) {
            $mappings = array();
        }

        $ghl_fields = self::get_ghl_fields();
        ?>
        <div class="cf7-ghl-pro-mapping">
            <h3><?php esc_html_e( 'Field Mapping (Pro)', 'cf7-to-highlevel-pro' ); ?></h3>
            <p class="description">
                <?php esc_html_e( 'Map each CF7 form field to a HighLevel contact field. Supports all standard GHL fields plus custom fields.', 'cf7-to-highlevel-pro' ); ?>
            </p>

            <table class="cf7-ghl-pro-mapping-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'CF7 Field Name', 'cf7-to-highlevel-pro' ); ?></th>
                        <th><?php esc_html_e( 'HighLevel Field', 'cf7-to-highlevel-pro' ); ?></th>
                        <th class="cf7-ghl-pro-actions"><?php esc_html_e( 'Remove', 'cf7-to-highlevel-pro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $mappings ) ) : ?>
                        <?php foreach ( $mappings as $index => $row ) : ?>
                            <tr>
                                <td>
                                    <input type="text"
                                           name="cf7_to_ghl_pro_mapping[<?php echo esc_attr( $index ); ?>][cf7_field]"
                                           value="<?php echo esc_attr( $row['cf7_field'] ); ?>"
                                           placeholder="<?php esc_attr_e( 'e.g. your-name', 'cf7-to-highlevel-pro' ); ?>" />
                                </td>
                                <td>
                                    <select name="cf7_to_ghl_pro_mapping[<?php echo esc_attr( $index ); ?>][ghl_field]"
                                            class="cf7-ghl-pro-ghl-select">
                                        <option value=""><?php esc_html_e( '-- Select HighLevel Field --', 'cf7-to-highlevel-pro' ); ?></option>
                                        <?php foreach ( $ghl_fields as $group_label => $fields ) : ?>
                                            <optgroup label="<?php echo esc_attr( $group_label ); ?>">
                                                <?php foreach ( $fields as $value => $label ) : ?>
                                                    <option value="<?php echo esc_attr( $value ); ?>"
                                                            <?php selected( isset( $row['ghl_field'] ) ? $row['ghl_field'] : '', $value ); ?>>
                                                        <?php echo esc_html( $label ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                        <optgroup label="<?php esc_attr_e( 'Custom', 'cf7-to-highlevel-pro' ); ?>">
                                            <option value="__custom__"
                                                    <?php selected( isset( $row['ghl_field'] ) ? $row['ghl_field'] : '', '__custom__' ); ?>>
                                                <?php esc_html_e( 'Custom Field (enter key)', 'cf7-to-highlevel-pro' ); ?>
                                            </option>
                                        </optgroup>
                                    </select>
                                    <input type="text"
                                           name="cf7_to_ghl_pro_mapping[<?php echo esc_attr( $index ); ?>][custom_key]"
                                           class="cf7-ghl-pro-custom-key"
                                           value="<?php echo esc_attr( isset( $row['custom_key'] ) ? $row['custom_key'] : '' ); ?>"
                                           placeholder="<?php esc_attr_e( 'e.g. contact.budget_range', 'cf7-to-highlevel-pro' ); ?>"
                                           style="<?php echo ( isset( $row['ghl_field'] ) && '__custom__' === $row['ghl_field'] ) ? '' : 'display:none;'; ?>" />
                                    <p class="cf7-ghl-pro-custom-key-hint" style="<?php echo ( isset( $row['ghl_field'] ) && '__custom__' === $row['ghl_field'] ) ? '' : 'display:none;'; ?>">
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
                <button type="button" id="cf7-ghl-pro-auto-detect" class="button">
                    <?php esc_html_e( 'Auto-Detect CF7 Fields', 'cf7-to-highlevel-pro' ); ?>
                </button>
            </div>

            <div id="cf7-ghl-pro-detected-fields" class="cf7-ghl-pro-detected" style="display: none;"></div>

            <div class="cf7-ghl-pro-guide">
                <h4><?php esc_html_e( 'How to find your HighLevel Custom Field keys', 'cf7-to-highlevel-pro' ); ?></h4>
                <ol>
                    <li><?php esc_html_e( 'Log into your HighLevel account', 'cf7-to-highlevel-pro' ); ?></li>
                    <li><?php esc_html_e( 'Go to Settings > Custom Fields', 'cf7-to-highlevel-pro' ); ?></li>
                    <li><?php esc_html_e( 'Find the custom field you want to map to', 'cf7-to-highlevel-pro' ); ?></li>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: example key */
                            esc_html__( 'Copy the field key (e.g. %s) - this is the internal identifier, not the display name', 'cf7-to-highlevel-pro' ),
                            '<code>contact.budget_range</code>'
                        );
                        ?>
                    </li>
                    <li><?php esc_html_e( 'Select "Custom Field (enter key)" from the HighLevel Field dropdown above and paste the key', 'cf7-to-highlevel-pro' ); ?></li>
                </ol>
                <p style="margin: 8px 0 0; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'Alternatively, you can find custom field keys via Settings > Integrations > Private Integrations in your HighLevel account. The custom field key is listed under each field\'s details.', 'cf7-to-highlevel-pro' ); ?>
                </p>
            </div>
        </div>
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
        $sanitized = array();

        foreach ( $raw_mappings as $row ) {
            $cf7_field = isset( $row['cf7_field'] ) ? sanitize_text_field( $row['cf7_field'] ) : '';
            $ghl_field = isset( $row['ghl_field'] ) ? sanitize_text_field( $row['ghl_field'] ) : '';
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
}
