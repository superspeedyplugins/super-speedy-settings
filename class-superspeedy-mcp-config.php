<?php
/**
 * Shared MCP configuration base: <slug>/get-config + <slug>/update-config.
 *
 * Opt-in: propagating the settings submodule activates nothing until a plugin
 * instantiates this with its settings registry. First consumer: Auto Infinite
 * Scroll (include/php/class-ais-mcp.php); the design is
 * auto-infinite-scroll/.docs/mcp-plugin-configuration.md (§4, §8, §9).
 *
 * The plugin hands over the SAME field array its admin form renders (id, label,
 * type, default, description, optional sensitive flag), so the MCP surface and
 * the settings page can never drift. get-config is the canonical "what can I
 * configure here?" discovery call; update-config only ever writes ids present
 * in that registry - never an option name taken from the agent verbatim - and
 * refuses to change a field flagged sensitive unless the call passes
 * confirm=true.
 *
 * @since settings 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SuperSpeedy_MCP_Config' ) ) {

class SuperSpeedy_MCP_Config {

    /** @var array */
    protected $args;

    /**
     * @param array $args {
     *     @type string   $slug               Ability namespace + category (dash-only), e.g. 'auto-infinite-scroll'.
     *     @type string   $name               Human plugin name (category label).
     *     @type string   $description        Category / plugin description.
     *     @type string   $version            Plugin version, reported by get-config.
     *     @type string   $option_prefix      Per-key storage: option-name prefix, e.g. 'isw_'.
     *     @type string   $option_name        Array storage: ONE option holding key => value, e.g. 'ei_settings'.
     *                                        Exactly one of option_prefix / option_name is required.
     *     @type callable $fields             Returns the FLAT field list (id, label, type, default, description,
     *                                        sensitive, secret). A `secret` field (API token, key) is masked by
     *                                        get-config and still writable by update-config.
     *     @type string   $capability         Capability gating both abilities. Default 'manage_options'.
     *     @type callable $effective_callback Optional. Computed "effective config" block for get-config.
     *     @type string[] $extra_abilities    Optional. Further ability names to advertise in get-config.
     *     @type string   $notes              Optional. Guidance string returned by get-config.
     *     @type string   $tool_prefix        Optional. AI Engine tool prefix; defaults to slug with dashes as underscores.
     * }
     */
    public function __construct( array $args ) {
        $this->args = wp_parse_args( $args, array(
            'slug'               => '',
            'name'               => '',
            'description'        => '',
            'version'            => '',
            'option_prefix'      => '',
            'option_name'        => '',
            'fields'             => null,
            'capability'         => 'manage_options',
            'effective_callback' => null,
            'extra_abilities'    => array(),
            'notes'              => '',
            'tool_prefix'        => '',
        ) );

        if ( '' === $this->args['slug'] || ! $this->args['fields']
            || ( '' === $this->args['option_prefix'] && '' === $this->args['option_name'] ) ) {
            return;
        }

        add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
        add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

        if ( class_exists( 'SSP_AI_Engine' ) ) {
            SSP_AI_Engine::register_namespace(
                $this->args['slug'],
                '' !== $this->args['tool_prefix'] ? $this->args['tool_prefix'] : null
            );
        }
    }

    public function can_manage() {
        return current_user_can( $this->args['capability'] );
    }

    public function register_category() {
        wp_register_ability_category( $this->args['slug'], array(
            'label'       => $this->args['name'],
            'description' => $this->args['description'],
        ) );
    }

    public function register_abilities() {
        $slug = $this->args['slug'];

        wp_register_ability( $slug . '/get-config', array(
            'label'               => sprintf( '%s: read the configuration', $this->args['name'] ),
            'description'         => sprintf(
                /* translators: %s: plugin name */
                __( 'Every configurable %s setting: current value, default, type, human description, and whether changing it needs confirm=true. Also reports the effective (computed) configuration. Call this FIRST, before update-config.', 'super-speedy-settings' ),
                $this->args['name']
            ),
            'category'            => $slug,
            'input_schema'        => array( 'type' => 'object', 'additionalProperties' => false, 'default' => array() ),
            'output_schema'       => array( 'type' => 'object' ),
            'permission_callback' => array( $this, 'can_manage' ),
            'execute_callback'    => array( $this, 'exec_get_config' ),
            'meta'                => array(
                'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                'show_in_rest' => true,
                'mcp'          => array( 'public' => true, 'type' => 'tool' ),
            ),
        ) );

        wp_register_ability( $slug . '/update-config', array(
            'label'               => sprintf( '%s: change the configuration', $this->args['name'] ),
            'description'         => sprintf(
                /* translators: %s: plugin name */
                __( 'Write %s settings. Pass settings as an object of id => value pairs using the ids from get-config; unknown ids are rejected, nothing else is ever written. Fields get-config flags as sensitive additionally require confirm=true in the same call. Returns what changed plus the new effective configuration so you can verify.', 'super-speedy-settings' ),
                $this->args['name']
            ),
            'category'            => $slug,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'settings' => array(
                        'type'        => 'object',
                        'description' => __( 'id => value pairs. Ids come from get-config.', 'super-speedy-settings' ),
                    ),
                    'confirm'  => array(
                        'type'        => 'boolean',
                        'description' => __( 'Safety interlock, required only when changing a sensitive field.', 'super-speedy-settings' ),
                    ),
                ),
                'required'             => array( 'settings' ),
                'default'              => array(),
            ),
            'output_schema'       => array( 'type' => 'object' ),
            'permission_callback' => array( $this, 'can_manage' ),
            'execute_callback'    => array( $this, 'exec_update_config' ),
            'meta'                => array(
                'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
                'show_in_rest' => true,
                'mcp'          => array( 'public' => true, 'type' => 'tool' ),
            ),
        ) );
    }

    /* --------------------------------------------------------------------- */

    /**
     * Normalised field registry, keyed by id.
     *
     * @return array<string, array>
     */
    public function fields() {
        $out = array();
        foreach ( (array) call_user_func( $this->args['fields'] ) as $field ) {
            if ( empty( $field['id'] ) ) {
                continue;
            }
            $id = (string) $field['id'];
            $out[ $id ] = array(
                'id'          => $id,
                'label'       => wp_strip_all_tags( isset( $field['label'] ) ? (string) $field['label'] : $id ),
                'description' => wp_strip_all_tags( isset( $field['description'] ) ? (string) $field['description'] : '' ),
                'type'        => isset( $field['type'] ) ? (string) $field['type'] : 'text',
                'default'     => isset( $field['default'] ) ? $field['default'] : '',
                'sensitive'   => ! empty( $field['sensitive'] ),
                'secret'      => ! empty( $field['secret'] ),
                // Optional richer semantics a plugin's registry may declare:
                'choices'     => isset( $field['choices'] ) && is_array( $field['choices'] ) ? array_map( 'strval', $field['choices'] ) : array(),
                'on_value'    => isset( $field['on_value'] ) ? (string) $field['on_value'] : 'on',
                'off_value'   => isset( $field['off_value'] ) ? (string) $field['off_value'] : '',
                'sanitize'    => isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ? $field['sanitize'] : null,
            );
        }
        return $out;
    }

    /** Stored value if set, else the field default (mirrors the admin form's display logic). */
    protected function current_value( array $field ) {
        if ( '' !== $this->args['option_name'] ) {
            $all = get_option( $this->args['option_name'] );
            $raw = ( is_array( $all ) && array_key_exists( $field['id'], $all ) ) ? $all[ $field['id'] ] : false;
        } else {
            $raw = get_option( $this->args['option_prefix'] . $field['id'] );
        }
        if ( false === $raw || '' === $raw ) {
            return $field['default'];
        }
        return $raw;
    }

    /** Decode a value while it is a string that parses as JSON (bounded - handles double encoding). */
    protected static function json_undouble( $value, $max = 3 ) {
        for ( $i = 0; $i < $max && is_string( $value ); $i++ ) {
            $decoded = json_decode( $value, true );
            if ( null === $decoded && 'null' !== strtolower( trim( $value ) ) ) {
                break;
            }
            $value = $decoded;
        }
        return $value;
    }

    /** Write one setting into whichever storage shape the plugin uses. */
    protected function store_value( $id, $value ) {
        if ( '' !== $this->args['option_name'] ) {
            $all = get_option( $this->args['option_name'] );
            if ( ! is_array( $all ) ) {
                $all = array();
            }
            $all[ $id ] = $value;
            update_option( $this->args['option_name'], $all );
            return;
        }
        update_option( $this->args['option_prefix'] . $id, $value );
    }

    protected function effective() {
        if ( is_callable( $this->args['effective_callback'] ) ) {
            return (array) call_user_func( $this->args['effective_callback'] );
        }
        return array();
    }

    public function exec_get_config() {
        $settings = array();
        foreach ( $this->fields() as $field ) {
            $value = $this->current_value( $field );
            if ( $field['secret'] ) {
                // API tokens and keys never echo back through the read tool -
                // report only whether one is set. update-config can still write.
                $value = ( is_scalar( $value ) && '' !== (string) $value ) ? '********' : '';
            }
            $row = array(
                'id'          => $field['id'],
                'label'       => $field['label'],
                'description' => $field['description'],
                'type'        => $field['type'],
                'default'     => is_scalar( $field['default'] ) ? (string) $field['default'] : wp_json_encode( $field['default'] ),
                'value'       => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
                'sensitive'   => (bool) $field['sensitive'],
                'secret'      => (bool) $field['secret'],
            );
            if ( ! empty( $field['choices'] ) ) {
                $row['choices'] = $field['choices'];
            }
            $settings[] = $row;
        }

        $abilities = array_merge(
            array( $this->args['slug'] . '/get-config', $this->args['slug'] . '/update-config' ),
            array_values( (array) $this->args['extra_abilities'] )
        );

        return array(
            'plugin'    => array(
                'slug'    => $this->args['slug'],
                'name'    => $this->args['name'],
                'version' => (string) $this->args['version'],
            ),
            'settings'  => $settings,
            'effective' => $this->effective(),
            'abilities' => $abilities,
            'notes'     => (string) $this->args['notes'],
        );
    }

    public function exec_update_config( $input = array() ) {
        $input    = is_array( $input ) ? $input : array();
        $settings = isset( $input['settings'] ) ? $input['settings'] : array();
        // Transport tolerance: some clients (and, historically, AI Engine)
        // deliver nested objects as JSON strings, sometimes double-encoded.
        if ( is_string( $settings ) ) {
            $decoded = self::json_undouble( $settings );
            if ( is_array( $decoded ) ) {
                $settings = $decoded;
            }
        }
        $settings = is_array( $settings ) ? $settings : array();
        $confirm  = ! empty( $input['confirm'] );
        $registry = $this->fields();

        if ( empty( $settings ) ) {
            return new WP_Error( 'ssp_config_empty', __( 'Pass at least one id => value pair in settings. Ids come from get-config.', 'super-speedy-settings' ), array( 'status' => 400 ) );
        }

        $unknown = array_diff( array_keys( $settings ), array_keys( $registry ) );
        if ( ! empty( $unknown ) ) {
            return new WP_Error(
                'ssp_config_unknown_setting',
                sprintf(
                    /* translators: %s: comma-separated setting ids */
                    __( 'Unknown setting id(s): %s. Only ids listed by get-config can be written.', 'super-speedy-settings' ),
                    implode( ', ', array_map( 'strval', $unknown ) )
                ),
                array( 'status' => 400 )
            );
        }

        $needs_confirm = array();
        foreach ( $settings as $id => $value ) {
            if ( $registry[ $id ]['sensitive'] && ! $confirm ) {
                $needs_confirm[] = $id;
            }
        }
        if ( ! empty( $needs_confirm ) ) {
            return new WP_Error(
                'ssp_config_confirm_required',
                sprintf(
                    /* translators: %s: comma-separated setting ids */
                    __( 'Sensitive field(s) %s can change existing behaviour site-wide. Call again with confirm=true to proceed; nothing was written.', 'super-speedy-settings' ),
                    implode( ', ', $needs_confirm )
                ),
                array( 'status' => 400 )
            );
        }

        $updated = array();
        foreach ( $settings as $id => $value ) {
            $clean = $this->sanitise( $registry[ $id ], $value );
            if ( is_wp_error( $clean ) ) {
                return $clean;
            }
            $this->store_value( $id, $clean );
            $updated[] = array(
                'id'    => (string) $id,
                'value' => $registry[ $id ]['secret'] ? '********' : ( is_scalar( $clean ) ? (string) $clean : wp_json_encode( $clean ) ),
            );
        }

        return array(
            'updated'   => $updated,
            'effective' => $this->effective(),
            'notes'     => __( 'Written. Verify with get-config (or the plugin\'s own inspection abilities) that the effective configuration is what you intended.', 'super-speedy-settings' ),
        );
    }

    /**
     * Per-type sanitisation. Checkboxes store on_value/off_value ('on'/'' by
     * default - a registry can declare 'yes'/'no' storage instead); a `choices`
     * list validates enums; a `sanitize` callable overrides everything; `list`
     * fields accept an array of strings; numbers must be numeric; everything
     * else is treated as text.
     *
     * @return string|array|WP_Error
     */
    protected function sanitise( array $field, $value ) {
        if ( $field['sanitize'] ) {
            return call_user_func( $field['sanitize'], $value, $field );
        }

        if ( ! empty( $field['choices'] ) ) {
            $value = trim( (string) $value );
            if ( ! in_array( $value, $field['choices'], true ) ) {
                return new WP_Error(
                    'ssp_config_invalid_value',
                    sprintf(
                        /* translators: 1: setting id, 2: comma-separated valid values */
                        __( '"%1$s" must be one of: %2$s.', 'super-speedy-settings' ),
                        $field['id'],
                        implode( ', ', $field['choices'] )
                    ),
                    array( 'status' => 400 )
                );
            }
            return $value;
        }

        switch ( $field['type'] ) {
            case 'checkbox':
                if ( is_bool( $value ) ) {
                    return $value ? $field['on_value'] : $field['off_value'];
                }
                $truthy = array( 'on', '1', 'true', 'yes', strtolower( $field['on_value'] ) );
                return in_array( strtolower( trim( (string) $value ) ), $truthy, true ) ? $field['on_value'] : $field['off_value'];

            case 'list':
                if ( is_string( $value ) ) {
                    // Accept a (possibly double-) JSON-encoded array - the
                    // known AI Engine transport shapes.
                    $decoded = self::json_undouble( $value );
                    if ( is_array( $decoded ) ) {
                        $value = $decoded;
                    }
                }
                if ( ! is_array( $value ) ) {
                    return new WP_Error(
                        'ssp_config_invalid_value',
                        sprintf(
                            /* translators: %s: setting id */
                            __( '"%s" expects an array of strings.', 'super-speedy-settings' ),
                            $field['id']
                        ),
                        array( 'status' => 400 )
                    );
                }
                return array_values( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );

            case 'number':
                if ( ! is_numeric( $value ) ) {
                    return new WP_Error(
                        'ssp_config_invalid_value',
                        sprintf(
                            /* translators: %s: setting id */
                            __( '"%s" expects a number.', 'super-speedy-settings' ),
                            $field['id']
                        ),
                        array( 'status' => 400 )
                    );
                }
                return (string) ( 0 + $value );

            case 'image':
                return esc_url_raw( (string) $value );

            default:
                return sanitize_text_field( (string) $value );
        }
    }
}

}
