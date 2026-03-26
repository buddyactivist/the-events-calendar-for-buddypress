<?php
/**
 * Plugin Name: The Events Calendar for Buddypress
 * Plugin URI: 
 * Description: Integra The Events Calendar con i Gruppi BuddyPress usando i form standard (Backend o Community Events) e visualizza il gruppo nei dettagli nativi dell'evento.
 * Version: 2.1.0
 * Author: Tuo Nome
 * Text Domain: tecbp
 * Domain Path: /languages
 */

// Uscita di sicurezza se richiamato direttamente
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. CARICAMENTO TRADUZIONI (Text Domain)
 */
add_action( 'plugins_loaded', 'tecbp_load_textdomain' );
function tecbp_load_textdomain() {
    load_plugin_textdomain( 'tecbp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * 2. REGISTRAZIONE DELL'ESTENSIONE GRUPPO BUDDYPRESS (Lista e Bottoni "Ponte")
 */
add_action( 'bp_init', 'tecbp_register_group_extension' );
function tecbp_register_group_extension() {
    if ( ! class_exists( 'BP_Group_Extension' ) ) return;

    class TECBP_Group_Events extends BP_Group_Extension {

        public function __construct() {
            $this->name = __( 'Eventi', 'tecbp' );
            $this->slug = 'events';
            $this->create_step_position = 21;
            $this->nav_item_position = 31;
            $this->enable_nav_item = true;
        }

        public function display( $group_id = NULL ) {
            $group_id = bp_get_current_group_id();
            
            // Determina gli URL per la creazione degli eventi (Scenario A vs B)
            $add_url = admin_url( 'post-new.php?post_type=tribe_events&bpgroup=' . $group_id ); // Scenario A: Default Backend
            if ( class_exists( 'Tribe__Events__Community__Main' ) ) {
                // Scenario B: Community Events (Frontend)
                $add_url = add_query_arg( 'bpgroup', $group_id, tribe_get_community_add_event_link() );
            }

            // Mostra il pulsante "Nuovo Evento" se l'utente può creare (membro o admin)
            if ( groups_is_user_member( bp_loggedin_user_id(), $group_id ) || groups_is_user_admin( bp_loggedin_user_id(), $group_id ) ) {
                echo '<div style="margin-bottom: 25px;">';
                echo '<a href="' . esc_url( $add_url ) . '" class="button">+ ' . __( 'Crea Nuovo Evento', 'tecbp' ) . '</a>';
                echo '</div>';
            }

            // Loop degli eventi del gruppo
            $events = tribe_get_events( array(
                'meta_query' => array(
                    array(
                        'key'     => '_associated_group_id',
                        'value'   => $group_id,
                        'compare' => '='
                    )
                )
            ) );

            if ( $events ) {
                foreach ( $events as $event ) {
                    echo '<div class="tecbp-event-item" style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;">';
                    echo '<h3 style="margin-bottom: 5px;"><a href="' . esc_url( get_permalink( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a></h3>';
                    echo '<p style="margin-top: 0;"><strong>' . __( 'Inizio:', 'tecbp' ) . '</strong> ' . esc_html( tribe_get_start_date( $event, true, 'Y-m-d H:i' ) ) . '</p>';
                    
                    // Mostra link Modifica se l'utente è autore o admin del gruppo
                    if ( groups_is_user_admin( bp_loggedin_user_id(), $group_id ) || $event->post_author == bp_loggedin_user_id() ) {
                        $edit_url = get_edit_post_link( $event->ID ); // Scenario A
                        if ( class_exists( 'Tribe__Events__Community__Main' ) ) {
                            // Costruisce l'URL di edit per Community Events
                            $edit_url = str_replace( '/add', '/edit/' . $event->ID, tribe_get_community_add_event_link() );
                        }
                        echo '<a href="' . esc_url( $edit_url ) . '" class="button">✏️ ' . __( 'Modifica Evento', 'tecbp' ) . '</a>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<p>' . __( 'Nessun evento programmato per questo gruppo.', 'tecbp' ) . '</p>';
            }
        }
    }
    bp_register_group_extension( 'TECBP_Group_Events' );
}

/**
 * 3. SCENARIO A: INTEGRAZIONE BACKEND (WP-ADMIN)
 */
add_action( 'add_meta_boxes', 'tecbp_add_backend_meta_box' );
function tecbp_add_backend_meta_box() {
    add_meta_box( 'tecbp_group_select', __( 'Gruppo BuddyPress Associato', 'tecbp' ), 'tecbp_render_meta_box', 'tribe_events', 'side', 'default' );
}

function tecbp_render_meta_box( $post ) {
    wp_nonce_field( 'tecbp_backend_save_group', 'tecbp_backend_nonce' );
    
    $current_group = get_post_meta( $post->ID, '_associated_group_id', true );
    
    // Auto-seleziona il gruppo se arriviamo dal pulsante BuddyPress (Nuovo evento)
    if ( empty( $current_group ) && isset( $_GET['bpgroup'] ) ) {
        $current_group = intval( $_GET['bpgroup'] );
    }
    
    $groups = groups_get_groups( array( 'per_page' => -1 ) );
    
    echo '<p><label for="tecbp_group_id">' . __( 'Seleziona un gruppo:', 'tecbp' ) . '</label></p>';
    echo '<select name="tecbp_group_id" id="tecbp_group_id" style="width:100%;">';
    echo '<option value="">' . __( '-- Nessun Gruppo --', 'tecbp' ) . '</option>';
    foreach ( $groups['groups'] as $group ) {
        echo '<option value="' . esc_attr( $group->id ) . '" ' . selected( $current_group, $group->id, false ) . '>' . esc_html( $group->name ) . '</option>';
    }
    echo '</select>';
}

add_action( 'save_post_tribe_events', 'tecbp_save_backend_meta_box' );
function tecbp_save_backend_meta_box( $post_id ) {
    if ( ! isset( $_POST['tecbp_backend_nonce'] ) || ! wp_verify_nonce( $_POST['tecbp_backend_nonce'], 'tecbp_backend_save_group' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( isset( $_POST['tecbp_group_id'] ) ) {
        update_post_meta( $post_id, '_associated_group_id', sanitize_text_field( $_POST['tecbp_group_id'] ) );
    }
}

/**
 * 4. SCENARIO B: INTEGRAZIONE FRONTEND (COMMUNITY EVENTS)
 */
add_action( 'tribe_events_community_section_after_custom_fields', 'tecbp_ce_add_group_dropdown' );
function tecbp_ce_add_group_dropdown() {
    // Funziona solo se BuddyPress è attivo
    if ( ! function_exists( 'groups_get_user_groups' ) ) return;

    // Recupera l'evento corrente (se in modifica) o l'ID in query (se nuovo)
    $event_id = get_the_ID();
    $current_group = $event_id ? get_post_meta( $event_id, '_associated_group_id', true ) : 0;
    
    if ( empty( $current_group ) && isset( $_GET['bpgroup'] ) ) {
        $current_group = intval( $_GET['bpgroup'] );
    }

    // Mostra solo i gruppi di cui l'utente loggato fa parte
    $user_id = get_current_user_id();
    $user_groups = groups_get_user_groups( $user_id );
    
    if ( empty( $user_groups['groups'] ) ) return;

    echo '<div class="tribe-section tribe-section-buddypress" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #e0e0e0;">';
    echo '<div class="tribe-section-header"><h3>' . __( 'Gruppo BuddyPress', 'tecbp' ) . '</h3></div>';
    echo '<table class="tribe-community-event-info" cellspacing="0" cellpadding="0">';
    echo '<tr>';
    echo '<td class="tribe_sectionheader"><label for="tecbp_ce_group_id">' . __( 'Associa al gruppo:', 'tecbp' ) . '</label></td>';
    echo '<td>';
    echo '<select name="tecbp_ce_group_id" id="tecbp_ce_group_id">';
    echo '<option value="">' . __( '-- Evento Globale (Nessun Gruppo) --', 'tecbp' ) . '</option>';
    
    foreach ( $user_groups['groups'] as $group_id ) {
        $group = groups_get_group( array( 'group_id' => $group_id ) );
        echo '<option value="' . esc_attr( $group->id ) . '" ' . selected( $current_group, $group->id, false ) . '>' . esc_html( $group->name ) . '</option>';
    }
    
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';
}

add_action( 'tribe_community_event_created', 'tecbp_ce_save_group' );
add_action( 'tribe_community_event_updated', 'tecbp_ce_save_group' );
function tecbp_ce_save_group( $event_id ) {
    if ( isset( $_POST['tecbp_ce_group_id'] ) ) {
        $group_id = intval( $_POST['tecbp_ce_group_id'] );
        if ( $group_id > 0 ) {
            update_post_meta( $event_id, '_associated_group_id', $group_id );
        } else {
            delete_post_meta( $event_id, '_associated_group_id' );
        }
    }
}

/**
 * 5. INSERIMENTO DEL GRUPPO NEL BLOCCO META NATIVO DI T.E.C.
 * Aggiunge la riga "Gruppo: [Nome Gruppo]" nel blocco Dettagli, 
 * garantendo che sia sempre visibile anche senza un Organizzatore.
 */
add_action( 'tribe_events_single_meta_details_section_end', 'tecbp_add_group_to_details_meta' );
function tecbp_add_group_to_details_meta() {
    $group_id = get_post_meta( get_the_ID(), '_associated_group_id', true );

    if ( ! empty( $group_id ) && function_exists( 'groups_get_group' ) ) {
        $group = groups_get_group( array( 'group_id' => $group_id ) );

        if ( $group ) {
            $group_link = bp_get_group_permalink( $group );
            $group_name = esc_html( $group->name );

            // Usa la struttura standard <dt> (titolo) e <dd> (valore) di The Events Calendar
            echo '<dt class="tribe-meta-label tecbp-group-label">' . esc_html__( 'Gruppo:', 'tecbp' ) . '</dt>';
            echo '<dd class="tribe-meta-value tecbp-group-value"><a href="' . esc_url( $group_link ) . '">' . $group_name . '</a></dd>';
        }
    }
}
