<?php
/**
 * Pro API handler that extends the free plugin with per-form field mapping.
 *
 * @package CF7_To_HighLevel_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pro API Handler class.
 *
 * Hooks into the free plugin's filters to provide per-form field mapping
 * and support for all HighLevel contact fields including conversations.
 */
class CF7_To_GHL_Pro_API_Handler {

    /**
     * Single instance.
     *
     * @var CF7_To_GHL_Pro_API_Handler
     */
    private static $instance = null;

    /**
     * Standard GHL fields that map directly to the API payload root.
     *
     * @var array
     */
    private $standard_fields = array(
        'firstName',
        'lastName',
        'email',
        'phone',
        'companyName',
        'website',
        'address1',
        'city',
        'state',
        'postalCode',
        'country',
        'gender',
        'dateOfBirth',
        'timezone',
        'assignedTo',
    );

    /**
     * Pending conversation messages keyed by form ID.
     *
     * Stored during payload build, sent after contact creation.
     *
     * @var array
     */
    private $pending_conversations = array();

    /**
     * Get the single instance.
     *
     * @return CF7_To_GHL_Pro_API_Handler
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
        add_filter( 'cf7_to_ghl_payload', array( $this, 'build_pro_payload' ), 10, 4 );
        add_action( 'cf7_to_ghl_contact_created', array( $this, 'send_conversation_message' ), 10, 5 );
    }

    /**
     * Build an extended payload using per-form field mappings.
     *
     * Hooks into the `cf7_to_ghl_payload` filter. If the form has a Pro
     * per-form mapping, this replaces the free plugin's default payload
     * with one built from the Pro mapping (supporting all GHL fields).
     *
     * @param array $payload     The default payload from the free plugin.
     * @param int   $form_id     The form ID.
     * @param array $posted_data The submitted form data.
     * @param array $mapping     The field mapping used by the free plugin.
     * @return array The modified payload.
     */
    public function build_pro_payload( $payload, $form_id, $posted_data, $mapping ) {
        // Check if this form has a Pro per-form mapping.
        $pro_mapping = CF7_To_GHL_Pro_Form_Settings::get_form_mapping( $form_id );

        if ( false === $pro_mapping ) {
            // No Pro mapping for this form, keep the free plugin's payload.
            return $payload;
        }

        // Start with locationId and source from the free plugin payload.
        $pro_payload = array(
            'locationId' => $payload['locationId'],
            'source'     => $payload['source'],
        );

        $custom_fields = array();

        foreach ( $pro_mapping as $row ) {
            $cf7_field = $row['cf7_field'];
            $ghl_field = $row['ghl_field'];
            $custom_key = isset( $row['custom_key'] ) ? $row['custom_key'] : '';

            // Get the value from the submitted form data.
            $value = $this->get_posted_value( $posted_data, $cf7_field );

            if ( '' === $value ) {
                continue;
            }

            // Handle full_name: auto-split into firstName/lastName.
            if ( 'full_name' === $ghl_field ) {
                $api_handler = CF7_To_GHL_API_Handler::get_instance();
                $name_parts = $api_handler->split_name( $value );
                $pro_payload['firstName'] = $name_parts['first'];
                $pro_payload['lastName'] = $name_parts['last'];
                continue;
            }

            // Handle tags: convert comma-separated string to array.
            if ( 'tags' === $ghl_field ) {
                $pro_payload['tags'] = array_map( 'trim', explode( ',', $value ) );
                continue;
            }

            // Handle source: override the lead source.
            if ( 'source' === $ghl_field ) {
                $pro_payload['source'] = $value;
                continue;
            }

            // Handle dnd: convert to boolean.
            if ( 'dnd' === $ghl_field ) {
                $pro_payload['dnd'] = in_array( strtolower( $value ), array( '1', 'yes', 'true', 'on' ), true );
                continue;
            }

            // Handle message: save as custom field on the contact.
            if ( 'message' === $ghl_field ) {
                $custom_fields[] = array(
                    'key'   => 'message',
                    'value' => $value,
                );
                continue;
            }

            // Handle conversation_message: store for sending after contact creation.
            if ( 'conversation_message' === $ghl_field ) {
                $this->pending_conversations[ $form_id ] = $value;
                continue;
            }

            // Handle custom fields.
            if ( '__custom__' === $ghl_field && ! empty( $custom_key ) ) {
                $custom_fields[] = array(
                    'key'   => $custom_key,
                    'value' => $value,
                );
                continue;
            }

            // Handle standard fields.
            if ( in_array( $ghl_field, $this->standard_fields, true ) ) {
                $pro_payload[ $ghl_field ] = $value;
                continue;
            }
        }

        // Add custom fields to payload if any.
        if ( ! empty( $custom_fields ) ) {
            $pro_payload['customFields'] = $custom_fields;
        }

        return $pro_payload;
    }

    /**
     * Send a conversation message after contact creation.
     *
     * Hooks into `cf7_to_ghl_contact_created` to create a conversation
     * and send the form message via the Conversations API.
     *
     * @param string $contact_id  The HighLevel contact ID.
     * @param int    $form_id     The CF7 form ID.
     * @param string $form_title  The CF7 form title.
     * @param array  $posted_data The submitted form data.
     * @param string $api_token   The API token.
     */
    public function send_conversation_message( $contact_id, $form_id, $form_title, $posted_data, $api_token ) {
        if ( empty( $this->pending_conversations[ $form_id ] ) ) {
            return;
        }

        $message = $this->pending_conversations[ $form_id ];
        unset( $this->pending_conversations[ $form_id ] );

        $location_id = CF7_To_GHL_Settings::get_setting( 'location_id' );

        // Step 1: Create a conversation for this contact.
        $conv_response = wp_remote_post(
            'https://services.leadconnectorhq.com/conversations/',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_token,
                    'Content-Type'  => 'application/json',
                    'Version'       => '2021-04-15',
                ),
                'body'    => wp_json_encode( array(
                    'locationId' => $location_id,
                    'contactId'  => $contact_id,
                ) ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $conv_response ) ) {
            CF7_To_GHL_Logger::log(
                'error',
                'Failed to create conversation: ' . $conv_response->get_error_message(),
                array( 'form_id' => $form_id, 'contact_id' => $contact_id )
            );
            return;
        }

        $conv_code = wp_remote_retrieve_response_code( $conv_response );
        $conv_data = json_decode( wp_remote_retrieve_body( $conv_response ), true );

        if ( $conv_code < 200 || $conv_code >= 300 ) {
            CF7_To_GHL_Logger::log(
                'error',
                'Conversation creation failed (HTTP ' . $conv_code . ')',
                array( 'form_id' => $form_id, 'contact_id' => $contact_id, 'response' => $conv_data )
            );
            return;
        }

        // Step 2: Send the message to the conversation.
        $msg_response = wp_remote_post(
            'https://services.leadconnectorhq.com/conversations/messages',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_token,
                    'Content-Type'  => 'application/json',
                    'Version'       => '2021-04-15',
                ),
                'body'    => wp_json_encode( array(
                    'type'      => 'Custom',
                    'contactId' => $contact_id,
                    'message'   => $message,
                ) ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $msg_response ) ) {
            CF7_To_GHL_Logger::log(
                'error',
                'Failed to send conversation message: ' . $msg_response->get_error_message(),
                array( 'form_id' => $form_id, 'contact_id' => $contact_id )
            );
            return;
        }

        $msg_code = wp_remote_retrieve_response_code( $msg_response );
        $msg_data = json_decode( wp_remote_retrieve_body( $msg_response ), true );

        if ( $msg_code >= 200 && $msg_code < 300 ) {
            CF7_To_GHL_Logger::log(
                'success',
                'Conversation message sent to contact',
                array(
                    'form_id'    => $form_id,
                    'contact_id' => $contact_id,
                    'message'    => $message,
                    'response'   => $msg_data,
                )
            );
        } else {
            CF7_To_GHL_Logger::log(
                'error',
                'Conversation message failed (HTTP ' . $msg_code . ')',
                array(
                    'form_id'    => $form_id,
                    'contact_id' => $contact_id,
                    'response'   => $msg_data,
                )
            );
        }
    }

    /**
     * Get a value from posted data for a CF7 field.
     *
     * @param array  $posted_data The submitted form data.
     * @param string $cf7_field   The CF7 field name.
     * @return string The field value.
     */
    private function get_posted_value( $posted_data, $cf7_field ) {
        if ( empty( $cf7_field ) || ! isset( $posted_data[ $cf7_field ] ) ) {
            return '';
        }

        $value = $posted_data[ $cf7_field ];

        // Handle array values (e.g., checkboxes, multi-selects).
        if ( is_array( $value ) ) {
            $value = implode( ', ', $value );
        }

        return sanitize_text_field( $value );
    }
}
