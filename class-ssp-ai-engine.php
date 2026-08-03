<?php
/**
 * AI Engine aliases for Super Speedy abilities.
 *
 * Mirrors registered abilities onto AI Engine's MCP endpoint (/wp-json/mcp/v1/...)
 * via the mwai_mcp_tools + mwai_mcp_callback filters, so a site running AI Engine
 * needs ONE MCP connector for AI Engine's tools and ours. This is a generic
 * adapter, not hand-written tool definitions: the tool list is derived from the
 * Abilities API registry at request time, so new abilities appear automatically
 * and schemas cannot drift from their single registration.
 *
 * Which abilities surface: any whose namespace has been registered here. The
 * shared `ssp` surface (SSP_MCP) is always registered; each plugin using the
 * SuperSpeedy_MCP_Config base has its namespace registered by the base. Ability
 * names map dash->underscore under a per-namespace prefix, e.g.
 * ssp/install-plugin -> ssp_install_plugin.
 *
 * Execution goes through WP_Ability::execute() - the full pipeline (input
 * validation, the ability's own permission_callback, output validation) - so
 * capability gates and confirm interlocks apply identically on this transport.
 * The acting user is whoever AI Engine's authentication resolves. AI Engine logs
 * every call (mwai_mcp_tool_called) with user_id + client info, so audit comes
 * free. Requires nothing from AI Engine at load time: if it is inactive the
 * filters never fire.
 *
 * Reference: super-speedy-emails/includes/class-sse-ai-engine.php and AI
 * Engine 3.x labs/mcp.php (get_tools_list() / execute_tool()).
 *
 * @since settings 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SSP_AI_Engine' ) ) {

class SSP_AI_Engine {

    /** @var array<string,string> ability namespace => tool-name prefix */
    protected static $namespaces = array();

    public static function init() {
        // Only one copy registers, however many Super Speedy plugins are active.
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        // The shared ssp surface always aliases when this adapter is present.
        self::register_namespace( 'ssp', 'ssp_' );

        add_filter( 'mwai_mcp_tools', array( __CLASS__, 'register_tools' ) );
        add_filter( 'mwai_mcp_callback', array( __CLASS__, 'execute_tool' ), 10, 4 );
    }

    /**
     * Expose an ability namespace on the AI Engine endpoint.
     *
     * @param string      $namespace Ability namespace (the part before the /).
     * @param string|null $prefix    Tool-name prefix; defaults to the namespace
     *                               with dashes as underscores, plus '_'.
     */
    public static function register_namespace( $namespace, $prefix = null ) {
        $namespace = (string) $namespace;
        if ( '' === $namespace ) {
            return;
        }
        if ( null === $prefix || '' === $prefix ) {
            $prefix = str_replace( '-', '_', $namespace ) . '_';
        }
        self::$namespaces[ $namespace ] = (string) $prefix;
    }

    /** ssp/install-plugin -> ssp_install_plugin. '' when the namespace is not registered. */
    public static function tool_name( $ability_name ) {
        $parts = explode( '/', (string) $ability_name, 2 );
        if ( 2 !== count( $parts ) || ! isset( self::$namespaces[ $parts[0] ] ) ) {
            return '';
        }
        return self::$namespaces[ $parts[0] ] . str_replace( '-', '_', $parts[1] );
    }

    /**
     * The abilities exposed on the endpoint, keyed by AI Engine tool name.
     *
     * Pulled from the registry (wp_get_abilities() triggers
     * wp_abilities_api_init, so lazy-init timing is safe). Filterable so a site
     * can withhold abilities from this transport.
     *
     * @return array<string, WP_Ability>
     */
    public static function abilities() {
        $map = array();
        foreach ( wp_get_abilities() as $ability ) {
            $tool = self::tool_name( $ability->get_name() );
            if ( '' !== $tool ) {
                $map[ $tool ] = $ability;
            }
        }

        /**
         * Which Super Speedy abilities the AI Engine MCP endpoint exposes.
         *
         * @param array<string, WP_Ability> $map tool name => ability.
         */
        return apply_filters( 'ssp_ai_engine_abilities', $map );
    }

    public static function register_tools( $tools ) {
        if ( ! is_array( $tools ) ) {
            $tools = array();
        }

        $taken = array();
        foreach ( $tools as $tool ) {
            if ( is_array( $tool ) && isset( $tool['name'] ) ) {
                $taken[ (string) $tool['name'] ] = true;
            }
        }

        foreach ( self::abilities() as $name => $ability ) {
            if ( isset( $taken[ $name ] ) ) {
                continue; // Never clobber another plugin's tool.
            }
            $taken[ $name ] = true;

            $annotations = (array) $ability->get_meta_item( 'annotations', array() );
            $schema      = $ability->get_input_schema();
            if ( empty( $schema ) ) {
                // AI Engine requires an object schema; (object) keeps
                // `properties` encoding as {} rather than [].
                $schema = array(
                    'type'       => 'object',
                    'properties' => (object) array(),
                );
            }

            $tools[] = array(
                'name'        => $name,
                'description' => $ability->get_description(),
                'inputSchema' => $schema,
                'annotations' => array(
                    'readOnlyHint'    => ! empty( $annotations['readonly'] ),
                    'destructiveHint' => ! empty( $annotations['destructive'] ),
                    'idempotentHint'  => ! empty( $annotations['idempotent'] ),
                    'openWorldHint'   => false,
                ),
                'accessLevel' => self::access_level( $ability, $annotations ),
            );
        }

        return $tools;
    }

    /**
     * Map an ability to AI Engine's read/write/admin connection-role gates:
     * readonly -> 'read', destructive -> 'admin', else 'write'. An ability can
     * override via meta: 'ai_engine' => array( 'accessLevel' => 'admin' ) -
     * ssp/install-plugin uses this because installing code warrants the admin
     * gate even though the ability is not annotated destructive.
     */
    private static function access_level( $ability, array $annotations ) {
        $override = $ability->get_meta_item( 'ai_engine', array() );
        if ( is_array( $override ) && isset( $override['accessLevel'] )
            && in_array( $override['accessLevel'], array( 'read', 'write', 'admin' ), true ) ) {
            return $override['accessLevel'];
        }
        if ( ! empty( $annotations['readonly'] ) ) {
            return 'read';
        }
        if ( ! empty( $annotations['destructive'] ) ) {
            return 'admin';
        }
        return 'write';
    }

    /**
     * mwai_mcp_callback handler. Returning the tool result (non-null) makes AI
     * Engine wrap it as the JSON-RPC response; throwing surfaces a tool-level
     * isError result the model can read. Anything not ours passes through.
     */
    public static function execute_tool( $result, $tool, $args, $id ) {
        if ( null !== $result ) {
            return $result;
        }

        $abilities = self::abilities();
        if ( ! isset( $abilities[ (string) $tool ] ) ) {
            return $result;
        }

        // Transport repair BEFORE the Abilities API validates: AI Engine has
        // historically delivered the whole argument set as a JSON STRING
        // instead of a decoded object, and double-encoded nested values (a
        // string containing the JSON of the object the schema expects).
        $args = self::repair_args( $abilities[ (string) $tool ], $args );

        if ( null !== $args && ! is_array( $args ) ) {
            throw new InvalidArgumentException( 'Tool arguments must be an object.' );
        }

        // execute() validates input, checks the ability's permission_callback,
        // runs, and validates output - the full Abilities API pipeline.
        $out = $abilities[ (string) $tool ]->execute( is_array( $args ) ? $args : array() );

        if ( is_wp_error( $out ) ) {
            throw new RuntimeException( $out->get_error_message() );
        }

        return $out;
    }

    /** Decode a value while it is a string that parses as JSON (bounded - handles double encoding). */
    private static function json_undouble( $value, $max = 3 ) {
        for ( $i = 0; $i < $max && is_string( $value ); $i++ ) {
            $decoded = json_decode( $value, true );
            if ( null === $decoded && 'null' !== strtolower( trim( $value ) ) ) {
                break; // Not JSON - leave the string alone.
            }
            $value = $decoded;
        }
        return $value;
    }

    /**
     * Repair the two known AI Engine transport bugs without changing valid
     * input: (1) the whole $args arriving as a JSON string; (2) a top-level
     * property whose schema expects object/array arriving as a (possibly
     * double-) JSON-encoded string. Anything that does not decode to the
     * expected shape is left untouched so the schema validation error stays
     * honest.
     */
    public static function repair_args( $ability, $args ) {
        if ( is_string( $args ) ) {
            $decoded = self::json_undouble( $args );
            if ( is_array( $decoded ) || null === $decoded ) {
                $args = $decoded;
            }
        }
        if ( ! is_array( $args ) ) {
            return $args;
        }

        $schema = $ability->get_input_schema();
        $props  = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
        foreach ( $props as $key => $prop ) {
            if ( ! array_key_exists( $key, $args ) || ! is_string( $args[ $key ] ) ) {
                continue;
            }
            $types = isset( $prop['type'] ) ? (array) $prop['type'] : array();
            if ( ! array_intersect( $types, array( 'object', 'array' ) ) ) {
                continue;
            }
            $decoded = self::json_undouble( $args[ $key ] );
            if ( is_array( $decoded ) ) {
                $args[ $key ] = $decoded;
            }
        }
        return $args;
    }
}

}
